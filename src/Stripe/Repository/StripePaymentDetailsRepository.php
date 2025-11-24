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
 * Repository for Stripe-specific payment details
 *
 * Stores Stripe-specific data like card information, 3D Secure details,
 * risk scores, etc. in the osc_stripe_payment_details table.
 *
 * @since 1.0.0
 */
class StripePaymentDetailsRepository
{
    /**
     * Store Stripe-specific payment details
     *
     * @param string $transactionId Transaction ID to link to
     * @param array $charge Stripe charge data
     * @return void
     */
    public function storePaymentDetails(string $transactionId, array $charge): void
    {
        $db = DatabaseProvider::getDb();

        $card = $charge['payment_method_details']['card'] ?? null;
        $threeDSecure = $card['three_d_secure'] ?? null;

        $sql = "INSERT INTO osc_stripe_payment_details
                (OXID, OXTRANSACTIONID, OXCARDLAST4, OXCARDBRAND, OXCARDEXPMONTH, OXCARDEXPYEAR,
                 OXCARDFUNDING, OXCARDCOUNTRY, OX3DSECURE, OX3DSVERSION, OX3DSAUTHENTICATED,
                 OXRISKSCORE, OXRISKLEVEL, OXCREATED)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $db->execute($sql, [
            UtilsObject::getInstance()->generateUId(),
            $transactionId,
            $card['last4'] ?? null,
            $card['brand'] ?? null,
            $card['exp_month'] ?? null,
            $card['exp_year'] ?? null,
            $card['funding'] ?? null,
            $card['country'] ?? null,
            $threeDSecure ? 1 : 0,
            $threeDSecure['version'] ?? null,
            $threeDSecure['authenticated'] ?? null,
            $charge['outcome']['risk_score'] ?? null,
            $charge['outcome']['risk_level'] ?? null,
        ]);
    }

    /**
     * Find payment details by transaction ID
     *
     * @param string $transactionId
     * @return array|null
     */
    public function findByTransactionId(string $transactionId): ?array
    {
        $db = DatabaseProvider::getDb();

        $sql = "SELECT * FROM osc_stripe_payment_details WHERE OXTRANSACTIONID = ?";
        $result = $db->getRow($sql, [$transactionId]);

        return $result ?: null;
    }
}
