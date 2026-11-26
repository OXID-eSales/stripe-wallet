<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;

/**
 * The contract a shopper comes back from Stripe Checkout with: Stripe creates the order before the redirect
 * (EarlyOrderCreationHandler: DRAFT → NOT_FINISHED → PENDING), so the return finds it PENDING.
 *
 * STRP-182: payment-base refuses a confirmation for a DRAFT contract (it has no order). The fake repositories
 * of the return tests gave a fresh, DRAFT contract - a state a real Stripe return never has.
 */
final class ReturningContract
{
    public static function pending(string $id, string $userId): PaymentContract
    {
        $contract = self::draft($id, $userId);
        $contract->transitionToNotFinished('order_' . $id);
        $contract->transitionToPending();

        return $contract;
    }

    /**
     * A contract for which no order was created before the redirect. payment-base refuses its return (STRP-182).
     */
    public static function draft(string $id, string $userId): PaymentContract
    {
        $contract = new PaymentContract(1, $userId, BasketSnapshot::fromArray([
            'items' => [],
            'totalGross' => 1.0,
            'totalNet' => 1.0,
            'totalVat' => 0.0,
            'currency' => 'EUR',
        ]), $id);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        return $contract;
    }
}
