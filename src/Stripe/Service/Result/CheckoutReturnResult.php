<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Result;

use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\AmountConverter;

/**
 * Result object for checkout return validation.
 *
 * Sprint 21: Extract business logic from StripeCheckoutReturnHandler.
 * Sprint 25: Moved from DTO/ to Service/Result/ for consistent organization.
 *
 * Immutable DTO following the Result Object pattern:
 * - Named constructors for success/failure states
 * - Type-safe access to result data
 * - Self-documenting API
 *
 * @since 2.0.0
 */
final readonly class CheckoutReturnResult
{
    private function __construct(
        private bool $successful,
        private ?string $contractId,
        private ?string $paymentIntentId,
        private ?int $amountCents,
        private ?string $currency,
        private ?string $paymentStatus,
        private ?string $paymentIntentStatus,
        private ?string $errorMessage,
        private ?string $errorCode,
        private ?string $redirectTarget
    ) {
    }

    /**
     * Create a successful checkout return result.
     *
     * @param string $paymentIntentStatus PaymentIntent status (succeeded, requires_capture, etc.)
     */
    public static function success(
        string $contractId,
        string $paymentIntentId,
        int $amountCents,
        string $currency,
        string $paymentStatus = StripeStatusMapper::CHECKOUT_PAYMENT_STATUS_PAID,
        string $paymentIntentStatus = StripeStatusMapper::STRIPE_SUCCEEDED
    ): self {
        return new self(
            successful: true,
            contractId: $contractId,
            paymentIntentId: $paymentIntentId,
            amountCents: $amountCents,
            currency: $currency,
            paymentStatus: $paymentStatus,
            paymentIntentStatus: $paymentIntentStatus,
            errorMessage: null,
            errorCode: null,
            redirectTarget: 'thankyou'
        );
    }

    /**
     * Create a failed checkout return result.
     */
    public static function failure(
        string $errorMessage,
        ?string $errorCode = null,
        string $redirectTarget = 'payment'
    ): self {
        return new self(
            successful: false,
            contractId: null,
            paymentIntentId: null,
            amountCents: null,
            currency: null,
            paymentStatus: null,
            paymentIntentStatus: null,
            errorMessage: $errorMessage,
            errorCode: $errorCode,
            redirectTarget: $redirectTarget
        );
    }

    /**
     * Create a security failure result.
     */
    public static function securityFailure(string $errorMessage): self
    {
        return new self(
            successful: false,
            contractId: null,
            paymentIntentId: null,
            amountCents: null,
            currency: null,
            paymentStatus: null,
            paymentIntentStatus: null,
            errorMessage: $errorMessage,
            errorCode: 'security_check_failed',
            redirectTarget: 'payment'
        );
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function getPaymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    /**
     * Get the amount in cents.
     */
    public function getAmountCents(): ?int
    {
        return $this->amountCents;
    }

    /**
     * Get the amount in decimal (e.g., 25.50 for 2550 EUR cents; 1000.0 for 1000 JPY).
     *
     * Sprint 114.7: uses AmountConverter so zero-decimal currencies are handled correctly.
     */
    public function getAmount(): ?float
    {
        if ($this->amountCents === null) {
            return null;
        }
        return AmountConverter::toMajorUnits($this->amountCents, $this->currency ?? '');
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    /**
     * Get the Stripe PaymentIntent status.
     *
     * @return string|null Status like 'succeeded', 'requires_capture', 'requires_action', etc.
     */
    public function getPaymentIntentStatus(): ?string
    {
        return $this->paymentIntentStatus;
    }

    /**
     * Check if the PaymentIntent requires manual capture.
     *
     * This indicates the payment is authorized but not yet captured
     * (manual capture mode is enabled).
     */
    public function isRequiresCapture(): bool
    {
        return $this->paymentIntentStatus === StripeStatusMapper::STRIPE_REQUIRES_CAPTURE;
    }

    /**
     * Check if the PaymentIntent is fully captured.
     */
    public function isCaptured(): bool
    {
        return $this->paymentIntentStatus === StripeStatusMapper::STRIPE_SUCCEEDED;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getRedirectTarget(): ?string
    {
        return $this->redirectTarget;
    }
}
