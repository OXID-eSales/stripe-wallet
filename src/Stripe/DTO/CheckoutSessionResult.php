<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\DTO;

/**
 * Result object for checkout session creation.
 *
 * Sprint 21: Extract business logic from StripeCheckoutSessionHandler.
 *
 * Immutable DTO following the Result Object pattern:
 * - Named constructors for success/failure states
 * - Type-safe access to result data
 * - Self-documenting API
 *
 * @since 2.0.0
 */
final readonly class CheckoutSessionResult
{
    private function __construct(
        private bool $successful,
        private ?string $sessionId,
        private ?string $checkoutUrl,
        private ?string $errorMessage,
        private ?string $errorCode
    ) {
    }

    /**
     * Create a successful checkout session result.
     */
    public static function success(string $sessionId, ?string $checkoutUrl = null): self
    {
        return new self(
            successful: true,
            sessionId: $sessionId,
            checkoutUrl: $checkoutUrl,
            errorMessage: null,
            errorCode: null
        );
    }

    /**
     * Create a failed checkout session result.
     */
    public static function failure(string $errorMessage, ?string $errorCode = null): self
    {
        return new self(
            successful: false,
            sessionId: null,
            checkoutUrl: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
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
