<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

interface PaymentAuthorizedEventInterface extends PaymentEventInterface
{
    public function getAuthorizationId(): string;

    public function getProviderOrderId(): string;

    public function getAmount(): float;

    public function getCurrency(): string;
}
