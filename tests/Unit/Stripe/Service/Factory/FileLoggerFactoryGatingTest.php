<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Factory;

use OxidEsales\PaymentBase\Service\FileLogger;
use OxidEsales\PaymentBase\Service\NullFileLogger;
use OxidEsales\Payments\Stripe\Service\Factory\EventFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\Factory\ReconciliationFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 TDD tests: channel gating via ModuleConfigurationServiceInterface.
 *
 * Each factory now accepts a ModuleConfigurationServiceInterface dependency.
 * The factory builds a closure from the matching helper and passes it to the
 * parent AbstractFileLoggerFactory as the optional $isEnabled gate.
 *
 * Key assertion per channel:
 * - When the relevant helper returns false → create() returns NullFileLogger.
 * - When the relevant helper returns true  → create() returns FileLogger.
 * - assertFileDoesNotExist proves no file is written when the channel is disabled.
 *
 * The testable-subclass pattern (override getShopDirectory() with a temp dir)
 * mirrors ConcreteFileLoggerFactoriesTest to avoid OXID Registry dependency.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\EventFileLoggerFactory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\ReconciliationFileLoggerFactory::class)]
#[\PHPUnit\Framework\Attributes\Group('logging')]
#[\PHPUnit\Framework\Attributes\Group('phase-3')]
final class FileLoggerFactoryGatingTest extends TestCase
{
    private string $testShopDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testShopDir = sys_get_temp_dir() . '/stripe_factory_gating_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testShopDir);
        parent::tearDown();
    }

    // =========================================================================
    // RequestFileLoggerFactory
    // =========================================================================

    public function testRequestFactoryReturnsNullLoggerWhenRequestLoggingDisabled(): void
    {
        $config = $this->makeConfig(isRequest: false);
        $factory = $this->makeRequestFactory($this->testShopDir, $config);

        $this->assertInstanceOf(NullFileLogger::class, $factory->create());
    }

    public function testRequestFactoryReturnsFileLoggerWhenRequestLoggingEnabled(): void
    {
        $config = $this->makeConfig(isRequest: true);
        $factory = $this->makeRequestFactory($this->testShopDir, $config);

        $this->assertInstanceOf(FileLogger::class, $factory->create());
    }

    /**
     * Hard gate: with request logging disabled, no file is created on disk.
     */
    public function testRequestFactoryWritesNoFileWhenDisabled(): void
    {
        $config = $this->makeConfig(isRequest: false);
        $factory = $this->makeRequestFactory($this->testShopDir, $config);

        $logger = $factory->create();
        $logger->log('should not appear');

        $logDir = $this->testShopDir . '/log/stripe';
        $this->assertFileDoesNotExist($logDir);
    }

    // =========================================================================
    // WebhookFileLoggerFactory
    // =========================================================================

    public function testWebhookFactoryReturnsNullLoggerWhenWebhookLoggingDisabled(): void
    {
        $config = $this->makeConfig(isWebhook: false);
        $factory = $this->makeWebhookFactory($this->testShopDir, $config);

        $this->assertInstanceOf(NullFileLogger::class, $factory->create());
    }

    public function testWebhookFactoryReturnsFileLoggerWhenWebhookLoggingEnabled(): void
    {
        $config = $this->makeConfig(isWebhook: true);
        $factory = $this->makeWebhookFactory($this->testShopDir, $config);

        $this->assertInstanceOf(FileLogger::class, $factory->create());
    }

    /**
     * Hard gate: with webhook logging disabled, no file is created on disk.
     */
    public function testWebhookFactoryWritesNoFileWhenDisabled(): void
    {
        $config = $this->makeConfig(isWebhook: false);
        $factory = $this->makeWebhookFactory($this->testShopDir, $config);

        $logger = $factory->create();
        $logger->log('should not appear');

        $logDir = $this->testShopDir . '/log/stripe';
        $this->assertFileDoesNotExist($logDir);
    }

    // =========================================================================
    // EventFileLoggerFactory
    // =========================================================================

    public function testEventFactoryReturnsNullLoggerWhenEventLoggingDisabled(): void
    {
        $config = $this->makeConfig(isEvent: false);
        $factory = $this->makeEventFactory($this->testShopDir, $config);

        $this->assertInstanceOf(NullFileLogger::class, $factory->create());
    }

    public function testEventFactoryReturnsFileLoggerWhenEventLoggingEnabled(): void
    {
        $config = $this->makeConfig(isEvent: true);
        $factory = $this->makeEventFactory($this->testShopDir, $config);

        $this->assertInstanceOf(FileLogger::class, $factory->create());
    }

    /**
     * Hard gate: with event logging disabled, no file is created on disk.
     */
    public function testEventFactoryWritesNoFileWhenDisabled(): void
    {
        $config = $this->makeConfig(isEvent: false);
        $factory = $this->makeEventFactory($this->testShopDir, $config);

        $logger = $factory->create();
        $logger->log('should not appear');

        $logDir = $this->testShopDir . '/log/stripe';
        $this->assertFileDoesNotExist($logDir);
    }

    // =========================================================================
    // ReconciliationFileLoggerFactory
    // =========================================================================

    public function testReconciliationFactoryReturnsNullLoggerWhenReconciliationLoggingDisabled(): void
    {
        $config = $this->makeConfig(isReconciliation: false);
        $factory = $this->makeReconciliationFactory($this->testShopDir, $config);

        $this->assertInstanceOf(NullFileLogger::class, $factory->create());
    }

    public function testReconciliationFactoryReturnsFileLoggerWhenReconciliationLoggingEnabled(): void
    {
        $config = $this->makeConfig(isReconciliation: true);
        $factory = $this->makeReconciliationFactory($this->testShopDir, $config);

        $this->assertInstanceOf(FileLogger::class, $factory->create());
    }

    /**
     * Hard gate: with reconciliation logging disabled, no file is created on disk.
     */
    public function testReconciliationFactoryWritesNoFileWhenDisabled(): void
    {
        $config = $this->makeConfig(isReconciliation: false);
        $factory = $this->makeReconciliationFactory($this->testShopDir, $config);

        $logger = $factory->create();
        $logger->log('should not appear');

        $logDir = $this->testShopDir . '/log/stripe';
        $this->assertFileDoesNotExist($logDir);
    }

    // =========================================================================
    // Config mock builder
    // =========================================================================

    private function makeConfig(
        bool $isRequest = false,
        bool $isWebhook = false,
        bool $isEvent = false,
        bool $isReconciliation = false,
    ): ModuleConfigurationServiceInterface {
        return new class (
            $isRequest,
            $isWebhook,
            $isEvent,
            $isReconciliation,
        ) implements ModuleConfigurationServiceInterface {
            public function __construct(
                private readonly bool $request,
                private readonly bool $webhook,
                private readonly bool $event,
                private readonly bool $reconciliation,
            ) {
            }

            public function isRequestLoggingEnabled(): bool
            {
                return $this->request;
            }

            public function isWebhookLoggingEnabled(): bool
            {
                return $this->webhook;
            }

            public function isEventLoggingEnabled(): bool
            {
                return $this->event;
            }

            public function isReconciliationLoggingEnabled(): bool
            {
                return $this->reconciliation;
            }

            // ---- interface stubs not under test ----
            public function get(string $name): mixed { return ''; }
            public function isTestMode(): bool { return true; }
            public function getMode(): string { return 'test'; }
            public function getPublishableKey(): string { return ''; }
            public function getToken(): string { return ''; }
            public function getSecretKey(): string { return ''; }
            public function getWebhookSecret(): string { return ''; }
            public function getWebhookEndpoint(): string { return ''; }
            public function isTransactionLoggingEnabled(): bool { return false; }
            public function isRemoveByBillingCountry(): bool { return false; }
            public function isRemoveByBasketCurrency(): bool { return false; }
            public function shouldProvideCustomerEmail(): bool { return false; }
            public function getWebhookUrl(): string { return ''; }
            public function isConfigured(): bool { return false; }
            public function getPlatformKey(): string { return ''; }
            public function getModuleDescription(): string { return ''; }
            public function getCaptureMode(): string { return 'automatic'; }
            public function is3DSecureEnabled(): bool { return false; }
            public function getMinimumOrderAmount(): float { return 0.5; }
            public function getLogLevel(): string { return 'off'; }
            public function isFrontendDebugEnabled(): bool { return false; }
        };
    }

    // =========================================================================
    // Factory builders — testable subclasses overriding getShopDirectory()
    // =========================================================================

    private function makeRequestFactory(
        string $shopDir,
        ModuleConfigurationServiceInterface $config,
    ): RequestFileLoggerFactory {
        return new class ($shopDir, $config) extends RequestFileLoggerFactory {
            public function __construct(
                private readonly string $overrideShopDir,
                ModuleConfigurationServiceInterface $config,
            ) {
                parent::__construct($config);
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    private function makeWebhookFactory(
        string $shopDir,
        ModuleConfigurationServiceInterface $config,
    ): WebhookFileLoggerFactory {
        return new class ($shopDir, $config) extends WebhookFileLoggerFactory {
            public function __construct(
                private readonly string $overrideShopDir,
                ModuleConfigurationServiceInterface $config,
            ) {
                parent::__construct($config);
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    private function makeEventFactory(
        string $shopDir,
        ModuleConfigurationServiceInterface $config,
    ): EventFileLoggerFactory {
        return new class ($shopDir, $config) extends EventFileLoggerFactory {
            public function __construct(
                private readonly string $overrideShopDir,
                ModuleConfigurationServiceInterface $config,
            ) {
                parent::__construct($config);
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    private function makeReconciliationFactory(
        string $shopDir,
        ModuleConfigurationServiceInterface $config,
    ): ReconciliationFileLoggerFactory {
        return new class ($shopDir, $config) extends ReconciliationFileLoggerFactory {
            public function __construct(
                private readonly string $overrideShopDir,
                ModuleConfigurationServiceInterface $config,
            ) {
                parent::__construct($config);
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($path);
    }
}
