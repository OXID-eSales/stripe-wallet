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
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\StripeUrlBuilder::class)]
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

    public function testGetClassicSettingsUrlPointsAtTheModuleConfigPageOfTheShop(): void
    {
        $builder = $this->createBuilderWithAdminTarget('https://shop.example.com/', 'admin', 3);

        $this->assertSame(
            'https://shop.example.com/admin/index.php?cl=module_config&oxid=oe_payments_stripe_wallet&shp=3',
            $builder->getClassicSettingsUrl()
        );
    }

    public function testGetClassicSettingsUrlRespectsAConfiguredAdminDirectory(): void
    {
        $builder = $this->createBuilderWithAdminTarget('https://shop.example.com/', 'backoffice', 1);

        $this->assertStringStartsWith('https://shop.example.com/backoffice/index.php?', $builder->getClassicSettingsUrl());
    }

    public function testGetClassicSettingsUrlAddsTheMissingSlashAfterTheShopUrl(): void
    {
        $builder = $this->createBuilderWithAdminTarget('https://shop.example.com', 'admin', 1);

        $this->assertStringStartsWith('https://shop.example.com/admin/index.php?', $builder->getClassicSettingsUrl());
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

    private function createBuilderWithAdminTarget(string $shopUrl, string $adminDir, int $shopId): StripeUrlBuilder
    {
        return new class ($shopUrl, $adminDir, $shopId) extends StripeUrlBuilder {
            public function __construct(
                private readonly string $stubbedShopUrl,
                private readonly string $stubbedAdminDir,
                private readonly int $stubbedShopId,
            ) {
            }

            protected function getAdminShopBaseUrl(): string
            {
                return $this->stubbedShopUrl;
            }

            protected function getAdminDirectory(): string
            {
                return $this->stubbedAdminDir;
            }

            protected function getShopId(): int
            {
                return $this->stubbedShopId;
            }
        };
    }
}
