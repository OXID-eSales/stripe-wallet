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
    /**
     * Upload a product catalog feed to the Stripe API.
     *
     * @param string $feedContent Feed content (CSV or JSONL)
     * @param string $feedFormat Format identifier ('csv' or 'jsonl')
     * @return array{successful: bool, error?: string, products_processed?: int, products_created?: int, products_updated?: int}
     */
    public function syncProductCatalog(string $feedContent, string $feedFormat): array;

    /**
     * Upload inventory updates to the Stripe API.
     *
     * @param string $csvContent CSV content with ID,Availability columns
     * @return array{successful: bool, error?: string, products_processed?: int, products_created?: int, products_updated?: int}
     */
    public function syncProductInventory(string $csvContent): array;

    /**
     * Update fulfillment status for a Stripe order.
     *
     * @param string $orderId Stripe order ID
     * @param string $status Fulfillment status
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function updateFulfillmentStatus(string $orderId, string $status, array $metadata = []): bool;
}
