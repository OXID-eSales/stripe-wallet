<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\Service\Factory;

use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactory;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapter;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeClientFactory;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactory
 */
final class PaymentAdapterFactoryTest extends TestCase
{
    private PaymentAdapterFactory $factory;
    private ModuleConfigurationService|MockObject $configurationService;
    private StripeClientFactory $clientFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurationService = $this->createMock(ModuleConfigurationService::class);
        $this->configurationService
            ->method('getSecretKey')
            ->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $this->configurationService
            ->method('isTestMode')
            ->willReturn(true);

        // Use real StripeClientFactory since it's final and cannot be mocked
        $this->clientFactory = new StripeClientFactory($this->configurationService);

        $this->factory = new PaymentAdapterFactory(
            $this->configurationService,
            $this->clientFactory
        );
    }

    public function testCreateAdapterReturnsStripeAdapter(): void
    {
        $adapter = $this->factory->createAdapter('stripe');

        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
        $this->assertInstanceOf(StripeAdapter::class, $adapter);
    }

    public function testCreateAdapterThrowsExceptionForUnsupportedProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported payment provider: unsupported_provider');

        $this->factory->createAdapter('unsupported_provider');
    }

    public function testCreateDefaultAdapterReturnsStripeAdapter(): void
    {
        $adapter = $this->factory->createDefaultAdapter();

        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
        $this->assertInstanceOf(StripeAdapter::class, $adapter);
    }

    public function testIsProviderSupportedReturnsTrueForStripe(): void
    {
        $this->assertTrue($this->factory->isProviderSupported('stripe'));
    }

    public function testIsProviderSupportedReturnsFalseForUnsupported(): void
    {
        $this->assertFalse($this->factory->isProviderSupported('paypal'));
        $this->assertFalse($this->factory->isProviderSupported('unzer'));
        $this->assertFalse($this->factory->isProviderSupported('unknown'));
    }

    public function testGetSupportedProvidersReturnsArray(): void
    {
        $providers = $this->factory->getSupportedProviders();

        $this->assertIsArray($providers);
        $this->assertContains('stripe', $providers);
    }

    public function testGetSupportedProvidersContainsStripe(): void
    {
        $providers = $this->factory->getSupportedProviders();

        $this->assertCount(1, $providers);
        $this->assertSame(['stripe'], $providers);
    }

    public function testFactoryCreatesAdapterWithProvidedConfiguration(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getSecretKey')->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('isTestMode')->willReturn(true);

        $clientFactory = new StripeClientFactory($configService);
        $factory = new PaymentAdapterFactory($configService, $clientFactory);

        $adapter = $factory->createAdapter('stripe');

        $this->assertInstanceOf(StripeAdapter::class, $adapter);
    }

    public function testFactorySupportsTestMode(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getSecretKey')->willReturn('sk_test_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('isTestMode')->willReturn(true);

        $clientFactory = new StripeClientFactory($configService);
        $testFactory = new PaymentAdapterFactory($configService, $clientFactory);

        $adapter = $testFactory->createDefaultAdapter();
        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
    }

    public function testFactorySupportsLiveMode(): void
    {
        $configService = $this->createMock(ModuleConfigurationService::class);
        $configService->method('getSecretKey')->willReturn('sk_live_4242424242424242424242424242424242424242424242424242424242424242');
        $configService->method('isTestMode')->willReturn(false);

        $clientFactory = new StripeClientFactory($configService);
        $liveFactory = new PaymentAdapterFactory($configService, $clientFactory);

        $adapter = $liveFactory->createDefaultAdapter();
        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
    }

    public function testCreateAdapterIsCaseSensitive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // 'Stripe' (capitalized) should not match 'stripe'
        $this->factory->createAdapter('Stripe');
    }

    public function testFactoryIsProviderAgnostic(): void
    {
        // The factory itself is in the Component namespace (provider-agnostic)
        // It should be able to create adapters for ANY provider

        $reflectionClass = new \ReflectionClass(PaymentAdapterFactory::class);
        $namespace = $reflectionClass->getNamespaceName();

        // Verify factory is in Component namespace, not Stripe namespace
        $this->assertStringContainsString('Component', $namespace);
        $this->assertStringNotContainsString('Stripe\\', $namespace);

        // Verify it returns provider-agnostic interface
        $adapter = $this->factory->createAdapter('stripe');
        $this->assertInstanceOf(PaymentAdapterInterface::class, $adapter);
    }

    public function testMultipleCallsCreateNewInstances(): void
    {
        $adapter1 = $this->factory->createAdapter('stripe');
        $adapter2 = $this->factory->createAdapter('stripe');

        // Each call should create a new instance
        $this->assertNotSame($adapter1, $adapter2);
    }
}
