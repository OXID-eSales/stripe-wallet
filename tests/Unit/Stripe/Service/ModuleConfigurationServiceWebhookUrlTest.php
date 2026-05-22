<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 109 Step 2.5 — webhook URL must use the SSL form of the shop URL
 * unconditionally, regardless of the scheme of the current admin request.
 *
 * Stripe rejects non-HTTPS webhook URLs with `invalid_request_error`, so
 * `getWebhookUrl()` must not pass through whatever scheme the admin happens
 * to be browsing the OXID backend with.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::getWebhookUrl
 * @group sprint-109
 */
final class ModuleConfigurationServiceWebhookUrlTest extends TestCase
{
    public function testWebhookUrlUsesSslSchemeEvenWhenSslBaseUrlReturnsHttps(): void
    {
        $service = $this->createServiceWithSslShopUrl('https://shop.example.com/');

        $url = $service->getWebhookUrl();

        $this->assertSame('https://shop.example.com/index.php?cl=StripeWebhookController', $url);
    }

    public function testWebhookUrlStripsTrailingSlashFromBaseUrl(): void
    {
        $service = $this->createServiceWithSslShopUrl('https://shop.example.com/');

        $this->assertStringStartsWith('https://shop.example.com/index.php', $service->getWebhookUrl());
        $this->assertStringNotContainsString('//index.php', $service->getWebhookUrl());
    }

    /**
     * The seam: production code must read from the SSL base URL, not the
     * request-scheme-dependent base URL. If a future refactor accidentally
     * routes getWebhookUrl() through getShopBaseUrl() (the http-capable one),
     * this test fails — the http URL would survive the round-trip.
     */
    public function testWebhookUrlIgnoresHttpBaseUrlWhenSslBaseAvailable(): void
    {
        $service = new class extends ModuleConfigurationService {
            public function __construct()
            {
                // Skip parent constructor — keep test free of OXID framework.
            }

            protected function getSslShopBaseUrl(): string
            {
                return 'https://shop.example.com/';
            }

            protected function getShopBaseUrl(): string
            {
                return 'http://shop.example.com/'; // should NOT be used by getWebhookUrl
            }
        };

        $this->assertStringStartsWith('https://', $service->getWebhookUrl());
    }

    private function createServiceWithSslShopUrl(string $sslShopUrl): ModuleConfigurationService
    {
        return new class ($sslShopUrl) extends ModuleConfigurationService {
            public function __construct(private readonly string $stubbedSslUrl)
            {
                // Skip parent constructor — keep test free of OXID framework.
            }

            protected function getSslShopBaseUrl(): string
            {
                return $this->stubbedSslUrl;
            }
        };
    }
}
