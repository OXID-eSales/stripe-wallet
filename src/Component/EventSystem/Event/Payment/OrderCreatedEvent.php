<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContextInterface;

readonly class OrderCreatedEvent implements OrderCreatedEventInterface
{
    public function __construct(
        private EventContextInterface $context,
        private string $orderId,
        private string $contractId
    ) {
    }

    public function getContext(): EventContextInterface
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
