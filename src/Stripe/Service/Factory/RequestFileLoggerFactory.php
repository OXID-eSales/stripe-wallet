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
 * Factory for creating the request logging file logger.
 *
 * Sprint 27: Extends AbstractFileLoggerFactory using Template Method Pattern.
 * Sprint 15: Logs API requests/responses to file instead of database.
 * Logs to log/osc/stripe_requests.log.
 *
 * @since 2.0.0
 */
final class RequestFileLoggerFactory extends AbstractFileLoggerFactory
{
    protected function getLogFile(): string
    {
        return 'log/osc/stripe_requests.log';
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
