<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Stripe\Charge;
use Stripe\PaymentIntent;

/**
 * Handles Stripe API interactions for admin order operations.
 *
 * Sprint 46: Extracted from OrderRefund controller to reduce ECC.
 *
 * @since 2.0.0
 */
final class StripeOrderApiService
{
    private ?StripeAdapterInterface $adapter = null;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory
    ) {
    }

    /**
     * Retrieve PaymentIntent from Stripe for the given order.
     */
    public function getPaymentIntent(Order $order): ?PaymentIntent
    {
        /** @phpstan-ignore-next-line OXID core: magic property oxorder__oxtransid->value */
        $transId = $order->oxorder__oxtransid->value;
        if (empty($transId) || !is_string($transId)) {
            return null;
        }

        return $this->getAdapter()->retrievePaymentIntent($transId);
    }

    /**
     * Retrieve the last Charge from a PaymentIntent.
     */
    public function getLastCharge(PaymentIntent $paymentIntent): ?Charge
    {
        $latestCharge = $paymentIntent->latest_charge ?? null;

        if (!$latestCharge) {
            return null;
        }

        if ($latestCharge instanceof Charge) {
            return $latestCharge;
        }

        return $this->getAdapter()->retrieveCharge($latestCharge);
    }

    /**
     * Retrieve PaymentIntent with expanded charge + refunds data.
     *
     * Stripe SDK v19+: Charge.refunds removed from default response.
     * Must use expand to include refunds inline.
     */
    public function getPaymentIntentWithRefunds(Order $order): ?PaymentIntent
    {
        /** @phpstan-ignore-next-line OXID core: magic property oxorder__oxtransid->value */
        $transId = $order->oxorder__oxtransid->value;
        if (empty($transId) || !is_string($transId)) {
            return null;
        }

        return $this->getAdapter()->retrievePaymentIntent($transId, [
            'latest_charge.refunds',
        ]);
    }

    /**
     * Get the Stripe adapter instance.
     */
    public function getAdapter(): StripeAdapterInterface
    {
        if ($this->adapter === null) {
            $this->adapter = $this->adapterFactory->getStripeAdapter();
        }
        return $this->adapter;
    }
}
