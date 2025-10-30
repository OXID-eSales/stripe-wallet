<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;
use OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler\Support\InMemoryOrderRepository;
use OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Handler\Support\Order;

/**
 * Handles order creation when contract becomes ready to commit.
 *
 * Creates an order from the contract's basket snapshot and transitions
 * the contract to COMMITTED state.
 *
 * @todo Refactor to use proper OrderRepository interface from production namespace
 *       instead of test support classes (TICKET-10)
 *
 * @since 1.0.0
 */
class OrderCreationHandler extends AbstractHandler
{
    public function __construct(
        ContractRepository $contractRepository,
        private InMemoryOrderRepository $orderRepository,
        ?EventDispatcher $eventDispatcher = null
    ) {
        parent::__construct($contractRepository, $eventDispatcher);
    }

    public function handle(object $event): void
    {
        if (!$event instanceof ContractReadyToCommitEvent) {
            return;
        }
        $contract = $event->getContract();

        if (!$contract->getState()->isReadyToCommit()) {
            throw new \DomainException('Cannot create order: contract is not ready to commit');
        }

        if (!$contract->areAllConditionsFulfilled()) {
            throw new \DomainException('Cannot create order: not all conditions fulfilled');
        }

        $basket = $contract->getBasketSnapshot();
        $orderId = $this->orderRepository->generateNextId();
        $orderNumber = $this->orderRepository->generateNextOrderNumber();

        $order = new Order(
            $orderId,
            $orderNumber,
            $contract->getUserId(),
            $basket->getTotalGross(),
            $basket->getTotalNet(),
            $basket->getTotalVat(),
            $basket->getCurrency(),
            $basket->getItems(),
            $contract->getId()
        );

        $this->orderRepository->save($order);

        $contract->commitToOrder((string) $orderId);
        $this->contractRepository->save($contract);

        $committedEvent = new ContractCommittedEvent(
            $contract,
            $event->getContext(),
            (string) $orderId
        );

        $this->eventDispatcher->dispatch($committedEvent);
    }
}
