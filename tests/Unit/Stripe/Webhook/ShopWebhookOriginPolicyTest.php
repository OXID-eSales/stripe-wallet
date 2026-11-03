<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\ShopStripeAccountInterface;
use OxidEsales\Payments\Stripe\Webhook\ShopWebhookOriginPolicy;
use OxidEsales\Payments\Stripe\Webhook\StripeEventEnvelope;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-23 T3 (S-2): the platform endpoint receives the events of every connected account, live and test. An event that
 * is not this shop's - another merchant's account, the other mode - must not reach a contract: a connected merchant
 * can put any contract id into the metadata of its own PaymentIntent.
 */
final class ShopWebhookOriginPolicyTest extends TestCase
{
    private ModuleConfigurationServiceInterface&MockObject $config;
    private ShopStripeAccountInterface&MockObject $shopAccount;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->shopAccount = $this->createMock(ShopStripeAccountInterface::class);
    }

    public function testAnEventOfTheShopsOwnAccountInTheShopsModeIsAccepted(): void
    {
        $this->config->method('isTestMode')->willReturn(true);
        $this->shopAccount->method('id')->willReturn('acct_shop');

        self::assertNull($this->policy()->refusalFor($this->event('acct_shop', livemode: false)));
    }

    public function testAnEventOfAnotherConnectedAccountIsRefused(): void
    {
        $this->config->method('isTestMode')->willReturn(false);
        $this->shopAccount->method('id')->willReturn('acct_shop');

        self::assertSame('ignored_foreign_account', $this->policy()->refusalFor($this->event('acct_other', livemode: true)));
    }

    public function testAnEventOfTheOtherModeIsRefused(): void
    {
        $this->config->method('isTestMode')->willReturn(true);
        $this->shopAccount->method('id')->willReturn('acct_shop');

        self::assertSame('ignored_livemode_mismatch', $this->policy()->refusalFor($this->event('acct_shop', livemode: true)));
    }

    public function testAnEventWithoutAnAccountIsThePlatformsOwnAndNeedsNoAccountLookup(): void
    {
        $this->config->method('isTestMode')->willReturn(false);
        $this->shopAccount->expects($this->never())->method('id');

        self::assertNull($this->policy()->refusalFor($this->event(null, livemode: true)));
    }

    public function testAnEventWithoutAnEnvelopeIsNotJudged(): void
    {
        $this->config->expects($this->never())->method('isTestMode');

        self::assertNull($this->policy()->refusalFor(new WebhookEvent('evt_1', 'charge.refunded', ['object' => []], 0)));
    }

    private function policy(): ShopWebhookOriginPolicy
    {
        return new ShopWebhookOriginPolicy($this->config, $this->shopAccount);
    }

    private function event(?string $account, bool $livemode): WebhookEvent
    {
        return new WebhookEvent('evt_1', 'charge.refunded', StripeEventEnvelope::into(['object' => []], $account, $livemode), 0);
    }
}
