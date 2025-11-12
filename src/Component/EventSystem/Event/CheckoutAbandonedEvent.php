<?php

declare(strict_types=1);

namespace OxidEsales\StripeWallet\Component\EventSystem\Event;

/**
 * Event dispatched when customer abandons the checkout process
 *
 * This event is fired when:
 * - Customer closes the browser/tab during checkout
 * - Checkout session times out
 * - Customer navigates away from checkout page
 * - Payment fails multiple times and customer gives up
 * - Customer explicitly cancels the checkout
 *
 * Subscribers can:
 * - Track cart abandonment metrics
 * - Send reminder/recovery emails
 * - Release reserved inventory
 * - Log abandonment reasons for analytics
 * - Trigger remarketing campaigns
 */
class CheckoutAbandonedEvent
{
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_NAVIGATION = 'navigation';
    public const REASON_PAYMENT_FAILED = 'payment_failed';
    public const REASON_USER_CANCELLED = 'user_cancelled';
    public const REASON_SESSION_EXPIRED = 'session_expired';
    public const REASON_UNKNOWN = 'unknown';

    public function __construct(
        private readonly string $sessionId,
        private readonly string $customerId,
        private readonly string $reason,
        private readonly array $checkoutState,
        private readonly ?string $contractId = null,
        private readonly ?float $cartTotal = null,
        private readonly ?string $currency = null,
        private readonly ?array $metadata = null
    ) {
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getCheckoutState(): array
    {
        return $this->checkoutState;
    }

    public function getContractId(): ?string
    {
        return $this->contractId;
    }

    public function getCartTotal(): ?float
    {
        return $this->cartTotal;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * Get which stage of checkout was abandoned
     */
    public function getAbandonedStage(): string
    {
        return $this->checkoutState['currentStage'] ?? 'unknown';
    }

    /**
     * Check if address was completed before abandonment
     */
    public function hasCompletedAddress(): bool
    {
        return !empty($this->checkoutState['addressCompleted']);
    }

    /**
     * Check if payment was attempted before abandonment
     */
    public function hasAttemptedPayment(): bool
    {
        return !empty($this->checkoutState['paymentAttempted']);
    }

    /**
     * Get cart items at time of abandonment
     */
    public function getCartItems(): array
    {
        return $this->checkoutState['cartItems'] ?? [];
    }

    /**
     * Get time spent in checkout before abandonment
     */
    public function getTimeSpent(): ?int
    {
        return $this->checkoutState['timeSpent'] ?? null;
    }

    /**
     * Get customer email if available
     */
    public function getCustomerEmail(): ?string
    {
        return $this->checkoutState['email']
            ?? $this->checkoutState['billingAddress']['email']
            ?? null;
    }
}
