<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

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
     * Records the captured amount on the contract when supplied (>0). The
     * `oe_payments_contract.OXCAPTUREDAMOUNT` field is the source of truth
     * downstream (opalreturns refund broker, admin views, reporting) — it
     * MUST be set whenever a successful capture event reaches the shop,
     * regardless of whether capture was triggered by `charge.captured`
     * (manual) or `payment_intent.succeeded` (automatic).
     *
     * @param string $providerOrderId Stripe PaymentIntent ID (pi_xxx)
     * @param float $capturedAmount Amount captured in currency units. Pass 0.0 if unknown.
     * @return bool|null true if fulfilled, false if skipped (idempotent), null if not found
     */
    public function handlePaymentSucceeded(string $providerOrderId, float $capturedAmount = 0.0): ?bool;

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

    /**
     * Handle payment_intent.canceled webhook event.
     *
     * Sprint 1 Bug Fix: Contract cancellation was not being handled.
     * Transitions contract to CANCELLED state.
     *
     * @param string $providerOrderId Stripe PaymentIntent ID
     * @param string $cancellationReason Reason for cancellation
     * @return bool|null true if processed, false if skipped (already terminal), null if not found
     */
    public function handlePaymentCanceled(string $providerOrderId, string $cancellationReason): ?bool;

    /**
     * Handle checkout.session.expired webhook event.
     *
     * Sprint 1 Bug Fix: Expired checkout sessions were not updating contract state.
     * Transitions contract to EXPIRED state (distinct from CANCELLED).
     *
     * @param string $contractId Contract ID from session metadata
     * @return bool|null true if processed, false if skipped (already terminal), null if not found
     */
    public function handleSessionExpired(string $contractId): ?bool;
}
