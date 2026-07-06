<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Service\Factory\AbstractFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

/**
 * Factory for creating the reconciliation file logger.
 *
 * Sprint 27: Extends AbstractFileLoggerFactory using Template Method Pattern.
 * Sprint 14: Logs to log/stripe/stripe_reconciliation.log for OXPAID reconciliation.
 * Phase 3: Gated via ModuleConfigurationServiceInterface::isReconciliationLoggingEnabled().
 * Logs to log/stripe/stripe_reconciliation_<date>.log.
 *
 * @since Sprint 14
 */
class ReconciliationFileLoggerFactory extends AbstractFileLoggerFactory
{
    public function __construct(
        // Kept for DI resolvability; parent::__construct(callable) removed
        // because AbstractFileLoggerFactory no longer defines a constructor
        // after Sprint 27's Template-Method refactor. See EventFileLoggerFactory
        // for the equivalent fix + rationale.
        private readonly ModuleConfigurationServiceInterface $config,
    ) {
    }

    protected function getLogFile(): string
    {
        $date = date('Y-m-d');
        return "log/stripe/stripe_reconciliation_{$date}.log";
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
