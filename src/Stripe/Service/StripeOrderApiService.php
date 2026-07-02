<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;

/**
 * Handles Stripe API interactions for admin order operations.
 *
 * Sprint 46: Extracted from OrderRefund controller to reduce ECC.
 * Sprint 114.10b: Return types migrated from raw \Stripe\* to StripePaymentIntentDto
 * (A1 boundary fix — seals Stripe SDK types inside src/Stripe/Adapter/).
 *
 * @since 2.0.0
 */
class StripeOrderApiService
{
    private ?StripeAdapterInterface $adapter = null;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory
    ) {
    }

    /**
     * Retrieve PaymentIntent from Stripe for the given order.
     */
    public function getPaymentIntent(Order $order): ?StripePaymentIntentDto
    {
        $transId = $this->extractTransId($order);
        if (empty($transId)) {
            return null;
        }

        return $this->getAdapter()->retrievePaymentIntent($transId);
    }

    /**
     * Retrieve PaymentIntent with expanded charge + refunds data.
     *
     * Stripe SDK v19+: Charge.refunds removed from default response.
     * Must use expand to include refunds inline.
     */
    public function getPaymentIntentWithRefunds(Order $order): ?StripePaymentIntentDto
    {
        $transId = $this->extractTransId($order);
        if (empty($transId)) {
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

    /**
     * Extract the Stripe transaction ID from an OXID order.
     *
     * Protected to allow testable subclasses to bypass OXID magic property access.
     */
    protected function extractTransId(Order $order): string
    {
        /** @phpstan-ignore-next-line OXID core: magic property oxorder__oxtransid->value */
        $transId = $order->oxorder__oxtransid->value;

        return (is_string($transId) && !empty($transId)) ? $transId : '';
    }
}
