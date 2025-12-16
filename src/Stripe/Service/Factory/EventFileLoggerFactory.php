<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Component\Service\FileLogger;
use OxidSolutionCatalysts\Payments\Component\Service\FileLoggerInterface;

/**
 * Factory for creating the event system file logger.
 *
 * Gets the shop directory from OXID Registry to determine the log file path.
 * Logs to log/osc/stripe_events.log for debugging event handler flow.
 *
 * @since Sprint 25
 */
final class EventFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_events.log';

    /**
     * Create the event file logger.
     */
    public function create(): FileLoggerInterface
    {
        $shopDir = $this->getShopDir();
        $logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;

        return new FileLogger($logFilePath, 'EVENT');
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
