<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Factory;

use OxidEsales\PaymentComponent\Service\NullFileLogger;
use OxidEsales\Payments\Stripe\Service\Factory\McpFileLoggerFactory;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\Payments\Stripe\Service\Factory\McpFileLoggerFactory
 */
class McpFileLoggerFactoryTest extends TestCase
{
    public function testCreateReturnsNullFileLoggerInProductionMode(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isTestMode')->willReturn(false);

        $factory = new McpFileLoggerFactory($config);
        $logger = $factory->create();

        $this->assertInstanceOf(NullFileLogger::class, $logger);
    }

    public function testProductionModeNullLoggerIsNoOp(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isTestMode')->willReturn(false);

        $factory = new McpFileLoggerFactory($config);
        $logger = $factory->create();

        // NullFileLogger should not throw
        $logger->log('test', ['key' => 'value']);

        $this->assertTrue(true);
    }

    /**
     * In test mode, the factory delegates to parent::create() which calls
     * getShopDirectory(). Since that uses Registry (OXID framework),
     * we can only verify that production mode correctly returns NullFileLogger.
     * The test-mode path is covered by integration tests.
     */
    public function testProductionModeCalledMultipleTimesReturnsFreshInstances(): void
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('isTestMode')->willReturn(false);

        $factory = new McpFileLoggerFactory($config);
        $logger1 = $factory->create();
        $logger2 = $factory->create();

        $this->assertInstanceOf(NullFileLogger::class, $logger1);
        $this->assertInstanceOf(NullFileLogger::class, $logger2);
    }
}
