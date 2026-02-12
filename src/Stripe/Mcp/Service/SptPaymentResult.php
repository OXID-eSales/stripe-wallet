<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

readonly class SptPaymentResult
{
    private function __construct(
        private bool $successful,
        private ?string $paymentIntentId,
        private ?string $status,
        private ?string $errorMessage
    ) {
    }

    public static function success(string $paymentIntentId, string $status): self
    {
        return new self(true, $paymentIntentId, $status, null);
    }

    public static function failed(string $errorMessage, ?string $paymentIntentId = null): self
    {
        return new self(false, $paymentIntentId, null, $errorMessage);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getPaymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
