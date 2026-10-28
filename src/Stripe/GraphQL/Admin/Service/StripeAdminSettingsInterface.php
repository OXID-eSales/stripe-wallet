<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

/**
 * The Stripe module settings as the admin API reads them (infrastructure seam over the module settings).
 */
interface StripeAdminSettingsInterface
{
    public function snapshot(): StripeConfigSnapshot;

    /**
     * The stored secret key of the connected account for a mode, for the key-pair check inside the service layer.
     * Never leaves it.
     */
    public function token(string $mode): string;

    /**
     * The stored platform key (webhook registration for Connect) for a mode. Never leaves the service layer.
     */
    public function platformKey(string $mode): string;

    /**
     * Stores the fields the change carries; a secret only when it is a new one.
     */
    public function apply(StripeConfigChange $change): void;
}
