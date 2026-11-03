<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\ClientConfig;

use OxidEsales\PaymentBase\ClientConfig\Contract\ClientConfigSecretPatternProviderInterface;
use OxidEsales\Payments\Stripe\ClientConfig\StripeClientConfigSecretPatternProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(StripeClientConfigSecretPatternProvider::class)]
final class StripeClientConfigSecretPatternProviderTest extends TestCase
{
    public function testItIsASecretPatternProvider(): void
    {
        $this->assertInstanceOf(
            ClientConfigSecretPatternProviderInterface::class,
            new StripeClientConfigSecretPatternProvider()
        );
    }

    #[DataProvider('secrets')]
    public function testItRecognisesStripeSecrets(string $value): void
    {
        $this->assertTrue($this->matchesAny($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secrets(): array
    {
        return [
            'secret key' => ['sk_live_abc123'],
            'restricted key' => ['rk_test_abc123'],
            'webhook secret' => ['whsec_abc123'],
        ];
    }

    public function testItLeavesThePublishableKeyAlone(): void
    {
        $this->assertFalse($this->matchesAny('pk_live_abc123'));
    }

    private function matchesAny(string $value): bool
    {
        foreach ((new StripeClientConfigSecretPatternProvider())->secretValuePatterns() as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
