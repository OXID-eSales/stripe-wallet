<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Result;

/**
 * Result object for checkout session creation.
 *
 * Sprint 21: Extract business logic from StripeCheckoutSessionHandler.
 * Sprint 25: Moved from DTO/ to Service/Result/ for consistent organization.
 *
 * Immutable DTO following the Result Object pattern:
 * - Named constructors for success/failure states
 * - Type-safe access to result data
 * - Self-documenting API
 *
 * @since 2.0.0
 */
readonly class CheckoutSessionResult
{
    private function __construct(
        private bool $successful,
        private ?string $sessionId,
        private ?string $checkoutUrl,
        private ?string $errorMessage,
        private ?string $errorCode,
        private ?string $clientSecret = null
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
     * Create a successful embedded-checkout result (Stripe ui_mode=embedded).
     *
     * Carries the client secret the frontend mounts inline; no hosted URL.
     */
    public static function embedded(string $sessionId, string $clientSecret): self
    {
        return new self(
            successful: true,
            sessionId: $sessionId,
            checkoutUrl: null,
            errorMessage: null,
            errorCode: null,
            clientSecret: $clientSecret
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

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function isEmbedded(): bool
    {
        return $this->clientSecret !== null;
    }
}
