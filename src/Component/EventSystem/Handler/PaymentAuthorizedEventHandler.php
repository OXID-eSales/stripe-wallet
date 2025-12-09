<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;

/**
 * Handles PaymentAuthorizedEvent from payment providers.
 *
 * This handler:
 * 1. Transitions contract from DRAFT to PENDING (if in DRAFT state)
 * 2. Fulfills the PAYMENT_AUTHORIZED condition
 * 3. If all conditions met → dispatches ContractReadyToCommitEvent
 *
 * This is the bridge between provider-specific payment confirmation
 * (PaymentAuthorizedEvent) and the contract state machine.
 *
 * Sprint 22: EventDispatcher now injected via constructor (no ContainerFactory).
 *
 * @since 1.0.0
 */
class PaymentAuthorizedEventHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return PaymentAuthorizedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentAuthorizedEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->getContract();

        if ($contract === null) {
            // No contract in context - nothing to do
            return;
        }

        // Store authorization data in context for downstream handlers
        $context->set('authorizationId', $event->getAuthorizationId());
        $context->set('providerOrderId', $event->getProviderOrderId());
        $context->set('amount', $event->getAmount());
        $context->set('currency', $event->getCurrency());

        // Transition from DRAFT to PENDING if needed
        if ($contract->getState()->isDraft()) {
            $contract->transitionToPending();
        }

        // Fulfill the payment_authorized condition
        if ($contract->getState()->isPending()) {
            $contract->fulfillCondition(
                ContractCondition::TYPE_PAYMENT_AUTHORIZED,
                [
                    'authorizationId' => $event->getAuthorizationId(),
                    'providerOrderId' => $event->getProviderOrderId(),
                    'amount' => $event->getAmount(),
                    'currency' => $event->getCurrency(),
                ]
            );

            // Set provider info
            $providerName = $context->get('providerName') ?? 'stripe';
            $contract->setProvider($providerName, $event->getProviderOrderId());
        }

        // Save contract state
        $this->contractRepository->save($contract);

        // If contract is now ready to commit, dispatch event
        if ($contract->getState()->isReadyToCommit()) {
            $readyEvent = new ContractReadyToCommitEvent(
                $contract,
                $context,
                []
            );

            $this->eventDispatcher->dispatch($readyEvent);
        }
    }
}
