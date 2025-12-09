<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\DTO\CheckoutReturnResult;

/**
 * Service interface for processing Stripe checkout returns.
 *
 * Sprint 21: Extract business logic from StripeCheckoutReturnHandler.
 *
 * SOLID Principles:
 * - SRP: Handles checkout return validation only
 * - OCP: Can be extended for different checkout flows
 * - DIP: Handlers depend on this abstraction
 * - ISP: Focused interface for checkout return operations only
 *
 * @since 2.0.0
 */
interface CheckoutReturnServiceInterface
{
    /**
     * Validate and process a checkout return from Stripe.
     *
     * This method:
     * 1. Validates the contract token
     * 2. Retrieves the Stripe checkout session
     * 3. Validates payment status
     * 4. Validates contract ID matches
     *
     * @param string $checkoutSessionId Stripe Checkout Session ID (cs_xxx)
     * @param string $contractId Contract ID from URL
     * @param string $contractToken Security token for validation
     */
    public function validateReturn(
        string $checkoutSessionId,
        string $contractId,
        string $contractToken
    ): CheckoutReturnResult;

    /**
     * Retrieve checkout session details from Stripe.
     *
     * @param string $checkoutSessionId Stripe Checkout Session ID
     * @return array{
     *     payment_status: string,
     *     payment_intent_id: string|null,
     *     amount_total: int,
     *     currency: string,
     *     contract_id: string|null
     * }|null Session details or null if not found
     */
    public function getSessionDetails(string $checkoutSessionId): ?array;
}
