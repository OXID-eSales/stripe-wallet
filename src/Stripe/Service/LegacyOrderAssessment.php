<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\Result\LegacyOrderVerdict;

/**
 * Decides whether a legacy Stripe order gets a contract. The dry run and `--apply` ask the same
 * question here, so the dry run lists exactly what `--apply` will skip.
 *
 * The shop row decides first (no Stripe call for an order that is out anyway). Then Stripe decides:
 * the PaymentIntent must exist in the configured account and be paid, and its amounts are the
 * contract's amounts - a refund made in the Stripe Dashboard is in no shop column.
 *
 * STRP-181 / sprint 144 (AW).
 */
class LegacyOrderAssessment
{
    private const NEVER_PAID = '0000-00-00 00:00:00';

    private const STATUS_PAID = 'succeeded';

    private const STRIPE_NOT_FOUND = 'resource_missing';

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ChargeAmountResolverInterface $chargeAmounts
    ) {
    }

    /**
     * @param array<string, mixed> $row the oxorder row
     */
    public function assess(array $row): LegacyOrderVerdict
    {
        $paidAt = $this->paidAt($row);
        $shopReason = $this->shopSkipReason($row, $paidAt);
        if ($shopReason !== null || $paidAt === null) {
            return LegacyOrderVerdict::skip($shopReason ?? 'order was never paid - nothing for the new module to manage');
        }

        return $this->assessAtStripe(LegacyOrderRow::text($row, 'OXTRANSID'), $paidAt);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function shopSkipReason(array $row, ?DateTimeImmutable $paidAt): ?string
    {
        $transactionId = LegacyOrderRow::text($row, 'OXTRANSID');

        return match (true) {
            $transactionId === '' => 'no transaction id on the order',
            !str_starts_with($transactionId, 'pi_')
                => "transaction id '{$transactionId}' is not a PaymentIntent - the new module cannot manage it",
            LegacyOrderRow::text($row, 'OXSTORNO') === '1' => 'order is cancelled (storno)',
            $paidAt === null => 'order was never paid - nothing for the new module to manage',
            LegacyOrderRow::number($row, 'OXTOTALORDERSUM') <= 0.0 => 'order total is zero - nothing to capture or refund',
            default => null,
        };
    }

    private function assessAtStripe(string $paymentIntentId, DateTimeImmutable $paidAt): LegacyOrderVerdict
    {
        try {
            $intent = $this->adapterFactory->getStripeAdapter()->retrievePaymentIntent($paymentIntentId, ['latest_charge']);
        } catch (PaymentAdapterException $refusal) {
            if ($refusal->getErrorCode() !== self::STRIPE_NOT_FOUND) {
                throw $refusal;
            }

            return LegacyOrderVerdict::skip(
                "the configured Stripe account has no PaymentIntent '{$paymentIntentId}'"
                . ' - another Stripe account or mode took this payment'
            );
        }
        if ($intent->status !== self::STATUS_PAID) {
            return LegacyOrderVerdict::skip("Stripe reports the PaymentIntent as '{$intent->status}', not paid");
        }

        return LegacyOrderVerdict::migrate(
            AmountConverter::toMajorUnits($intent->amountReceived, $intent->currency),
            $this->refundedAmount($intent),
            $paidAt
        );
    }

    private function refundedAmount(StripePaymentIntentDto $intent): float
    {
        return $intent->charge === null ? 0.0 : $this->chargeAmounts->customerRefundedAmount($intent->charge);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function paidAt(array $row): ?DateTimeImmutable
    {
        $paid = LegacyOrderRow::text($row, 'OXPAID');
        if ($paid === '' || $paid === self::NEVER_PAID) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $paid);

        return $date === false ? null : $date;
    }
}
