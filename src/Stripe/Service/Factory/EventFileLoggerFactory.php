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
 * Factory for creating the event system file logger.
 *
 * Sprint 27: Extends AbstractFileLoggerFactory using Template Method Pattern.
 * Sprint 25: Logs to log/stripe/stripe_events.log for debugging event handler flow.
 * Phase 3: Gated via ModuleConfigurationServiceInterface::isEventLoggingEnabled().
 * Logs to log/stripe/stripe_events_<date>.log.
 *
 * @since Sprint 25
 */
class EventFileLoggerFactory extends AbstractFileLoggerFactory
{
    public function __construct(ModuleConfigurationServiceInterface $config)
    {
        parent::__construct(static fn (): bool => $config->isEventLoggingEnabled());
    }

    protected function getLogFile(): string
    {
        $date = date('Y-m-d');
        return "log/stripe/stripe_events_{$date}.log";
    }

    protected function getPrefix(): string
    {
        return 'EVENT';
    }

    protected function getShopDirectory(): string
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        return is_string($shopDir) ? $shopDir : '';
    }
}
