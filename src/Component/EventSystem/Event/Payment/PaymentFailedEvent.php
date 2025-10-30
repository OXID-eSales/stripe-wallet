<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;

readonly class PaymentFailedEvent implements PaymentFailedEventInterface
{
    public function __construct(
        private EventContext $context,
        private string $providerOrderId,
        private string $errorCode,
        private string $errorMessage
    ) {
    }

    public function getContext(): EventContext
    {
        return $this->context;
    }

    public function getProviderOrderId(): string
    {
        return $this->providerOrderId;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}
