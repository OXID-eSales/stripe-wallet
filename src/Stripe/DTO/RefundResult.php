<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\DTO;

/**
 * Result object for refund operations.
 *
 * Sprint 21: Extract business logic from StripeRefundRequestHandler.
 *
 * Immutable DTO following the Result Object pattern:
 * - Named constructors for success/failure states
 * - Type-safe access to result data
 * - Self-documenting API
 *
 * @since 2.0.0
 */
final readonly class RefundResult
{
    private function __construct(
        private bool $successful,
        private ?string $refundId,
        private ?int $refundedAmountCents,
        private ?string $currency,
        private ?string $status,
        private ?string $errorMessage,
        private ?string $errorCode
    ) {
    }

    /**
     * Create a successful refund result.
     */
    public static function success(
        string $refundId,
        int $amountCents,
        string $currency,
        string $status = 'succeeded'
    ): self {
        return new self(
            successful: true,
            refundId: $refundId,
            refundedAmountCents: $amountCents,
            currency: $currency,
            status: $status,
            errorMessage: null,
            errorCode: null
        );
    }

    /**
     * Create a failed refund result.
     */
    public static function failure(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            successful: false,
            refundId: null,
            refundedAmountCents: null,
            currency: null,
            status: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getRefundId(): ?string
    {
        return $this->refundId;
    }

    /**
     * Get the refunded amount in cents.
     */
    public function getRefundedAmountCents(): ?int
    {
        return $this->refundedAmountCents;
    }

    /**
     * Get the refunded amount in decimal (e.g., 25.50 for 2550 cents).
     */
    public function getRefundedAmount(): ?float
    {
        if ($this->refundedAmountCents === null) {
            return null;
        }
        return $this->refundedAmountCents / 100;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
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
