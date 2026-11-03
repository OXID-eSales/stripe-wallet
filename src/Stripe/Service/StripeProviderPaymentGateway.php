<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewayInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentState;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentStatus;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use RuntimeException;

/**
 * VR-23 T1/T5: what payment-base's LateMoneyResolver (and the stale-order cleanup) asks Stripe about a
 * PaymentIntent nobody is waiting for any more: what it holds, and to give it back. Replaces the module's own
 * StripeLatePaymentRefunder; the rule of what to do with the answer is payment-base's.
 */
final class StripeProviderPaymentGateway implements ProviderPaymentGatewayInterface
{
    private const REQUIRES_CAPTURE = 'requires_capture';
    private const REFUND_REASON = 'duplicate';
    private const RELEASE_REASON = 'abandoned';
    private const ACCEPTED_REFUND_STATUSES = [StripeStatusMapper::STRIPE_SUCCEEDED, StripeStatusMapper::STRIPE_REFUND_STATUS_PENDING];

    public function __construct(private readonly StripeAdapterFactoryInterface $adapterFactory)
    {
    }

    public function provider(): string
    {
        return StripeDefinitions::PROVIDER;
    }

    public function statusOf(string $providerPaymentId): ProviderPaymentStatus
    {
        $intent = $this->adapterFactory->getStripeAdapter()->retrievePaymentIntent($providerPaymentId);
        $currency = strtoupper($intent->currency);

        if ($intent->status === self::REQUIRES_CAPTURE) {
            return new ProviderPaymentStatus(ProviderPaymentState::Authorized, $intent->id, AmountConverter::toMajorUnits($intent->amountCapturable, $currency), $currency);
        }
        if ($intent->status === StripeStatusMapper::STRIPE_SUCCEEDED) {
            return new ProviderPaymentStatus(ProviderPaymentState::Paid, $intent->id, AmountConverter::toMajorUnits($intent->amountReceived, $currency), $currency);
        }

        return new ProviderPaymentStatus(ProviderPaymentState::NoMoney, $intent->id, 0.0, $currency);
    }

    public function refund(string $providerPaymentId, string $idempotencyKey): string
    {
        $adapter = $this->adapterFactory->getStripeAdapter();
        $chargeId = $this->chargeOf($adapter->retrievePaymentIntent($providerPaymentId));

        $refund = $adapter->createRefundByCharge($chargeId, null, self::REFUND_REASON, ['late_money' => '1'], $idempotencyKey);
        if (!in_array($refund->status, self::ACCEPTED_REFUND_STATUSES, true)) {
            throw new RuntimeException('Stripe did not accept the refund of the late payment: status ' . $refund->status);
        }

        return $refund->id;
    }

    public function release(string $providerPaymentId, string $idempotencyKey): void
    {
        $this->adapterFactory->getStripeAdapter()->cancelPaymentIntent($providerPaymentId, self::RELEASE_REASON);
    }

    private function chargeOf(StripePaymentIntentDto $intent): string
    {
        $chargeId = $intent->latestChargeId;
        if ($chargeId === null || $chargeId === '') {
            throw new RuntimeException('The paid PaymentIntent names no charge to refund.');
        }

        return $chargeId;
    }
}
