<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\FileLogger;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use Symfony\Component\Filesystem\Path;

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
     *
     * @return FileLoggerInterface
     * @throws \RuntimeException If shop directory not configured
     */
    public function create(): FileLoggerInterface
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');

        if (!is_string($shopDir)) {
            throw new \RuntimeException('Shop directory not configured');
        }

        $logFilePath = Path::join(rtrim($shopDir, '/'), self::LOG_FILE);

        return new FileLogger($logFilePath, 'EVENT');
    }
}
