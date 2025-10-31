<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\OrderRepositoryInterface;

/**
 * Handles contract fulfillment when payment confirmation webhook is received.
 *
 * Transitions contract to FULFILLED state and marks associated order as completed.
 *
 * @since 1.0.0
 */
class ContractFulfillmentHandler extends AbstractHandler
{
    private const FULFILLMENT_EVENT_TYPES = [
        'payment_intent.succeeded',
        'charge.succeeded',
    ];

    public function __construct(
        ContractRepositoryInterface $contractRepository,
        private OrderRepositoryInterface $orderRepository,
        ?EventDispatcherInterface $eventDispatcher = null
    ) {
        parent::__construct($contractRepository, $eventDispatcher);
    }

    public function handle(object $event): void
    {
        if (!$event instanceof WebhookReceivedEvent) {
            return;
        }
        if (!$this->isFulfillmentEvent($event)) {
            return;
        }

        $contractId = $event->getContext()->get('contractId');
        if (!$contractId) {
            return;
        }

        $contract = $this->contractRepository->findById($contractId);
        if (!$contract) {
            return;
        }

        if (!$contract->getState()->isCommitted()) {
            throw new \DomainException('Contract must be COMMITTED before fulfillment');
        }

        $contract->fulfill();
        $this->contractRepository->save($contract);

        $orderId = $contract->getOrderId();
        if ($orderId) {
            $order = $this->orderRepository->findById((int) $orderId);
            if ($order) {
                $order->setStatus('completed');
                $this->orderRepository->save($order);
            }
        }

        $fulfilledEvent = new ContractFulfilledEvent(
            $contract,
            $event->getContext(),
            $orderId ?? ''
        );

        $this->eventDispatcher->dispatch($fulfilledEvent);
    }

    private function isFulfillmentEvent(WebhookReceivedEvent $event): bool
    {
        return in_array($event->getEventType(), self::FULFILLMENT_EVENT_TYPES, true);
    }
}
