<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

interface OrderCreatedEventInterface extends PaymentEventInterface
{
    public function getOrderId(): string;

    public function getContractId(): string;
}
