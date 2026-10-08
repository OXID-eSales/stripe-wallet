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

/**
 * Found on the dev shop (GRAPH-QL PS7): "Create webhooks" demanded a platform key and
 * registered a Connect webhook on whatever account that key belonged to - an account that
 * did not control the shop's account, so the endpoint registered fine and never received
 * an event. The shop's own key was never tried, and a connected account was told nothing.
 * registerForShop() does what Stripe allows for this shop and otherwise says what to do.
 */
final class WebhookEndpointRegistrarForShopTest extends TestCase
{
    private const URL = 'https://shop.example/index.php?cl=StripeWebhookController';

    private StripeWebhookEndpointApiInterface&MockObject $api;
    private WebhookEndpointRegistrar $registrar;

    protected function setUp(): void
    {
        $this->api = $this->createMock(StripeWebhookEndpointApiInterface::class);
        $this->registrar = new WebhookEndpointRegistrar($this->api, new WebhookEventCatalog());
    }

    public function testAnOrdinaryAccountGetsAPlainEndpointWithItsOwnKey(): void
    {
        $this->api->expects($this->once())->method('create')
            ->with('sk_shop', self::URL, $this->anything(), 'desc', false)
            ->willReturn(new WebhookEndpointRegistrationResult('we_1', 'whsec_1'));
        $this->api->expects($this->never())->method('accountId');

        $result = $this->registrar->registerForShop('sk_shop', 'sk_platform', self::URL, null, 'desc');

        self::assertSame('we_1', $result->endpointId);
        self::assertSame('whsec_1', $result->secret);
    }

    public function testAConnectedAccountWhosePlatformControlsItGetsAConnectWebhookOnThePlatform(): void
    {
        $this->api->expects($this->exactly(2))->method('create')
            ->willReturnCallback(function (string $key, string $url, array $events, string $desc, bool $connect) {
                if ($key === 'sk_shop') {
                    throw WebhookRegistrationException::fromApiError('', 'You are not permitted to configure webhook endpoints on a connected account.');
                }
                self::assertSame(['sk_platform', true], [$key, $connect]);

                return new WebhookEndpointRegistrationResult('we_connect', 'whsec_c');
            });
        $this->api->method('accountId')->willReturnMap([['sk_shop', 'acct_shop'], ['sk_platform', 'acct_platform']]);
        $this->api->expects($this->once())->method('platformControlsAccount')->with('sk_platform', 'acct_shop')->willReturn(true);

        $result = $this->registrar->registerForShop('sk_shop', 'sk_platform', self::URL, null, 'desc');

        self::assertSame('we_connect', $result->endpointId);
    }

    public function testAConnectedAccountWithAPlatformThatDoesNotControlItIsToldSoAndWhatToDo(): void
    {
        $this->api->method('create')->willThrowException(
            WebhookRegistrationException::fromApiError('', 'You are not permitted to configure webhook endpoints on a connected account.')
        );
        $this->api->method('accountId')->willReturnMap([['sk_shop', 'acct_shop'], ['sk_platform', 'acct_other']]);
        $this->api->method('platformControlsAccount')->willReturn(false);

        try {
            $this->registrar->registerForShop('sk_shop', 'sk_platform', self::URL, null, 'desc');
            self::fail('a Connect webhook on an unrelated account would never fire');
        } catch (WebhookRegistrationException $e) {
            self::assertStringContainsString('acct_other', $e->getMessage());
            self::assertStringContainsString('does not control your account acct_shop', $e->getMessage());
            self::assertStringContainsString(self::URL, $e->getMessage());
            self::assertStringContainsString('checkout.session.completed', $e->getMessage());
            self::assertStringContainsString('Dashboard', $e->getMessage());
        }
    }

    public function testAConnectedAccountWithoutAPlatformKeyIsSentToItsDashboard(): void
    {
        $this->api->method('create')->willThrowException(
            WebhookRegistrationException::fromApiError('', 'You are not permitted to configure webhook endpoints on a connected account.')
        );
        $this->api->method('accountId')->willReturn('acct_shop');
        $this->api->expects($this->never())->method('platformControlsAccount');

        $this->expectException(WebhookRegistrationException::class);
        $this->expectExceptionMessage('acct_shop is a connected account');
        $this->registrar->registerForShop('sk_shop', '', self::URL, null, 'desc');
    }

    public function testAnUnrelatedStripeErrorIsNotRetriedOnThePlatform(): void
    {
        $this->api->method('create')->willThrowException(WebhookRegistrationException::fromApiError('rate_limit', 'Too many requests'));
        $this->api->expects($this->never())->method('accountId');

        $this->expectExceptionMessage('rate_limit');
        $this->registrar->registerForShop('sk_shop', 'sk_platform', self::URL, null, 'desc');
    }

    public function testAnEndpointIdFromTheOldPlatformFlowIsUpdatedOnThePlatformWhenTheAccountDoesNotKnowIt(): void
    {
        $this->api->method('update')->willReturnCallback(function (string $key, string $id) {
            if ($key === 'sk_shop') {
                throw WebhookRegistrationException::fromApiError('resource_missing', 'No such webhook endpoint: we_old');
            }

            return new WebhookEndpointRegistrationResult($id, null);
        });
        $this->api->method('accountId')->willReturnMap([['sk_shop', 'acct_shop'], ['sk_platform', 'acct_platform']]);
        $this->api->method('platformControlsAccount')->willReturn(true);

        $result = $this->registrar->registerForShop('sk_shop', 'sk_platform', self::URL, 'we_old', 'desc');

        self::assertSame('we_old', $result->endpointId);
        self::assertNull($result->secret, 'Stripe does not re-emit the secret on update');
    }
}
