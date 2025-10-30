<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;

/**
 * Handles payment authorization events.
 *
 * When a contract transitions to PENDING state (payment authorized),
 * this handler fulfills the PAYMENT_AUTHORIZED condition and checks
 * if all conditions are met to transition to READY_TO_COMMIT.
 *
 * @since 1.0.0
 */
class PaymentAuthorizationHandler extends AbstractHandler
{
    public function handle(object $event): void
    {
        if (!$event instanceof ContractTransitionedToPendingEvent) {
            return;
        }
        $contract = $event->getContract();
        $context = $event->getContext();

        $authorizationId = $context->get('authorizationId');
        $providerOrderId = $context->get('providerOrderId');

        $contract->fulfillCondition(
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            [
                'authorizationId' => $authorizationId,
                'providerOrderId' => $providerOrderId,
            ]
        );

        if ($providerOrderId) {
            $contract->setProvider('stripe', $providerOrderId);
        }

        $this->contractRepository->save($contract);

        if ($contract->areAllConditionsFulfilled()) {
            $readyEvent = new ContractReadyToCommitEvent(
                $contract,
                $context,
                []
            );

            $this->eventDispatcher->dispatch($readyEvent);
        }
    }
}
