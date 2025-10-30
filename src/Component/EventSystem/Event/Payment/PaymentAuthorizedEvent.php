<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;

readonly class PaymentAuthorizedEvent implements PaymentAuthorizedEventInterface
{
    public function __construct(
        private EventContext $context,
        private string $authorizationId,
        private string $providerOrderId,
        private float $amount,
        private string $currency
    ) {
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getAuthorizationId(): string
    {
        return $this->authorizationId;
    }

    public function getProviderOrderId(): string
    {
        return $this->providerOrderId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
