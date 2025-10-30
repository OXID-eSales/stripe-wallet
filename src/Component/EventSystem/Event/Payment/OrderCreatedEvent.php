<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;

readonly class OrderCreatedEvent implements OrderCreatedEventInterface
{
    public function __construct(
        private EventContext $context,
        private string $orderId,
        private string $contractId
    ) {
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getContractId(): string
    {
        return $this->contractId;
    }
}
