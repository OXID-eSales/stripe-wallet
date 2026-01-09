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
     * @param array<string, mixed> $charge Stripe charge data
     * @return void
     */
    public function storePaymentDetails(string $transactionId, array $charge): void
    {
        $db = DatabaseProvider::getDb();

        $paymentMethodDetails = is_array($charge['payment_method_details'] ?? null)
            ? $charge['payment_method_details']
            : [];
        $card = is_array($paymentMethodDetails['card'] ?? null)
            ? $paymentMethodDetails['card']
            : [];
        $threeDSecure = is_array($card['three_d_secure'] ?? null)
            ? $card['three_d_secure']
            : null;
        $outcome = is_array($charge['outcome'] ?? null)
            ? $charge['outcome']
            : [];

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
            $outcome['risk_score'] ?? null,
            $outcome['risk_level'] ?? null,
        ]);
    }
}
