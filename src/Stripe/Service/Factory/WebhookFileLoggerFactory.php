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
 * Factory for creating the webhook file logger.
 *
 * Sprint 27: Refactored to extend AbstractFileLoggerFactory using Template Method Pattern.
 * Sprint 16: Logs webhook requests/responses to file.
 * Phase 3: Gated via ModuleConfigurationServiceInterface::isWebhookLoggingEnabled().
 * Logs to log/stripe/stripe_webhooks_<date>.log.
 *
 * @since 2.0.0
 */
class WebhookFileLoggerFactory extends AbstractFileLoggerFactory
{
    public function __construct(ModuleConfigurationServiceInterface $config)
    {
        parent::__construct(static fn (): bool => $config->isWebhookLoggingEnabled());
    }

    protected function getLogFile(): string
    {
        $date = date('Y-m-d');
        return "log/stripe/stripe_webhooks_{$date}.log";
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
