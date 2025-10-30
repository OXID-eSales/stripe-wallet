<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;

readonly class PaymentCapturedEvent implements PaymentCapturedEventInterface
{
    public function __construct(
        private EventContext $context,
        private string $authorizationId,
        private string $captureId,
        private float $capturedAmount,
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

    public function getCaptureId(): string
    {
        return $this->captureId;
    }

    public function getCapturedAmount(): float
    {
        return $this->capturedAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }
}
