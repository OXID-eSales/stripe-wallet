<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\StripeUrlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.11b (S2): StripeUrlBuilder — extracted from ModuleConfigurationService.
 *
 * Owns webhook URL construction + SSL shop-base-URL resolution.
 * Tested via anonymous subclass (getSslShopBaseUrl seam), keeping the test
 * free of OXID Registry.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\StripeUrlBuilder
 */
final class StripeUrlBuilderTest extends TestCase
{
    public function testGetWebhookUrlAppendsControllerParam(): void
    {
        $builder = $this->createBuilderWithSslUrl('https://shop.example.com/');

        $url = $builder->getWebhookUrl();

        $this->assertSame('https://shop.example.com/index.php?cl=StripeWebhookController', $url);
    }

    public function testGetWebhookUrlStripsTrailingSlash(): void
    {
        $builder = $this->createBuilderWithSslUrl('https://shop.example.com/');

        $this->assertStringNotContainsString('//index.php', $builder->getWebhookUrl());
    }

    public function testGetWebhookUrlWithoutTrailingSlashInBaseUrl(): void
    {
        $builder = $this->createBuilderWithSslUrl('https://shop.example.com');

        $url = $builder->getWebhookUrl();

        $this->assertSame('https://shop.example.com/index.php?cl=StripeWebhookController', $url);
    }

    public function testGetWebhookUrlAlwaysUsesHttpsScheme(): void
    {
        $builder = $this->createBuilderWithSslUrl('https://shop.example.com/');

        $this->assertStringStartsWith('https://', $builder->getWebhookUrl());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createBuilderWithSslUrl(string $sslUrl): StripeUrlBuilder
    {
        return new class ($sslUrl) extends StripeUrlBuilder {
            public function __construct(private readonly string $stubbedSslUrl)
            {
            }

            protected function getSslShopBaseUrl(): string
            {
                return $this->stubbedSslUrl;
            }
        };
    }
}
