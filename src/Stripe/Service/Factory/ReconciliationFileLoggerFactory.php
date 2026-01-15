<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Factory for creating the reconciliation file logger.
 *
 * Gets the shop directory from OXID Registry to determine the log file path.
 *
 * @since Sprint 14
 */
final class ReconciliationFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_reconciliation.log';

    /**
     * Create the reconciliation file logger.
     */
    public function create(): FileLoggerInterface
    {
        $shopDir = $this->getShopDir();
        $logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;

        return new FileLogger($logFilePath, 'RECONCILE');
    }

    /**
     * Get the shop directory path.
     */
    private function getShopDir(): string
    {
        /** @var string $shopDir */
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        return $shopDir;
    }
}
