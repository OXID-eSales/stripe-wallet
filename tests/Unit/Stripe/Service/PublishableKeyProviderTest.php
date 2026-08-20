<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\PublishableKeyProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\CoversClass(PublishableKeyProvider::class)]
final class PublishableKeyProviderTest extends TestCase
{
    private function config(string $key, string $mode = 'live'): ModuleConfigurationServiceInterface
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getPublishableKey')->willReturn($key);
        $config->method('getMode')->willReturn($mode);
        $config->method('isTestMode')->willReturn($mode === 'test');

        return $config;
    }

    public function testReturnsTheConfiguredKey(): void
    {
        $provider = new PublishableKeyProvider($this->config('pk_live_123'));

        $this->assertSame('pk_live_123', $provider->resolve());
        $this->assertTrue($provider->isAvailable());
    }

    public function testReturnsNullRatherThanAnEmptyStringWhenUnconfigured(): void
    {
        $provider = new PublishableKeyProvider($this->config(''));

        $this->assertNull($provider->resolve(), 'An empty key must not be handed to the frontend as a value.');
        $this->assertFalse($provider->isAvailable());
    }

    public function testLogsTheMissingKeyWithTheSettingToFill(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('publishable key'),
                $this->callback(static fn (array $c): bool =>
                    ($c['mode'] ?? null) === 'live'
                    && ($c['expected_setting'] ?? null) === 'sStripeLivePk')
            );

        (new PublishableKeyProvider($this->config(''), $logger))->resolve();
    }

    public function testNamesTheTestSettingInTestMode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $c): bool => ($c['expected_setting'] ?? null) === 'sStripeTestPk')
            );

        (new PublishableKeyProvider($this->config('', 'test'), $logger))->resolve();
    }

    public function testDoesNotRepeatTheLogOnEveryRender(): void
    {
        // The template asks for the key on each render; one error per request
        // is a signal, twenty is noise that buries it.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $provider = new PublishableKeyProvider($this->config(''), $logger);
        $provider->resolve();
        $provider->resolve();
        $provider->isAvailable();
    }
}
