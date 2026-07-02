<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult;

/**
 * Service interface for processing Stripe checkout returns.
 *
 * Sprint 21: Extract business logic from StripeCheckoutReturnHandler.
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
}
