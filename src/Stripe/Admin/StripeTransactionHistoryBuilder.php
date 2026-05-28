<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Core\AmountConverter;

/**
 * Assembles the transaction-history row array from Stripe DTO objects.
 *
 * Sprint 114.11b (S4): extracted from OrderRefundViewDataProvider to honor SRP.
 * The provider had 5 responsibilities; this class owns only history assembly.
 *
 * Public API: build(StripePaymentIntentDto $pi): array<int, array<string, mixed>>
 *
 * @since 2.0.0
 */
final class StripeTransactionHistoryBuilder
{
    /**
     * Build the transaction history array from a StripePaymentIntentDto.
     *
     * Covers all actions regardless of origin (admin, Stripe Dashboard, webhook).
     * Uses expanded PaymentIntent to include refunds (Stripe SDK v19+: Charge.refunds removed).
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(StripePaymentIntentDto $paymentIntent): array
    {
        $currency     = $paymentIntent->currency;
        $transactions = [];

        $transactions[] = [
            'type'          => 'authorization',
            'status'        => $this->mapPiStatusToLabel($paymentIntent->status),
            'amount'        => AmountConverter::toMajorUnits($paymentIntent->amount, $currency),
            'currency'      => $currency,
            'transactionId' => $paymentIntent->id,
            'createdAt'     => date('Y-m-d H:i:s', $paymentIntent->created),
        ];

        $charge = $paymentIntent->charge;
        if ($charge === null) {
            return $transactions;
        }

        if ($charge->captured) {
            $transactions[] = [
                'type'          => 'capture',
                'status'        => 'completed',
                'amount'        => AmountConverter::toMajorUnits($charge->amountCaptured, $currency),
                'currency'      => $currency,
                'transactionId' => $charge->id,
                'createdAt'     => date('Y-m-d H:i:s', $charge->created),
            ];
        }

        foreach ($charge->refunds as $refundDto) {
            $transactions[] = [
                'type'          => 'refund',
                'status'        => $refundDto->status,
                'amount'        => AmountConverter::toMajorUnits($refundDto->amount, $currency),
                'currency'      => $currency,
                'transactionId' => $refundDto->id,
                'createdAt'     => date('Y-m-d H:i:s', $refundDto->createdAt),
            ];
        }

        return $transactions;
    }

    private function mapPiStatusToLabel(string $status): string
    {
        return match ($status) {
            'requires_capture'                                                          => 'authorized',
            'succeeded'                                                                 => 'completed',
            'canceled'                                                                  => 'cancelled',
            'requires_payment_method', 'requires_confirmation', 'requires_action'      => 'pending',
            default                                                                     => $status,
        };
    }
}
