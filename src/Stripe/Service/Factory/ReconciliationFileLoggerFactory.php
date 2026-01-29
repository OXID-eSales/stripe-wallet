<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\Factory\AbstractFileLoggerFactory;

/**
 * Factory for creating the reconciliation file logger.
 *
 * Sprint 27: Extends AbstractFileLoggerFactory using Template Method Pattern.
 * Sprint 14: Logs to log/osc/stripe_reconciliation.log for OXPAID reconciliation.
 *
 * @since Sprint 14
 */
final class ReconciliationFileLoggerFactory extends AbstractFileLoggerFactory
{
    protected function getLogFile(): string
    {
        return 'log/osc/stripe_reconciliation.log';
    }

    protected function getPrefix(): string
    {
        return 'RECONCILE';
    }

    protected function getShopDirectory(): string
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        return is_string($shopDir) ? $shopDir : '';
    }
}
