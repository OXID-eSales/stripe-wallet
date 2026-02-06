<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Service for resolving/creating Stripe Customer objects.
 *
 * Sprint 45: Stripe Customer lifecycle.
 *
 * @since 2.0.0
 */
interface StripeCustomerServiceInterface
{
    /**
     * Resolve or create a Stripe Customer for the given OXID user.
     *
     * Returns the Stripe Customer ID (cus_xxx).
     * Creates a new Stripe Customer + DB record if none exists.
     *
     * @param string $userId OXID user ID
     * @param string $email User email address
     * @param string $name User full name
     * @return string Stripe Customer ID (cus_xxx)
     */
    public function resolveStripeCustomerId(string $userId, string $email, string $name): string;
}
