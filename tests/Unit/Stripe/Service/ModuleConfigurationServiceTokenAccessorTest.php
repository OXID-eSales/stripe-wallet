<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * D2: getToken() is the single accessor; getSecretKey() is a thin alias.
 *
 * Both must return the mode-correct secret key.
 * No caller should observe any difference in the returned value.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ModuleConfigurationService
 */
class ModuleConfigurationServiceTokenAccessorTest extends TestCase
{
    private function makeService(array $settings): ModuleConfigurationService
    {
        return new class ($settings) extends ModuleConfigurationService {
            /** @param array<string, mixed> $testSettings */
            public function __construct(private readonly array $testSettings)
            {
                // Skip parent constructor — avoids OXID framework dependencies
            }

            public function get(string $name): mixed
            {
                return $this->testSettings[$name] ?? '';
            }

            protected function readOxConfigVar(string $key): string
            {
                return '';
            }
        };
    }

    public function testGetTokenReturnsTestKeyInTestMode(): void
    {
        $service = $this->makeService([
            'sStripeMode' => 'test',
            'sStripeTestToken' => 'sk_test_abc',
            'sStripeLiveToken' => 'sk_live_xyz',
        ]);

        $this->assertSame('sk_test_abc', $service->getToken());
    }

    public function testGetTokenReturnsLiveKeyInLiveMode(): void
    {
        $service = $this->makeService([
            'sStripeMode' => 'live',
            'sStripeTestToken' => 'sk_test_abc',
            'sStripeLiveToken' => 'sk_live_xyz',
        ]);

        $this->assertSame('sk_live_xyz', $service->getToken());
    }

    /**
     * D2: getSecretKey() must be a thin alias for getToken() — same value, same mode logic.
     */
    public function testGetSecretKeyReturnsSameValueAsGetToken(): void
    {
        $settings = [
            'sStripeMode' => 'test',
            'sStripeTestToken' => 'sk_test_alias_check',
            'sStripeLiveToken' => 'sk_live_alias_check',
        ];
        $service = $this->makeService($settings);

        $this->assertSame($service->getToken(), $service->getSecretKey());
    }

    public function testGetSecretKeyReturnsSameValueAsGetTokenInLiveMode(): void
    {
        $settings = [
            'sStripeMode' => 'live',
            'sStripeTestToken' => 'sk_test_alias_check',
            'sStripeLiveToken' => 'sk_live_alias_check',
        ];
        $service = $this->makeService($settings);

        $this->assertSame($service->getToken(), $service->getSecretKey());
    }

    /**
     * D2: the interface still declares getSecretKey() so any implementor satisfies it.
     */
    public function testServiceImplementsInterface(): void
    {
        $service = $this->makeService(['sStripeMode' => 'test', 'sStripeTestToken' => 'sk']);

        $this->assertInstanceOf(ModuleConfigurationServiceInterface::class, $service);
    }
}
