<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Adapter\StripeClientFactory;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stripe\StripeClient;

/**
 * @covers \OxidEsales\Payments\Stripe\Adapter\StripeClientFactory
 */
final class StripeClientFactoryTest extends TestCase
{
    private ModuleConfigurationServiceInterface|MockObject $configurationService;
    private StripeClientFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationService = $this->createMock(ModuleConfigurationServiceInterface::class);
    }

    public function testCreateReturnsStripeClientWithTestKey(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        $this->factory = new StripeClientFactory($this->configurationService);
        $client = $this->factory->create();

        $this->assertInstanceOf(StripeClient::class, $client);
    }

    public function testCreateReturnsStripeClientWithLiveKey(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(false);

        $this->factory = new StripeClientFactory($this->configurationService);
        $client = $this->factory->create();

        $this->assertInstanceOf(StripeClient::class, $client);
    }

    public function testCreateReturnsNullWhenSecretKeyIsEmpty(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        $this->factory = new StripeClientFactory($this->configurationService);
        $client = $this->factory->create();

        $this->assertNull($client);
    }

    public function testIsTestModeReturnsTrueWhenConfiguredForTestMode(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        $this->factory = new StripeClientFactory($this->configurationService);

        $this->assertTrue($this->factory->isTestMode());
    }

    public function testIsTestModeReturnsFalseWhenConfiguredForLiveMode(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(false);

        $this->factory = new StripeClientFactory($this->configurationService);

        $this->assertFalse($this->factory->isTestMode());
    }

    public function testIsValidSecretKeyReturnsTrueForTestKey(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        $this->factory = new StripeClientFactory($this->configurationService);

        $this->assertTrue($this->factory->isValidSecretKey());
    }

    public function testIsValidSecretKeyReturnsTrueForLiveKey(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(false);

        $this->factory = new StripeClientFactory($this->configurationService);

        $this->assertTrue($this->factory->isValidSecretKey());
    }

    public function testIsValidSecretKeyReturnsFalseForTestKeyInLiveMode(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(false);

        $this->factory = new StripeClientFactory($this->configurationService);

        $this->assertFalse($this->factory->isValidSecretKey());
    }

    public function testIsValidSecretKeyReturnsFalseForLiveKeyInTestMode(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        $this->factory = new StripeClientFactory($this->configurationService);

        $this->assertFalse($this->factory->isValidSecretKey());
    }

    public function testIsValidSecretKeyReturnsFalseForInvalidKeyFormat(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('invalid_key_format');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        $this->factory = new StripeClientFactory($this->configurationService);

        $this->assertFalse($this->factory->isValidSecretKey());
    }

    public function testIsValidSecretKeyReturnsFalseForEmptyKey(): void
    {
        $this->configurationService
            ->method('getToken')
            ->willReturn('');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        $this->factory = new StripeClientFactory($this->configurationService);

        $this->assertFalse($this->factory->isValidSecretKey());
    }

    public function testFactoryInitializesWithConfigurationValues(): void
    {
        $testKey = 'sk_test_4242424242424242424242424242424242424242424242424242424242424242';

        $this->configurationService
            ->method('getToken')
            ->willReturn($testKey);
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        $this->factory = new StripeClientFactory($this->configurationService);

        // Verify that the factory correctly uses the configuration
        $this->assertTrue($this->factory->isTestMode());
        $this->assertTrue($this->factory->isValidSecretKey());
    }
}
