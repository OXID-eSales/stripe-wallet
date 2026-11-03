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
     * 1. Retrieves the Stripe checkout session
     * 2. Validates payment status
     * 3. Validates contract ID matches
     *
     * Who may come back for the contract is not asked here (VR-20 R5): payment-base's ReturnAccessGuard answers it at
     * the door of the return leg.
     *
     * @param string $checkoutSessionId Stripe Checkout Session ID (cs_xxx)
     * @param string $contractId Contract ID from URL
     */
    public function validateReturn(
        string $checkoutSessionId,
        string $contractId
    ): CheckoutReturnResult;
}
