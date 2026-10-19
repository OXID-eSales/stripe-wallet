<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigProviderInterface;
use OxidEsales\Payments\Stripe\ClientConfig\StripeClientConfigProvider;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\PublishableKeyProvider;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(StripeClientConfigProvider::class)]
final class StripeClientConfigProviderTest extends TestCase
{
    private function providerFor(string $configuredKey): StripeClientConfigProvider
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getPublishableKey')->willReturn($configuredKey);

        return new StripeClientConfigProvider(new PublishableKeyProvider($config));
    }

    public function testIsTheStripeClientConfigProvider(): void
    {
        $provider = $this->providerFor('pk_test_abc');

        $this->assertInstanceOf(ClientConfigProviderInterface::class, $provider);
        $this->assertSame('stripe', $provider->providerId());
    }

    public function testExposesTheTestModePublishableKeyAndNothingElse(): void
    {
        $this->assertSame(
            ['publishableKey' => 'pk_test_abc'],
            $this->providerFor('pk_test_abc')->publicValues()
        );
    }

    public function testExposesTheLiveModePublishableKeyAndNothingElse(): void
    {
        $this->assertSame(
            ['publishableKey' => 'pk_live_xyz'],
            $this->providerFor('pk_live_xyz')->publicValues()
        );
    }

    public function testExposesNothingWhenNoKeyIsConfigured(): void
    {
        $this->assertSame([], $this->providerFor('')->publicValues());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretShapedValues(): array
    {
        return [
            'secret key' => ['sk_live_51Hsecret'],
            'restricted key' => ['rk_live_51Hrestricted'],
            'webhook secret' => ['whsec_abc123'],
            'arbitrary text' => ['not-a-key'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('secretShapedValues')]
    public function testNeverExposesAValueThatIsNotAPublishableKey(string $misconfiguredValue): void
    {
        $this->assertSame([], $this->providerFor($misconfiguredValue)->publicValues());
    }
}
