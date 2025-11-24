<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Repository;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\UtilsObject;

/**
 * Repository for payment order state
 *
 * Manages the osc_payment_order_state table which tracks the payment
 * state of orders (paid, captured, refunded, etc.).
 *
 * @since 1.0.0
 */
class PaymentOrderStateRepository
{
    /**
     * Update payment order state
     *
     * @param string $orderId Order ID
     * @param array $paymentIntent Payment intent data
     * @return void
     */
    public function updateOrderState(string $orderId, array $paymentIntent): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "INSERT INTO osc_payment_order_state
                (OXID, OXORDERID, OXPAYMENTSTATE, OXPAYMENTMETHOD, OXCAPTURED,
                 OXCAPTUREDAMOUNT, OXCAPTUREDAT, OXCREATED)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                OXPAYMENTSTATE = VALUES(OXPAYMENTSTATE),
                OXCAPTURED = VALUES(OXCAPTURED),
                OXCAPTUREDAMOUNT = VALUES(OXCAPTUREDAMOUNT),
                OXCAPTUREDAT = VALUES(OXCAPTUREDAT),
                OXUPDATED = NOW()";

        $amount = isset($paymentIntent['amount']) ? $paymentIntent['amount'] / 100 : 0;

        $db->execute($sql, [
            UtilsObject::getInstance()->generateUId(),
            $orderId,
            'paid',
            'stripe',
            1,
            $amount,
        ]);
    }

    /**
     * Find payment state by order ID
     *
     * @param string $orderId
     * @return array|null
     */
    public function findByOrderId(string $orderId): ?array
    {
        $db = DatabaseProvider::getDb();

        $sql = "SELECT * FROM osc_payment_order_state WHERE OXORDERID = ?";
        $result = $db->getRow($sql, [$orderId]);

        return $result ?: null;
    }
}
