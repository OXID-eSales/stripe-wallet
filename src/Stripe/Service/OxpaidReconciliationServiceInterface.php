<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\Result\ReconciliationResult;

/**
 * Interface for OXPAID reconciliation service.
 *
 * Reconciles orders with unpaid status against Stripe API.
 *
 * @since Sprint 43
 */
interface OxpaidReconciliationServiceInterface
{
    /**
     * Find all orders that need reconciliation.
     *
     * @param int $maxAgeDays Maximum age of orders to check (default 7 days)
     * @return array<int, array<string, mixed>>
     */
    public function findUnpaidOrders(int $maxAgeDays = 7): array;

    /**
     * Reconcile a single order.
     *
     * @param string $orderId OXID of the order
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @return ReconciliationResult
     */
    public function reconcileOrder(string $orderId, string $paymentIntentId): ReconciliationResult;

    /**
     * Reconcile all unpaid orders.
     *
     * @param int $maxAgeDays Maximum age of orders to check
     * @param bool $dryRun If true, don't make changes, just report
     * @return array<ReconciliationResult>
     */
    public function reconcileAll(int $maxAgeDays = 7, bool $dryRun = false): array;
}
