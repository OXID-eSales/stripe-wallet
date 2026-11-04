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

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\StripeClientFactory::class)]
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

}
