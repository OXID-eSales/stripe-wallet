<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult;

/**
 * Service interface for creating Stripe checkout sessions.
 *
 * Sprint 21: Extract business logic from StripeCheckoutSessionHandler.
 *
 * SOLID Principles:
 * - SRP: Handles checkout session creation only
 * - OCP: Can be extended for different checkout configurations
 * - DIP: Handlers depend on this abstraction
 * - ISP: Focused interface for checkout session operations only
 *
 * @since 2.0.0
 */
interface CheckoutSessionServiceInterface
{
    /**
     * Create a Stripe checkout session.
     *
     * @param string $contractId Contract ID for metadata
     * @param BasketSnapshot $basketSnapshot Basket data for line items
     * @param string $successUrl URL to redirect on success
     * @param string $cancelUrl URL to redirect on cancel
     * @param string $shopId Shop ID for metadata
     * @param string $captureMode Payment capture mode (automatic/manual)
     * @param string|null $orderId Order ID (OXID) for metadata - STRP-75
     * @param string|null $orderNumber Order number (OXORDERNR) for metadata - STRP-75
     */
    /**
     * @param string|null $stripeCustomerId Stripe Customer ID for email prefill and saved cards - Sprint 45
     */
    public function createSession(
        string $contractId,
        BasketSnapshot $basketSnapshot,
        string $successUrl,
        string $cancelUrl,
        string $shopId = '1',
        string $captureMode = 'automatic',
        ?string $orderId = null,
        ?string $orderNumber = null,
        ?string $stripeCustomerId = null
    ): CheckoutSessionResult;

    /**
     * Build line items from basket snapshot.
     *
     * @return array<int, array<string, mixed>> Stripe-formatted line items
     */
    public function buildLineItems(BasketSnapshot $snapshot): array;

    /**
     * Generate a secure success URL with contract token.
     *
     * @param string $shopUrl Base shop URL
     * @param string $contractId Contract ID
     * @param string $contractToken Security token
     * @param string $sessionId OXID session ID to preserve session across external redirect
     */
    public function buildSuccessUrl(string $shopUrl, string $contractId, string $contractToken, string $sessionId = ''): string;
}
