<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\PaymentComponent\Adapter\PaymentAdapterInterface;

/**
 * Composite Stripe adapter interface.
 *
 * Extends PaymentAdapterInterface (provider-agnostic) and all
 * Stripe-specific sub-interfaces following the Interface Segregation Principle.
 *
 * Sprint 19: Route Stripe SDK calls through adapter.
 * Sprint 46: ISP split into focused sub-interfaces.
 *
 * @since 1.0.0
 */
interface StripeAdapterInterface extends
    PaymentAdapterInterface,
    StripeCheckoutAdapterInterface,
    StripePaymentIntentAdapterInterface,
    StripeRefundAdapterInterface,
    StripeCustomerAdapterInterface
{
}
