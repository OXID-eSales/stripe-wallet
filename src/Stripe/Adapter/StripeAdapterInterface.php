<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentBase\Adapter\Response\PaymentResponse;

/**
 * Composite Stripe adapter interface.
 *
 * Declares the three payment-flow calls Stripe code makes (create, capture,
 * details) on top of the Stripe-specific sub-interfaces (Interface Segregation).
 * payment-base no longer defines a provider-agnostic adapter interface (VR A4).
 *
 * Sprint 19: Route Stripe SDK calls through adapter.
 * Sprint 46: ISP split into focused sub-interfaces.
 *
 * @since 1.0.0
 */
interface StripeAdapterInterface extends
    StripeCheckoutAdapterInterface,
    StripePaymentIntentAdapterInterface,
    StripeRefundAdapterInterface,
    StripeCustomerAdapterInterface
{
    public function createPayment(CreatePaymentRequest $request): PaymentResponse;

    public function capturePayment(CapturePaymentRequest $request): CaptureResponse;

    public function getPaymentDetails(string $providerPaymentId): PaymentDetailsResponse;
}
