<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractTransitionedToPendingEvent;

/**
 * Handles contract creation and initiates condition resolution.
 *
 * When a contract is created, this handler transitions it to PENDING state
 * and dispatches an event to trigger condition fulfillment by other handlers.
 *
 * @since 1.0.0
 */
class ContractConditionResolverHandler extends AbstractHandler
{
    public static function getHandledEventClass(): string
    {
        return ContractCreatedEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractCreatedEvent) {
            return;
        }
        $contract = $event->getContract();

        $contract->transitionToPending();

        $this->contractRepository->save($contract);

        $pendingEvent = new ContractTransitionedToPendingEvent(
            $contract,
            $event->getContext(),
            $contract->getConditions()
        );

        $this->eventDispatcher->dispatch($pendingEvent);
    }
}
