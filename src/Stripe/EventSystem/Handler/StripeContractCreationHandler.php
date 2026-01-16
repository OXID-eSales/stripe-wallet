<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use InvalidArgumentException;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Service\ContractMetadataServiceInterface;

/**
 * Creates payment contract for Stripe Checkout Session flow.
 *
 * Sprint 21: Refactored to delegate metadata operations to ContractMetadataService.
 *
 * This handler runs BEFORE StripeCheckoutSessionHandler (via priority)
 * to ensure the contract exists when the session is created.
 *
 * Priority: 100 (higher than StripeCheckoutSessionHandler default 0)
 */
class StripeContractCreationHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContractServiceInterface $contractService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractMetadataServiceInterface $metadataService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutSessionRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function handle(object $event): void
    {
        $this->logEvent('StripeContractCreationHandler::handle() START');

        if (!$event instanceof StripeCheckoutSessionRequestEvent) {
            $this->logEvent('StripeContractCreationHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();

        // Skip if contract already exists
        if ($context->getContract() !== null) {
            $this->logEvent('StripeContractCreationHandler: Contract already exists, skipping');
            return;
        }

        $userId = $context->get('userId');
        if (!is_string($userId) || $userId === '') {
            $this->logEvent('StripeContractCreationHandler: ERROR - User ID is required');
            throw new InvalidArgumentException('User ID is required');
        }

        $basket = $context->get('basket');
        if (!is_object($basket)) {
            $this->logEvent('StripeContractCreationHandler: ERROR - Basket is required');
            throw new InvalidArgumentException('Basket is required');
        }

        $conditionTypes = $context->get('conditionTypes', []);
        if (!is_array($conditionTypes)) {
            $conditionTypes = [];
        }

        /** @var array<int, string> $validatedConditionTypes */
        $validatedConditionTypes = array_values(array_filter($conditionTypes, 'is_string'));

        $this->logEvent('StripeContractCreationHandler: Creating contract', [
            'userId' => $userId,
            'conditionTypes' => $validatedConditionTypes,
        ]);

        $contract = $this->contractService->createContract(
            $userId,
            $basket,
            $validatedConditionTypes
        );

        $this->logEvent('StripeContractCreationHandler: Contract created', [
            'contractId' => $contract->getId(),
        ]);

        // Sprint 21: Delegate metadata operations to service
        $this->metadataService->storeDeliveryAddressMetadata($contract, $basket);
        $this->metadataService->storeSecurityMetadata($contract, $context);

        // Save contract to persist the metadata
        $this->contractRepository->save($contract);

        $context->setContract($contract);
        $context->set('contractId', $contract->getId());

        // STRP-74: Dispatch ContractDraftCompletedEvent to trigger EarlyOrderCreationHandler
        // This creates the order early and transitions DRAFT → NOT_FINISHED → PENDING
        $this->logEvent('StripeContractCreationHandler: Dispatching ContractDraftCompletedEvent');
        $draftCompletedEvent = new ContractDraftCompletedEvent($contract, $context);
        $this->eventDispatcher->dispatch($draftCompletedEvent);

        $this->logEvent('StripeContractCreationHandler::handle() END', [
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
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
