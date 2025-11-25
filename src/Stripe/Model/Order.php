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
}