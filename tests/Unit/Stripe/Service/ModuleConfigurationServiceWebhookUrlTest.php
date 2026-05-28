<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use OxidEsales\Payments\Stripe\Service\ModuleDescriptionProvider;
use OxidEsales\Payments\Stripe\Service\StripeUrlBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 109 Step 2.5 — webhook URL must use the SSL form of the shop URL
 * unconditionally, regardless of the scheme of the current admin request.
 *
 * Sprint 114.11b (S2): ModuleConfigurationService::getWebhookUrl() now delegates
 * to StripeUrlBuilder (SRP extraction). This test verifies the delegation contract.
 * URL-construction behaviour is fully covered by StripeUrlBuilderTest.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ModuleConfigurationService::getWebhookUrl
 */
final class ModuleConfigurationServiceWebhookUrlTest extends TestCase
{
    public function testGetWebhookUrlDelegatesToUrlBuilder(): void
    {
        $expectedUrl = 'https://shop.example.com/index.php?cl=StripeWebhookController';

        $urlBuilder = $this->createMock(StripeUrlBuilder::class);
        $urlBuilder->method('getWebhookUrl')->willReturn($expectedUrl);

        $service = $this->createServiceWithUrlBuilder($urlBuilder);

        $this->assertSame($expectedUrl, $service->getWebhookUrl());
    }

    public function testGetWebhookUrlCallsBuilderExactlyOnce(): void
    {
        $urlBuilder = $this->createMock(StripeUrlBuilder::class);
        $urlBuilder->expects($this->once())->method('getWebhookUrl')
            ->willReturn('https://shop.example.com/index.php?cl=StripeWebhookController');

        $service = $this->createServiceWithUrlBuilder($urlBuilder);

        $service->getWebhookUrl();
    }

    private function createServiceWithUrlBuilder(StripeUrlBuilder&MockObject $urlBuilder): ModuleConfigurationService
    {
        $descriptionProvider = $this->createMock(ModuleDescriptionProvider::class);

        return new class ($urlBuilder, $descriptionProvider) extends ModuleConfigurationService {
            public function __construct(
                StripeUrlBuilder $urlBuilder,
                ModuleDescriptionProvider $descriptionProvider,
            ) {
                // Skip OXID bootstrap — pure unit test.
                // Use reflection to set readonly parent properties.
                $parent = new \ReflectionClass(parent::class);
                $parent->getProperty('urlBuilder')->setValue($this, $urlBuilder);
                $parent->getProperty('descriptionProvider')->setValue($this, $descriptionProvider);
            }
        };
    }
}
