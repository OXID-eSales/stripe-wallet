<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * @since 2.0.0
 */
final class ChargeRefundLister implements ChargeRefundListerInterface
{
    public function __construct(private readonly StripeAdapterFactoryInterface $adapterFactory)
    {
    }

    public function listFor(string $chargeId): array
    {
        $refunds = [];
        foreach ($this->adapterFactory->getStripeAdapter()->listChargeRefunds($chargeId) as $refund) {
            if ($this->movedNoMoney($refund)) {
                continue;
            }
            $currency = strtoupper($refund->currency);
            $amount = AmountConverter::toMajorUnits($refund->amount, $currency);
            $refunds[] = new ChargeRefund($refund->id, $amount, $currency);
        }

        return $refunds;
    }

    private function movedNoMoney(StripeRefundDto $refund): bool
    {
        return in_array($refund->status, StripeWebhookEventParser::REFUND_STATUSES_WITHOUT_MONEY, true);
    }
}
