<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Model;

use OxidEsales\Eshop\Core\Counter as EshopCoreCounter;

/**
 * Stripe Order Model Extension
 *
 * Extends OXID Order model to ensure proper order number generation
 * for Stripe payments, following the same pattern as PayPal module.
 *
 * @mixin \OxidEsales\Eshop\Application\Model\Order
 */
class Order extends Order_parent
{
    /**
     * Ensure order number is always set.
     *
     * This override is necessary because the core setOrderNumber() method
     * may not always generate a number depending on order state.
     *
     * Pattern borrowed from PayPal module for consistency across payment modules.
     *
     * @return void
     */
    public function setOrderNumber(): void
    {
        if (!$this->hasOrderNumber()) {
            $this->setNumber();
        } else {
            oxNew(EshopCoreCounter::class)
                ->update($this->getCounterIdent(), $this->getFieldData('oxordernr'));
        }
    }

    /**
     * Check if order already has a valid order number.
     *
     * @return bool
     */
    public function hasOrderNumber(): bool
    {
        return 0 < (int) $this->getFieldData('oxordernr');
    }

    /**
     * Override payment execution for Stripe payments.
     *
     * Stripe payments are handled separately via the Stripe SDK/API,
     * so we bypass the standard OXID payment gateway execution.
     * This allows finalizeOrder() to work normally without triggering
     * the standard payment gateway which would fail for Stripe payments.
     *
     * @param \OxidEsales\Eshop\Application\Model\Basket $oBasket Shopping basket
     * @param object $oUserpayment User payment object
     * @return bool|int Always returns true for Stripe payments to allow order finalization
     */
    protected function executePayment(\OxidEsales\Eshop\Application\Model\Basket $oBasket, $oUserpayment)
    {
        // Check if this is a Stripe payment
        $paymentId = $oBasket->getPaymentId();

        if (strpos($paymentId, 'osc_stripe_') === 0) {
            // Stripe payment - skip standard OXID payment execution
            // Payment is handled separately via Stripe SDK in OrderController
            \OxidEsales\Eshop\Core\Registry::getLogger()->debug('Stripe payment detected, skipping standard payment execution', [
                'payment_id' => $paymentId,
                'order_id' => $this->getId()
            ]);

            return true; // Return success to continue order finalization
        }

        // For non-Stripe payments, use standard OXID payment execution
        return parent::executePayment($oBasket, $oUserpayment);
    }
}