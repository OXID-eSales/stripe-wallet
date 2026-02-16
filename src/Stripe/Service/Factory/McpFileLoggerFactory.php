<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Factory;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Service\Factory\AbstractFileLoggerFactory;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\PaymentComponent\Service\NullFileLogger;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

/**
 * Factory for creating the MCP file logger.
 *
 * Sprint 56: Test-mode gated — returns NullFileLogger in production mode
 * to prevent sensitive MCP request/response data from being logged.
 * In test mode, logs to log/stripe/mcp.log for debugging.
 *
 * @since Sprint 56
 */
final class McpFileLoggerFactory extends AbstractFileLoggerFactory
{
    public function __construct(
        private readonly ModuleConfigurationServiceInterface $config
    ) {
    }

    public function create(): FileLoggerInterface
    {
        if (!$this->config->isTestMode()) {
            return new NullFileLogger();
        }

        return parent::create();
    }

    protected function getLogFile(): string
    {
        return 'log/stripe/mcp.log';
    }

    protected function getPrefix(): string
    {
        return 'MCP';
    }

    protected function getShopDirectory(): string
    {
        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        return is_string($shopDir) ? $shopDir : '';
    }
}
