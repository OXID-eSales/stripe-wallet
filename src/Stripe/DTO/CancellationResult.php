<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\DTO;

/**
 * Result of a cancel authorization operation.
 *
 * Sprint 11: Extract from handler to service layer.
 *
 * @since 2.0.0
 */
final readonly class CancellationResult
{
    private function __construct(
        private bool $successful,
        private ?string $paymentIntentId,
        private ?string $status,
        private ?string $errorMessage,
        private ?string $errorCode
    ) {
    }

    public static function success(string $paymentIntentId, string $status): self
    {
        return new self(
            successful: true,
            paymentIntentId: $paymentIntentId,
            status: $status,
            errorMessage: null,
            errorCode: null
        );
    }

    public static function failure(string $message, ?string $code = null): self
    {
        return new self(
            successful: false,
            paymentIntentId: null,
            status: null,
            errorMessage: $message,
            errorCode: $code
        );
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

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
