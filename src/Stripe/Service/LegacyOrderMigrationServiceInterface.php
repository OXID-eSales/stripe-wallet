<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\Result\LegacyOrderMigrationReport;

/**
 * Creates payment-base contracts for orders paid through the legacy
 * oxid-esales/stripe-module so the new module's admin panel (capture state,
 * refunds, transaction history) can manage them.
 *
 * STRP-181.
 */
interface LegacyOrderMigrationServiceInterface
{
    /**
     * @param bool $apply false = dry run: assess every candidate as `true` does, write nothing
     */
    public function migrate(bool $apply): LegacyOrderMigrationReport;
}
