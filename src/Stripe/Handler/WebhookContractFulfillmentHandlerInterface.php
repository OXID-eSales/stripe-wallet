<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Handler;

/**
 * Interface for handling webhook events with contract-awareness.
 *
 * This handler bridges Stripe webhooks to the contract state machine by:
 * 1. Looking up contract by providerOrderId (payment intent ID)
 * 2. Validating contract state (must be COMMITTED for fulfillment)
 * 3. Transitioning contract to appropriate state
 * 4. Dispatching relevant events
 *
 * @since 1.0.0
 */
interface WebhookContractFulfillmentHandlerInterface
{
    /**
     * Handle payment_intent.succeeded webhook event.
     *
     * Finds contract by providerOrderId, validates it's COMMITTED,
     * transitions to FULFILLED, and dispatches ContractFulfilledEvent.
     *
     * @param string $providerOrderId Stripe PaymentIntent ID (pi_xxx)
     * @return bool|null true if fulfilled, false if skipped (idempotent), null if not found
     */
    public function handlePaymentSucceeded(string $providerOrderId): ?bool;

    /**
     * Handle charge.captured webhook event.
     *
     * Sprint 8: Now accepts captured amount for contract tracking.
     *
     * @param string $providerOrderId Stripe PaymentIntent ID
     * @param float $capturedAmount Amount captured in currency units (default 0.0 for backwards compat)
     * @return bool|null true if fulfilled, false if skipped, null if not found
     */
    public function handleChargeCaptured(string $providerOrderId, float $capturedAmount = 0.0): ?bool;

    /**
     * Handle charge.refunded webhook event.
     *
     * @param string $providerOrderId Stripe PaymentIntent ID
     * @param float $refundAmount Amount refunded in currency units
     * @return bool|null true if processed, false if skipped, null if not found
     */
    public function handleChargeRefunded(string $providerOrderId, float $refundAmount): ?bool;

    /**
     * Handle payment_intent.payment_failed webhook event.
     *
     * Transitions contract to FAILED state.
     *
     * @param string $providerOrderId Stripe PaymentIntent ID
     * @param string $failureReason Reason for failure
     * @return bool|null true if processed, false if skipped, null if not found
     */
    public function handlePaymentFailed(string $providerOrderId, string $failureReason): ?bool;
}
