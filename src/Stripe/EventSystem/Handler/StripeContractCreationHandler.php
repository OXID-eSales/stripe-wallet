<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessGuard;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\EventSystem\Handler\ContractCreationHandler;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\PaymentBase\Service\ContractMetadataServiceInterface;

/**
 * Creates payment contract for Stripe Checkout Session flow.
 *
 * Sprint 1: Refactored to extend ContractCreationHandler using Template Method pattern.
 * Common validation and contract creation logic is now in the base class.
 *
 * This handler runs BEFORE StripeCheckoutSessionHandler (via priority)
 * to ensure the contract exists when the session is created.
 *
 * Priority: 100 (higher than StripeCheckoutSessionHandler default 0)
 *
 * @since 1.0.0
 */
class StripeContractCreationHandler extends ContractCreationHandler
{
    public function __construct(
        ContractServiceInterface $contractService,
        EventDispatcherInterface $eventDispatcher,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractMetadataServiceInterface $metadataService,
        private readonly FileLoggerInterface $eventLogger
    ) {
        parent::__construct($contractService, $eventDispatcher);
    }

    /**
     * @inheritDoc
     */
    public static function getHandledEventClass(): string
    {
        return StripeCheckoutSessionRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 100;
    }

    /**
     * Stripe-specific post-creation logic.
     *
     * - Stores delivery address and security metadata
     * - Saves contract to repository
     * - Sets contractId on context for downstream handlers
     *
     * @inheritDoc
     */
    protected function afterContractCreated(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        $this->logEvent('StripeContractCreationHandler: Contract created', [
            'contractId' => $contract->getId(),
        ]);

        // Sprint 21: Delegate metadata operations to service
        $basket = $context->get('basket');
        if (is_object($basket)) {
            $this->metadataService->storeDeliveryAddressMetadata($contract, $basket);
        }
        $this->metadataService->storeSecurityMetadata($contract, $context);
        $this->recordInitiatingSession($contract, $context);

        // Save contract to persist the metadata
        $this->contractRepository->save($contract);

        // Set contractId on context for downstream handlers
        $context->set('contractId', $contract->getId());
    }

    /**
     * VR-20 R5: the shop session the payment is started in. payment-base's ReturnAccessGuard lets the contract's
     * shopper back in through it, without a return token.
     */
    private function recordInitiatingSession(PaymentContractInterface $contract, EventContextInterface $context): void
    {
        $sessionId = $context->get('sessionId');
        if (is_string($sessionId) && $sessionId !== '') {
            $contract->setMetadata(ReturnAccessGuard::METADATA_INITIATING_SESSION, $sessionId);
        }
    }

    /**
     * Dispatch ContractDraftCompletedEvent to trigger EarlyOrderCreationHandler.
     *
     * STRP-74: This creates the order early and transitions DRAFT → NOT_FINISHED → PENDING
     *
     * @inheritDoc
     */
    protected function dispatchContractEvent(
        PaymentContractInterface $contract,
        EventContextInterface $context
    ): void {
        $this->logEvent('StripeContractCreationHandler: Dispatching ContractDraftCompletedEvent');
        $draftCompletedEvent = new ContractDraftCompletedEvent($contract, $context);
        $this->eventDispatcher->dispatch($draftCompletedEvent);

        $this->logEvent('StripeContractCreationHandler: Event dispatched', [
            'contractId' => $contract->getId(),
            'state' => $contract->getStateValue(),
        ]);
    }

    /**
     * Log event to file logger for debugging.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function logEvent(string $message, array $context = []): void
    {
        $this->eventLogger->log($message, $context);
    }
}
