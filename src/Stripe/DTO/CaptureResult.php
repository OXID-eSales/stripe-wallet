<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\DTO;

/**
 * Result of a capture operation.
 *
 * Sprint 9: Immutable result object for CaptureService.
 * Uses factory methods for success/failure - no exceptions.
 *
 * @since 2.0.0
 */
final readonly class CaptureResult
{
    private function __construct(
        private bool $successful,
        private ?string $captureId,
        private ?float $amountCaptured,
        private ?string $currency,
        private ?\DateTimeImmutable $capturedAt,
        private ?string $errorMessage,
        private ?string $errorCode
    ) {
    }

    public static function success(
        string $captureId,
        float $amountCaptured,
        string $currency,
        \DateTimeImmutable $capturedAt
    ): self {
        return new self(
            successful: true,
            captureId: $captureId,
            amountCaptured: $amountCaptured,
            currency: $currency,
            capturedAt: $capturedAt,
            errorMessage: null,
            errorCode: null
        );
    }

    public static function failure(string $message, ?string $code = null): self
    {
        return new self(
            successful: false,
            captureId: null,
            amountCaptured: null,
            currency: null,
            capturedAt: null,
            errorMessage: $message,
            errorCode: $code
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getCaptureId(): ?string
    {
        return $this->captureId;
    }

    public function getAmountCaptured(): ?float
    {
        return $this->amountCaptured;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getCapturedAt(): ?\DateTimeImmutable
    {
        return $this->capturedAt;
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
