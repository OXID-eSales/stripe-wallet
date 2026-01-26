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

/**
 * Factory for creating the webhook file logger.
 *
 * Sprint 16: Logs webhook requests/responses to file.
 * Logs to log/osc/stripe_webhooks.log
 *
 * @since 2.0.0
 */
final class WebhookFileLoggerFactory
{
    private const LOG_FILE = 'log/osc/stripe_webhooks.log';

    /**
     * Create the webhook file logger.
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

        $logFilePath = rtrim($shopDir, '/') . '/' . self::LOG_FILE;

        return new FileLogger($logFilePath, 'WEBHOOK');
    }
}
