<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface;

class ContractCreationHandler implements HandlerInterface
{
    public function __construct(
        private ContractServiceInterface $contractService,
        private EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function handle(object $event): void
    {
        if (!$event instanceof PaymentInitiatedEvent) {
            return;
        }

        $context = $event->getContext();

        $userId = $context->get('userId');
        if (!$userId) {
            throw new \InvalidArgumentException('User ID is required');
        }

        $basket = $context->get('basket');
        if (!$basket) {
            throw new \InvalidArgumentException('Basket is required');
        }

        $conditionTypes = $context->get('conditionTypes') ?? [];

        $contract = $this->contractService->createContract(
            $userId,
            $basket,
            $conditionTypes
        );

        $context->set('contract', $contract);

        $contractCreatedEvent = new ContractCreatedEvent($contract, $context);
        $this->eventDispatcher->dispatch($contractCreatedEvent);
    }
}
