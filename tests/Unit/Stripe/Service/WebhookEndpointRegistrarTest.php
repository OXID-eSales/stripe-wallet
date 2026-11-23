<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Adapter\StripeWebhookEndpointApiInterface;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrar;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrationResult;
use OxidEsales\Payments\Stripe\Service\WebhookEventCatalog;
use OxidEsales\Payments\Stripe\Service\WebhookUrlOwnership;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrar::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-109')]
final class WebhookEndpointRegistrarTest extends TestCase
{
    private const ACCESS_TOKEN = 'sk_test_dummy';
    private const WEBHOOK_URL  = 'https://shop.example.com/index.php?cl=StripeWebhookController';
    private const ENDPOINT_ID  = 'we_test_123';
    private const SECRET       = 'whsec_test_abc';

    private StripeWebhookEndpointApiInterface&MockObject $api;
    private WebhookEndpointRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(StripeWebhookEndpointApiInterface::class);
        $this->registrar = new WebhookEndpointRegistrar($this->api, new WebhookEventCatalog(), new WebhookUrlOwnership());
    }

    public function testRegisterCreatesNewEndpointWhenNoExistingIdGiven(): void
    {
        $this->api->expects($this->once())
            ->method('create')
            ->with(
                self::ACCESS_TOKEN,
                self::WEBHOOK_URL,
                $this->equalTo((new WebhookEventCatalog())->all()),
                $this->isType('string')
            )
            ->willReturn(new WebhookEndpointRegistrationResult(self::ENDPOINT_ID, self::SECRET));

        $this->api->expects($this->never())->method('update');

        $result = $this->registrar->register(self::ACCESS_TOKEN, self::WEBHOOK_URL, null);

        $this->assertSame(self::ENDPOINT_ID, $result->endpointId);
        $this->assertSame(self::SECRET, $result->secret);
    }

    public function testRegisterUpdatesExistingEndpointWhenIdGiven(): void
    {
        $this->api->expects($this->once())
            ->method('update')
            ->with(
                self::ACCESS_TOKEN,
                self::ENDPOINT_ID,
                self::WEBHOOK_URL,
                $this->equalTo((new WebhookEventCatalog())->all()),
                $this->isType('string')
            )
            ->willReturn(new WebhookEndpointRegistrationResult(self::ENDPOINT_ID, null));

        $this->api->expects($this->never())->method('create');

        $result = $this->registrar->register(self::ACCESS_TOKEN, self::WEBHOOK_URL, self::ENDPOINT_ID);

        $this->assertSame(self::ENDPOINT_ID, $result->endpointId);
        $this->assertNull($result->secret);
    }

    public function testCreateReusesEndpointForSameUrl(): void
    {
        $this->api->method('endpointUrls')
            ->with(self::ACCESS_TOKEN)
            ->willReturn(['we_other_shop' => 'https://other.example.com/hook', self::ENDPOINT_ID => self::WEBHOOK_URL]);
        $this->api->expects($this->never())->method('create');
        $this->api->expects($this->once())
            ->method('update')
            ->with(self::ACCESS_TOKEN, self::ENDPOINT_ID, self::WEBHOOK_URL, $this->anything(), $this->anything())
            ->willReturn(new WebhookEndpointRegistrationResult(self::ENDPOINT_ID, null));

        $result = $this->registrar->register(self::ACCESS_TOKEN, self::WEBHOOK_URL, null);

        $this->assertSame(self::ENDPOINT_ID, $result->endpointId);
    }

    public function testReusedEndpointIsUpdatedToTheEventCatalog(): void
    {
        $this->api->method('endpointUrls')->willReturn([self::ENDPOINT_ID => self::WEBHOOK_URL]);
        $this->api->expects($this->once())
            ->method('update')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->identicalTo((new WebhookEventCatalog())->all()),
                $this->anything()
            )
            ->willReturn(new WebhookEndpointRegistrationResult(self::ENDPOINT_ID, null));

        $this->registrar->register(self::ACCESS_TOKEN, self::WEBHOOK_URL, null);
    }

    public function testRegisterRefusesNonHttpsUrlWithoutCallingApi(): void
    {
        $this->api->expects($this->never())->method('create');
        $this->api->expects($this->never())->method('update');

        $this->expectException(WebhookRegistrationException::class);
        $this->expectExceptionMessageMatches('/https/i');

        $this->registrar->register(self::ACCESS_TOKEN, 'http://shop.example.com/index.php?cl=StripeWebhookController', null);
    }

    public function testRegisterPropagatesApiExceptions(): void
    {
        $this->api->method('create')
            ->willThrowException(WebhookRegistrationException::fromApiError('rate_limit_error', 'too many requests'));

        $this->expectException(WebhookRegistrationException::class);
        $this->expectExceptionMessageMatches('/too many requests/');

        $this->registrar->register(self::ACCESS_TOKEN, self::WEBHOOK_URL, null);
    }

    public function testRegisterPassesEventCatalogListVerbatim(): void
    {
        $catalog = new WebhookEventCatalog();
        $events  = $catalog->all();

        $this->assertContains('payment_intent.succeeded', $events);
        $this->assertContains('charge.refunded', $events);
        $this->assertContains('checkout.session.expired', $events);

        $this->api->expects($this->once())
            ->method('create')
            ->with($this->anything(), $this->anything(), $this->identicalTo($events), $this->anything())
            ->willReturn(new WebhookEndpointRegistrationResult(self::ENDPOINT_ID, self::SECRET));

        $this->registrar->register(self::ACCESS_TOKEN, self::WEBHOOK_URL, null);
    }

    public function testRegisterPassesConnectFlagAndDescriptionThroughToApi(): void
    {
        $api = $this->createMock(StripeWebhookEndpointApiInterface::class);
        $api->expects($this->once())
            ->method('create')
            ->with(
                'sk_test_platform',
                'https://shop.example/index.php?cl=StripeWebhookController',
                $this->isType('array'),
                'caller-supplied description',
                true,
            )
            ->willReturn(new WebhookEndpointRegistrationResult('we_123', 'whsec_abc'));

        $registrar = new WebhookEndpointRegistrar($api, new WebhookEventCatalog(), new WebhookUrlOwnership());
        $registrar->register(
            'sk_test_platform',
            'https://shop.example/index.php?cl=StripeWebhookController',
            null,
            true,
            'caller-supplied description',
        );
    }

    private const SHOP_1_URL = 'https://shop.example.com/index.php?cl=StripeWebhookController&shp=1';
    private const SHOP_2_URL = 'https://shop.example.com/index.php?cl=StripeWebhookController&shp=2';

    public function testRegister_WhenHeldEndpointPointsAtThisShopUrl_UpdatesIt(): void
    {
        $this->api->method('endpointUrls')->willReturn(['we_held' => self::SHOP_2_URL]);
        $this->api->expects($this->never())->method('create');
        $this->api->expects($this->once())->method('update')
            ->with(self::ACCESS_TOKEN, 'we_held', self::SHOP_2_URL, $this->anything(), $this->anything())
            ->willReturn(new WebhookEndpointRegistrationResult('we_held', null));

        $this->registrar->register(self::ACCESS_TOKEN, self::SHOP_2_URL, 'we_held');
    }

    /**
     * The installed shop 1: its endpoint was registered before the URL named the shop (STRP-180).
     */
    public function testRegister_WhenShopOneHoldsAnEndpointWithoutShp_UpdatesItToTheNewUrl(): void
    {
        $this->api->method('endpointUrls')->willReturn(['we_held' => self::WEBHOOK_URL]);
        $this->api->expects($this->never())->method('create');
        $this->api->expects($this->once())->method('update')
            ->with(self::ACCESS_TOKEN, 'we_held', self::SHOP_1_URL, $this->anything(), $this->anything())
            ->willReturn(new WebhookEndpointRegistrationResult('we_held', null));

        $this->registrar->register(self::ACCESS_TOKEN, self::SHOP_1_URL, 'we_held');
    }

    /**
     * A subshop holds a copy of shop 1's endpoint id. It must get its own endpoint and leave that one.
     */
    public function testRegister_WhenHeldEndpointPointsAtADifferentShop_LeavesItAndCreatesANewOne(): void
    {
        $this->api->method('endpointUrls')->willReturn(['we_shop1' => self::SHOP_1_URL]);
        $this->api->expects($this->never())->method('update');
        $this->api->expects($this->once())->method('create')
            ->with(self::ACCESS_TOKEN, self::SHOP_2_URL, $this->anything(), $this->anything(), $this->anything())
            ->willReturn(new WebhookEndpointRegistrationResult('we_shop2', 'whsec_2'));

        $result = $this->registrar->register(self::ACCESS_TOKEN, self::SHOP_2_URL, 'we_shop1');

        $this->assertSame('we_shop2', $result->endpointId);
    }

    public function testRegister_WhenASubshopHoldsAnEndpointWithoutShp_LeavesItAndCreatesANewOne(): void
    {
        $this->api->method('endpointUrls')->willReturn(['we_legacy' => self::WEBHOOK_URL]);
        $this->api->expects($this->never())->method('update');
        $this->api->expects($this->once())->method('create')
            ->willReturn(new WebhookEndpointRegistrationResult('we_shop2', 'whsec_2'));

        $this->registrar->register(self::ACCESS_TOKEN, self::SHOP_2_URL, 'we_legacy');
    }

    public function testRegister_WhenHeldEndpointIsForeignButOneWithThisUrlExists_UpdatesThatOne(): void
    {
        $this->api->method('endpointUrls')->willReturn(['we_shop1' => self::SHOP_1_URL, 'we_shop2' => self::SHOP_2_URL]);
        $this->api->expects($this->once())->method('update')
            ->with(self::ACCESS_TOKEN, 'we_shop2', self::SHOP_2_URL, $this->anything(), $this->anything())
            ->willReturn(new WebhookEndpointRegistrationResult('we_shop2', null));

        $this->registrar->register(self::ACCESS_TOKEN, self::SHOP_2_URL, 'we_shop1');
    }

    /**
     * An id the account does not list can be a Connect webhook on the platform (the old flow): it stays.
     */
    public function testRegister_WhenHeldEndpointIsNotListed_UpdatesTheHeldId(): void
    {
        $this->api->method('endpointUrls')->willReturn([]);
        $this->api->expects($this->once())->method('update')
            ->with(self::ACCESS_TOKEN, 'we_elsewhere', self::SHOP_2_URL, $this->anything(), $this->anything())
            ->willReturn(new WebhookEndpointRegistrationResult('we_elsewhere', null));

        $this->registrar->register(self::ACCESS_TOKEN, self::SHOP_2_URL, 'we_elsewhere');
    }

    public function testClearAll_ForShopOne_DeletesEndpointsWithAndWithoutShp(): void
    {
        $this->api->method('endpointUrls')->with(self::ACCESS_TOKEN)->willReturn([
            'we_new' => self::SHOP_1_URL,
            'we_legacy' => self::WEBHOOK_URL,
            'we_shop2' => self::SHOP_2_URL,
            'we_other_host' => 'https://other.example.com/index.php?cl=StripeWebhookController&shp=1',
        ]);
        $deleted = [];
        $this->api->method('delete')->willReturnCallback(function (string $apiKey, string $id) use (&$deleted): void {
            $this->assertSame(self::ACCESS_TOKEN, $apiKey);
            $deleted[] = $id;
        });

        $count = $this->registrar->clearAll(self::ACCESS_TOKEN, self::SHOP_1_URL);

        $this->assertSame(2, $count);
        $this->assertSame(['we_new', 'we_legacy'], $deleted);
    }

    public function testClearAll_ForASubshop_KeepsTheLegacyEndpointAndOtherShops(): void
    {
        $this->api->method('endpointUrls')->willReturn([
            'we_shop1' => self::SHOP_1_URL,
            'we_legacy' => self::WEBHOOK_URL,
            'we_shop2' => self::SHOP_2_URL,
        ]);
        $this->api->expects($this->once())->method('delete')->with(self::ACCESS_TOKEN, 'we_shop2');

        $this->assertSame(1, $this->registrar->clearAll(self::ACCESS_TOKEN, self::SHOP_2_URL));
    }

    public function testClearAll_WithoutAUrl_DeletesEveryEndpointOfTheAccount(): void
    {
        $this->api->method('endpointUrls')->willReturn(['we_a' => 'https://a.example.com/h', 'we_b' => 'https://b.example.com/h']);
        $this->api->expects($this->exactly(2))->method('delete');

        $this->assertSame(2, $this->registrar->clearAll(self::ACCESS_TOKEN));
    }

    public function testClearAll_WhenNothingBelongsToTheShop_ReturnsZero(): void
    {
        $this->api->method('endpointUrls')->willReturn([]);
        $this->api->expects($this->never())->method('delete');

        $this->assertSame(0, $this->registrar->clearAll(self::ACCESS_TOKEN, self::WEBHOOK_URL));
    }
}
