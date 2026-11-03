<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeWebhookEndpointApiInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\ShopStripeAccount;
use OxidEsales\Payments\Stripe\Service\ShopStripeAccountStoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-23 T3: the Stripe account (`acct_...`) the shop's key belongs to, kept per mode. Stored when the webhook is
 * registered; a shop registered before that asks Stripe once and keeps the answer.
 */
final class ShopStripeAccountTest extends TestCase
{
    private ShopStripeAccountStoreInterface&MockObject $store;
    private StripeWebhookEndpointApiInterface&MockObject $api;
    private ModuleConfigurationServiceInterface&MockObject $config;

    protected function setUp(): void
    {
        $this->store = $this->createMock(ShopStripeAccountStoreInterface::class);
        $this->api = $this->createMock(StripeWebhookEndpointApiInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->config->method('getMode')->willReturn('test');
        $this->config->method('getToken')->willReturn('sk_test_key');
    }

    public function testAStoredAccountIsReturnedWithoutAskingStripe(): void
    {
        $this->store->method('get')->with('test')->willReturn('acct_shop');
        $this->api->expects($this->never())->method('accountId');

        self::assertSame('acct_shop', $this->account()->id());
    }

    public function testAnUnknownAccountIsAskedOfStripeWithTheShopsKeyAndKept(): void
    {
        $this->store->method('get')->with('test')->willReturn('');
        $this->api->expects($this->once())->method('accountId')->with('sk_test_key')->willReturn('acct_shop');
        $this->store->expects($this->once())->method('save')->with('test', 'acct_shop');

        self::assertSame('acct_shop', $this->account()->id());
    }

    public function testRememberStoresTheAccountOfTheKeyForTheCurrentMode(): void
    {
        $this->api->method('accountId')->with('sk_test_key')->willReturn('acct_shop');
        $this->store->expects($this->once())->method('save')->with('test', 'acct_shop');

        $this->account()->remember();
    }

    private function account(): ShopStripeAccount
    {
        return new ShopStripeAccount($this->config, $this->api, $this->store);
    }
}
