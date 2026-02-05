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
 * Factory for creating the webhook file logger.
 *
 * Sprint 27: Refactored to extend AbstractFileLoggerFactory using Template Method Pattern.
 * Sprint 16: Logs webhook requests/responses to file.
 * Logs to log/stripe/stripe_webhooks.log
 *
 * @since 2.0.0
 */
final class WebhookFileLoggerFactory extends AbstractFileLoggerFactory
{
    protected function getLogFile(): string
    {
        return 'log/stripe/stripe_webhooks.log';
    }

    protected function getPrefix(): string
    {
        return 'WEBHOOK';
    }

    protected function getShopDirectory(): string
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        return is_string($shopDir) ? $shopDir : '';
    }
}
