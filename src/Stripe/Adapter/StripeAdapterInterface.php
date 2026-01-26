<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * Stripe-specific adapter interface extending the provider-agnostic PaymentAdapterInterface.
 *
 * This interface follows the Interface Segregation Principle (ISP):
 * - PaymentAdapterInterface: provider-agnostic operations
 * - StripeAdapterInterface: Stripe-specific convenience methods
 *
 * Sprint 19: Route Stripe SDK calls through adapter
 *
 * @since 1.0.0
 */
interface StripeAdapterInterface extends PaymentAdapterInterface
{
    /**
     * Retrieve a Stripe Checkout Session.
     *
     * Used by StripeCheckoutReturnHandler to validate return from Stripe Checkout.
     *
     * @param string $sessionId Stripe Checkout Session ID (cs_xxx)
     * @param array<string> $expand Fields to expand (e.g., ['payment_intent', 'line_items'])
     * @return Session Stripe Checkout Session object
     */
    public function retrieveCheckoutSession(string $sessionId, array $expand = []): Session;

    /**
     * Create a Stripe Checkout Session.
     *
     * Used by StripeCheckoutSessionHandler to create hosted checkout.
     *
     * @param array<string, mixed> $params Checkout session parameters
     * @return Session Created Stripe Checkout Session
     */
    public function createCheckoutSession(array $params): Session;

    /**
     * Retrieve a Stripe PaymentIntent.
     *
     * Used by StripeRefundRequestHandler to get charge information.
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID (pi_xxx)
     * @param array<string> $expand Fields to expand (e.g., ['latest_charge', 'charges'])
     * @return PaymentIntent Stripe PaymentIntent object
     */
    public function retrievePaymentIntent(string $paymentIntentId, array $expand = []): PaymentIntent;

    /**
     * Create a refund by charge ID.
     *
     * Used by StripeRefundRequestHandler to process refunds by charge.
     * This is different from refundPayment() which uses payment_intent.
     *
     * @param string $chargeId Stripe Charge ID (ch_xxx)
     * @param int|null $amount Amount to refund in cents (null for full refund)
     * @param string|null $reason Refund reason (requested_by_customer, duplicate, fraudulent)
     * @param array<string, string>|null $metadata Optional metadata
     * @return Refund Stripe Refund object
     */
    public function createRefundByCharge(
        string $chargeId,
        ?int $amount = null,
        ?string $reason = null,
        ?array $metadata = null
    ): Refund;

    /**
     * Cancel a PaymentIntent authorization.
     *
     * Used by StripeCancelAuthorizationRequestHandler to release an authorized
     * payment without capturing it.
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID (pi_xxx)
     * @param string|null $cancellationReason Reason (requested_by_customer, duplicate, fraudulent, abandoned)
     * @return PaymentIntent Cancelled PaymentIntent object
     */
    public function cancelPaymentIntent(string $paymentIntentId, ?string $cancellationReason = null): PaymentIntent;

    /**
     * Get the Stripe Radar risk score for a PaymentIntent.
     *
     * Sprint 2: Used by StripeRadarFraudCheckService to check fraud risk.
     * Returns the risk_score from the PaymentIntent's latest charge.
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID (pi_xxx)
     * @return float|null Risk score (0.0-1.0) or null if not available
     */
    public function getPaymentIntentRiskScore(string $paymentIntentId): ?float;

    /**
     * Retrieve a Stripe Charge.
     *
     * Sprint 14: Used by OrderRefund controller to get charge details.
     *
     * @param string $chargeId Stripe Charge ID (ch_xxx)
     * @return Charge Stripe Charge object
     * @throws \OxidEsales\PaymentComponent\Adapter\Exception\PaymentAdapterException On API errors
     */
    public function retrieveCharge(string $chargeId): Charge;

    /**
     * Test API connectivity by retrieving account balance.
     *
     * Sprint 18: Used by ConfigurationValidator to verify API keys are valid.
     * This method catches all exceptions and returns false instead of throwing.
     *
     * @return bool True if API connection successful, false otherwise
     */
    public function testConnection(): bool;
}
