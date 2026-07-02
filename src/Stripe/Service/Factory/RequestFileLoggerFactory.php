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
 * Factory for creating the request logging file logger.
 *
 * Sprint 27: Extends AbstractFileLoggerFactory using Template Method Pattern.
 * Sprint 15: Logs API requests/responses to file instead of database.
 * Phase 3: Gated via ModuleConfigurationServiceInterface::isRequestLoggingEnabled().
 * Logs to log/stripe/stripe_requests_<date>.log.
 *
 * @since 2.0.0
 */
class RequestFileLoggerFactory extends AbstractFileLoggerFactory
{
    public function __construct(ModuleConfigurationServiceInterface $config)
    {
        parent::__construct(static fn (): bool => $config->isRequestLoggingEnabled());
    }

    protected function getLogFile(): string
    {
        $date = date('Y-m-d');
        return "log/stripe/stripe_requests_{$date}.log";
    }

    protected function getPrefix(): string
    {
        return 'REQUEST';
    }

    protected function getShopDirectory(): string
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        return is_string($shopDir) ? $shopDir : '';
    }
}
