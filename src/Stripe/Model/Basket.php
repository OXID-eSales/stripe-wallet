<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Model;

use OxidEsales\Eshop\Application\Model\Basket as CoreBasket;

/**
 * Extended OXID Basket Model with getter for OXID property.
 *
 * Extends the core OXID Basket model to add a getter method for the
 * dynamically set _sOXID property.
 *
 * @since 1.0.0
 */
class Basket extends CoreBasket
{
    /**
     * Get the basket's unique identifier (OXID).
     *
     * Returns the _sOXID property value if set.
     *
     * @return string|null The basket ID or null if not set
     */
    public function getId(): ?string
    {
        return $this->_sOXID ?? null;
    }
}