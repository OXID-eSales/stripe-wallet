<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Service;

use OxidSolutionCatalysts\Payments\Component\Service\Result\CheckoutResult;
use OxidSolutionCatalysts\Payments\Component\Service\Result\OrderConfirmationResult;

/**
 * Orchestrates checkout accounting for Stripe payments.
 *
 * Note: This service handles BACKEND ACCOUNTING only.
 * Actual payment processing happens on the frontend via Stripe.js.
 *
 * Responsibilities:
 * - Create payment contracts
 * - Dispatch payment events
 * - Coordinate with event handlers
 * - Return results to controllers
 *
 * @since 1.0.0
 */
interface CheckoutOrchestratorInterface
{
    /**
     * Processes checkout: creates contract, snapshots basket, dispatches events.
     *
     * Called from OrderController::execute() BEFORE parent::execute().
     *
     * Does NOT:
     * - Call Stripe API
     * - Process payments
     * - Handle redirects
     *
     * Does:
     * - Create PaymentContract
     * - Snapshot basket data
     * - Store payment_intent_id for later webhook matching
     * - Emit PaymentInitiatedEvent
     *
     * @param object $basket OXID basket object
     * @param object $user OXID user object
     * @param string $paymentMethodId Payment method (e.g., 'stripe_card')
     * @param string|null $paymentIntentId Stripe PaymentIntent ID from frontend (optional)
     * @return CheckoutResult Result containing contract_id or error
     */
    public function processCheckout(
        object $basket,
        object $user,
        string $paymentMethodId,
        ?string $paymentIntentId = null
    ): CheckoutResult;

    /**
     * Confirms order completion and transitions contract state.
     *
     * Called from ThankyouController::render().
     *
     * Transitions contract: PENDING → COMMITTED
     * Final transition to FULFILLED happens via webhook.
     *
     * @param string $orderId OXID order ID
     * @param string|null $contractId Contract ID from session
     * @return OrderConfirmationResult Result with contract state
     */
    public function confirmOrderCompletion(
        string $orderId,
        ?string $contractId = null
    ): OrderConfirmationResult;
}
