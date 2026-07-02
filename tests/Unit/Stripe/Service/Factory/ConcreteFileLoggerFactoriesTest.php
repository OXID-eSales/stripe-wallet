<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Factory;

use OxidEsales\PaymentBase\Service\FileLogger;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\Factory\EventFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\Factory\ReconciliationFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory;
use PHPUnit\Framework\TestCase;

/**
 * Phase 0 characterization: each of the 4 Stripe concrete file-logger factories
 * returns a real FileLogger (never NullFileLogger) today — always-on behavior.
 *
 * Also pins the expected log file path fragment and prefix per factory so that
 * Phase 3 wiring cannot silently change log destinations or prefixes.
 *
 * Each factory reads Registry::getConfig()->getConfigParam('sShopDir') for the
 * shop dir. The testable-subclass pattern overrides getShopDirectory() to return
 * a predictable temp dir, avoiding the OXID Registry dependency in unit tests.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\RequestFileLoggerFactory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\WebhookFileLoggerFactory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\EventFileLoggerFactory::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\Factory\ReconciliationFileLoggerFactory::class)]
#[\PHPUnit\Framework\Attributes\Group('logging')]
#[\PHPUnit\Framework\Attributes\Group('phase-0-characterization')]
final class ConcreteFileLoggerFactoriesTest extends TestCase
{
    private string $testShopDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testShopDir = sys_get_temp_dir() . '/stripe_concrete_factory_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // RequestFileLoggerFactory
    // -------------------------------------------------------------------------

    /**
     * Characterization: RequestFileLoggerFactory::create() returns a FileLogger.
     */
    public function testRequestFactoryReturnsFileLogger(): void
    {
        $factory = $this->makeRequestFactory($this->testShopDir);

        $this->assertInstanceOf(FileLogger::class, $factory->create());
    }

    /**
     * Characterization: RequestFileLoggerFactory::create() returns a FileLoggerInterface.
     */
    public function testRequestFactoryReturnsFileLoggerInterface(): void
    {
        $factory = $this->makeRequestFactory($this->testShopDir);

        $this->assertInstanceOf(FileLoggerInterface::class, $factory->create());
    }

    /**
     * Characterization: request log file path contains the 'stripe_requests_' fragment.
     * Pins the destination so Phase 3 wiring cannot silently redirect the log.
     */
    public function testRequestFactoryLogFileContainsExpectedPathFragment(): void
    {
        $factory = $this->makeRequestFactory($this->testShopDir);

        $logger = $factory->create();
        $logFile = $this->extractLogFilePath($logger);

        $this->assertStringContainsString('stripe_requests_', $logFile);
    }

    /**
     * Characterization: request logger prefix is 'REQUEST'.
     */
    public function testRequestFactoryPrefixIsRequest(): void
    {
        $factory = $this->makeRequestFactory($this->testShopDir);

        $logger = $factory->create();
        $prefix = $this->extractPrefix($logger);

        $this->assertSame('REQUEST', $prefix);
    }

    // -------------------------------------------------------------------------
    // WebhookFileLoggerFactory
    // -------------------------------------------------------------------------

    /**
     * Characterization: WebhookFileLoggerFactory::create() returns a FileLogger.
     */
    public function testWebhookFactoryReturnsFileLogger(): void
    {
        $factory = $this->makeWebhookFactory($this->testShopDir);

        $this->assertInstanceOf(FileLogger::class, $factory->create());
    }

    /**
     * Characterization: webhook log file path contains the 'stripe_webhooks_' fragment.
     */
    public function testWebhookFactoryLogFileContainsExpectedPathFragment(): void
    {
        $factory = $this->makeWebhookFactory($this->testShopDir);

        $logger = $factory->create();
        $logFile = $this->extractLogFilePath($logger);

        $this->assertStringContainsString('stripe_webhooks_', $logFile);
    }

    /**
     * Characterization: webhook logger prefix is 'WEBHOOK'.
     */
    public function testWebhookFactoryPrefixIsWebhook(): void
    {
        $factory = $this->makeWebhookFactory($this->testShopDir);

        $logger = $factory->create();
        $prefix = $this->extractPrefix($logger);

        $this->assertSame('WEBHOOK', $prefix);
    }

    // -------------------------------------------------------------------------
    // EventFileLoggerFactory
    // -------------------------------------------------------------------------

    /**
     * Characterization: EventFileLoggerFactory::create() returns a FileLogger.
     */
    public function testEventFactoryReturnsFileLogger(): void
    {
        $factory = $this->makeEventFactory($this->testShopDir);

        $this->assertInstanceOf(FileLogger::class, $factory->create());
    }

    /**
     * Characterization: event log file path contains the 'stripe_events_' fragment.
     */
    public function testEventFactoryLogFileContainsExpectedPathFragment(): void
    {
        $factory = $this->makeEventFactory($this->testShopDir);

        $logger = $factory->create();
        $logFile = $this->extractLogFilePath($logger);

        $this->assertStringContainsString('stripe_events_', $logFile);
    }

    /**
     * Characterization: event logger prefix is 'EVENT'.
     */
    public function testEventFactoryPrefixIsEvent(): void
    {
        $factory = $this->makeEventFactory($this->testShopDir);

        $logger = $factory->create();
        $prefix = $this->extractPrefix($logger);

        $this->assertSame('EVENT', $prefix);
    }

    // -------------------------------------------------------------------------
    // ReconciliationFileLoggerFactory
    // -------------------------------------------------------------------------

    /**
     * Characterization: ReconciliationFileLoggerFactory::create() returns a FileLogger.
     */
    public function testReconciliationFactoryReturnsFileLogger(): void
    {
        $factory = $this->makeReconciliationFactory($this->testShopDir);

        $this->assertInstanceOf(FileLogger::class, $factory->create());
    }

    /**
     * Characterization: reconciliation log file path contains 'stripe_reconciliation_'.
     */
    public function testReconciliationFactoryLogFileContainsExpectedPathFragment(): void
    {
        $factory = $this->makeReconciliationFactory($this->testShopDir);

        $logger = $factory->create();
        $logFile = $this->extractLogFilePath($logger);

        $this->assertStringContainsString('stripe_reconciliation_', $logFile);
    }

    /**
     * Characterization: reconciliation logger prefix is 'RECONCILE'.
     */
    public function testReconciliationFactoryPrefixIsReconcile(): void
    {
        $factory = $this->makeReconciliationFactory($this->testShopDir);

        $logger = $factory->create();
        $prefix = $this->extractPrefix($logger);

        $this->assertSame('RECONCILE', $prefix);
    }

    // -------------------------------------------------------------------------
    // Cross-factory: all 4 channels are always-on today (no NullFileLogger)
    // -------------------------------------------------------------------------

    /**
     * Characterization: none of the 4 factories produces a NullFileLogger.
     * Confirms the always-on, no-gating status that Phase 3 will change.
     */
    public function testNoFactoryReturnsNullFileLogger(): void
    {
        $factories = [
            'request'        => $this->makeRequestFactory($this->testShopDir),
            'webhook'        => $this->makeWebhookFactory($this->testShopDir),
            'event'          => $this->makeEventFactory($this->testShopDir),
            'reconciliation' => $this->makeReconciliationFactory($this->testShopDir),
        ];

        foreach ($factories as $channel => $factory) {
            $this->assertInstanceOf(
                FileLogger::class,
                $factory->create(),
                "Expected {$channel} factory to return FileLogger (always-on), got NullFileLogger"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Factory builders — testable subclasses that bypass Registry::getConfig()
    // -------------------------------------------------------------------------

    private function makeRequestFactory(string $shopDir): RequestFileLoggerFactory
    {
        return new class ($shopDir) extends RequestFileLoggerFactory {
            public function __construct(private readonly string $overrideShopDir)
            {
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    private function makeWebhookFactory(string $shopDir): WebhookFileLoggerFactory
    {
        return new class ($shopDir) extends WebhookFileLoggerFactory {
            public function __construct(private readonly string $overrideShopDir)
            {
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    private function makeEventFactory(string $shopDir): EventFileLoggerFactory
    {
        return new class ($shopDir) extends EventFileLoggerFactory {
            public function __construct(private readonly string $overrideShopDir)
            {
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    private function makeReconciliationFactory(string $shopDir): ReconciliationFileLoggerFactory
    {
        return new class ($shopDir) extends ReconciliationFileLoggerFactory {
            public function __construct(private readonly string $overrideShopDir)
            {
            }

            protected function getShopDirectory(): string
            {
                return $this->overrideShopDir;
            }
        };
    }

    // -------------------------------------------------------------------------
    // Reflection helpers — read private state of FileLogger without writing files
    // -------------------------------------------------------------------------

    private function extractLogFilePath(FileLoggerInterface $logger): string
    {
        $reflection = new \ReflectionClass(FileLogger::class);
        $property = $reflection->getProperty('logFilePath');

        return (string) $property->getValue($logger);
    }

    private function extractPrefix(FileLoggerInterface $logger): string
    {
        $reflection = new \ReflectionClass(FileLogger::class);
        $property = $reflection->getProperty('prefix');

        return (string) $property->getValue($logger);
    }
}
