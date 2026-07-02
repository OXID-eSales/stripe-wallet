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
        $this->registrar = new WebhookEndpointRegistrar($this->api, new WebhookEventCatalog());
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

        $registrar = new WebhookEndpointRegistrar($api, new WebhookEventCatalog());
        $registrar->register(
            'sk_test_platform',
            'https://shop.example/index.php?cl=StripeWebhookController',
            null,
            true,
            'caller-supplied description',
        );
    }

    public function testClearAllForwardsUrlFilterToListAndDeletesEach(): void
    {
        $this->api->method('listAll')
            ->with(self::ACCESS_TOKEN, self::WEBHOOK_URL)
            ->willReturn(['we_a', 'we_b', 'we_c']);

        $deleted = [];
        $this->api->expects($this->exactly(3))
            ->method('delete')
            ->willReturnCallback(function (string $apiKey, string $id) use (&$deleted): void {
                $this->assertSame(self::ACCESS_TOKEN, $apiKey);
                $deleted[] = $id;
            });

        $count = $this->registrar->clearAll(self::ACCESS_TOKEN, self::WEBHOOK_URL);

        $this->assertSame(3, $count);
        $this->assertSame(['we_a', 'we_b', 'we_c'], $deleted);
    }

    public function testClearAllReturnsZeroWhenNothingToDelete(): void
    {
        $this->api->method('listAll')->willReturn([]);
        $this->api->expects($this->never())->method('delete');

        $this->assertSame(0, $this->registrar->clearAll(self::ACCESS_TOKEN, self::WEBHOOK_URL));
    }
}
