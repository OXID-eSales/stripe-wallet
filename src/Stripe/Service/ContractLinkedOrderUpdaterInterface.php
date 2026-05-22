<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Mirrors a contract-side terminal transition onto the linked oxorder row.
 *
 * Stripe webhooks update the contract state machine; the linked oxorder must
 * follow for cancelled and failed transitions, otherwise the admin order list
 * (which colours rows by OXTRANSSTATUS) shows cancelled orders as paid.
 *
 * @since Sprint 112
 */
interface ContractLinkedOrderUpdaterInterface
{
    /**
     * Mark the order as cancelled when the contract transitions to CANCELLED.
     *
     * Implementations should be no-op when the order id is empty or the order
     * row does not exist.
     */
    public function markCancelled(string $orderId): void;

    /**
     * Mark the order as failed when the contract transitions to FAILED.
     *
     * Implementations should be no-op when the order id is empty or the order
     * row does not exist.
     */
    public function markFailed(string $orderId, string $reason): void;
}
