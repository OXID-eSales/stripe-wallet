<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\Result\BackfillReport;

/**
 * Sprint 137 · Story 2: repairs contracts fulfilled before the webhook recorded
 * the captured amount.
 */
interface CapturedAmountBackfillServiceInterface
{
    /**
     * @param bool $apply false lists the candidates only; true records their amounts
     */
    public function backfill(bool $apply): BackfillReport;
}
