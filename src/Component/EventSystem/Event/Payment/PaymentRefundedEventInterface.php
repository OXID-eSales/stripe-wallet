<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

interface PaymentRefundedEventInterface extends PaymentEventInterface
{
    public function getRefundId(): string;

    public function getProviderOrderId(): string;

    public function getAmount(): float;

    public function getCurrency(): string;

    public function getOrderId(): string;
}
