<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Interface for static content management during module activation.
 *
 * Handles creation and configuration of Stripe payment methods.
 *
 * @since Sprint 43
 */
interface StaticContentInterface
{
    /**
     * Ensure all Stripe payment methods are created and configured.
     */
    public function ensureStripePaymentMethods(): void;
}
