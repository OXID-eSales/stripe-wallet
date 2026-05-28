<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Adapter\StripeClientProviderInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeWebhookEndpointApi;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Collection;
use Stripe\ErrorObject;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\WebhookEndpointService;
use Stripe\StripeClient;
use Stripe\WebhookEndpoint;

/**
 * S6 (sprint-114.11a): StripeWebhookEndpointApi must use the injected
 * StripeClientProviderInterface (with pinned stripe_version) instead of
 * constructing a bare StripeClient internally.
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\StripeWebhookEndpointApi
 */
final class StripeWebhookEndpointApiTest extends TestCase
{
    private StripeClientProviderInterface&MockObject $clientProvider;
    private StripeClient&MockObject $stripeClient;
    private WebhookEndpointService&MockObject $webhookEndpoints;
    private StripeWebhookEndpointApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webhookEndpoints = $this->createMock(WebhookEndpointService::class);

        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->stripeClient->webhookEndpoints = $this->webhookEndpoints;

        $this->clientProvider = $this->createMock(StripeClientProviderInterface::class);

        $this->api = new StripeWebhookEndpointApi($this->clientProvider);
    }

    // -------------------------------------------------------------------------
    // Factory injection contract
    // -------------------------------------------------------------------------

    public function testAcceptsClientProviderAsConstructorArgument(): void
    {
        // If the constructor signature does not accept StripeClientProviderInterface,
        // the setUp() will have already thrown; this assertion is the proof.
        $this->assertInstanceOf(StripeWebhookEndpointApi::class, $this->api);
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateUsesClientProviderForKeyToObtainClient(): void
    {
        $apiKey = 'sk_test_create';

        $this->clientProvider
            ->expects($this->once())
            ->method('forKey')
            ->with($apiKey)
            ->willReturn($this->stripeClient);

        $endpoint = $this->buildEndpointObject('we_test_create', 'whsec_create');
        $this->webhookEndpoints->method('create')->willReturn($endpoint);

        $this->api->create($apiKey, 'https://shop.example.com/webhook', ['payment_intent.succeeded'], 'Test');
    }

    public function testCreateReturnsRegistrationResultWithEndpointIdAndSecret(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);

        $endpoint = $this->buildEndpointObject('we_test_result', 'whsec_result');
        $this->webhookEndpoints->method('create')->willReturn($endpoint);

        $result = $this->api->create('sk_test', 'https://shop.example.com/webhook', ['payment_intent.succeeded'], 'T');

        $this->assertSame('we_test_result', $result->endpointId);
        $this->assertSame('whsec_result', $result->secret);
    }

    public function testCreateSetsConnectFlagWhenRequested(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);

        $this->webhookEndpoints
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $payload): bool {
                return isset($payload['connect']) && $payload['connect'] === true;
            }))
            ->willReturn($this->buildEndpointObject('we_connect', 'whsec_connect'));

        $this->api->create('sk_test', 'https://shop.example.com/webhook', ['*'], 'T', isConnect: true);
    }

    public function testCreateOmitsConnectFlagWhenNotRequested(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);

        $this->webhookEndpoints
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $payload): bool {
                return !array_key_exists('connect', $payload);
            }))
            ->willReturn($this->buildEndpointObject('we_noc', null));

        $this->api->create('sk_test', 'https://shop.example.com/webhook', ['*'], 'T', isConnect: false);
    }

    public function testCreateWrapsApiErrorExceptionInDomainException(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);
        $this->webhookEndpoints->method('create')->willThrowException($this->buildApiError());

        $this->expectException(WebhookRegistrationException::class);
        $this->api->create('sk_test', 'https://shop.example.com/webhook', ['*'], 'T');
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function testUpdateUsesClientProviderForKeyToObtainClient(): void
    {
        $apiKey = 'sk_test_update';

        $this->clientProvider
            ->expects($this->once())
            ->method('forKey')
            ->with($apiKey)
            ->willReturn($this->stripeClient);

        $endpoint = $this->buildEndpointObject('we_upd', null);
        $this->webhookEndpoints->method('update')->willReturn($endpoint);

        $this->api->update($apiKey, 'we_upd', 'https://shop.example.com/webhook', ['*'], 'T');
    }

    public function testUpdateReturnsResultWithNullSecret(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);
        $this->webhookEndpoints
            ->method('update')
            ->willReturn($this->buildEndpointObject('we_upd', 'should_be_ignored'));

        $result = $this->api->update('sk_test', 'we_upd', 'https://shop.example.com/webhook', ['*'], 'T');

        $this->assertSame('we_upd', $result->endpointId);
        $this->assertNull($result->secret);
    }

    public function testUpdateWrapsApiErrorExceptionInDomainException(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);
        $this->webhookEndpoints->method('update')->willThrowException($this->buildApiError());

        $this->expectException(WebhookRegistrationException::class);
        $this->api->update('sk_test', 'we_upd', 'https://shop.example.com/webhook', ['*'], 'T');
    }

    // -------------------------------------------------------------------------
    // listAll()
    // -------------------------------------------------------------------------

    public function testListAllUsesClientProviderForKeyToObtainClient(): void
    {
        $apiKey = 'sk_test_list';

        $this->clientProvider
            ->expects($this->once())
            ->method('forKey')
            ->with($apiKey)
            ->willReturn($this->stripeClient);

        $this->webhookEndpoints->method('all')->willReturn($this->buildCollection([]));

        $this->api->listAll($apiKey);
    }

    public function testListAllReturnsEndpointIds(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);

        $ep1 = $this->buildEndpointObject('we_1', null, 'https://shop.example.com/webhook');
        $ep2 = $this->buildEndpointObject('we_2', null, 'https://shop.example.com/webhook');
        $this->webhookEndpoints->method('all')->willReturn($this->buildCollection([$ep1, $ep2]));

        $ids = $this->api->listAll('sk_test');

        $this->assertSame(['we_1', 'we_2'], $ids);
    }

    public function testListAllFiltersEndpointsByUrl(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);

        $matchingEp  = $this->buildEndpointObject('we_match', null, 'https://shop.example.com/webhook');
        $differentEp = $this->buildEndpointObject('we_other', null, 'https://other-shop.example.com/webhook');
        $this->webhookEndpoints->method('all')->willReturn($this->buildCollection([$matchingEp, $differentEp]));

        $ids = $this->api->listAll('sk_test', 'https://shop.example.com/webhook');

        $this->assertSame(['we_match'], $ids);
    }

    public function testListAllWrapsApiErrorExceptionInDomainException(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);
        $this->webhookEndpoints->method('all')->willThrowException($this->buildApiError());

        $this->expectException(WebhookRegistrationException::class);
        $this->api->listAll('sk_test');
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    public function testDeleteUsesClientProviderForKeyToObtainClient(): void
    {
        $apiKey = 'sk_test_delete';

        $this->clientProvider
            ->expects($this->once())
            ->method('forKey')
            ->with($apiKey)
            ->willReturn($this->stripeClient);

        $this->webhookEndpoints->method('delete')->willReturn($this->buildEndpointObject('we_del', null));

        $this->api->delete($apiKey, 'we_del');
    }

    public function testDeleteWrapsApiErrorExceptionInDomainException(): void
    {
        $this->clientProvider->method('forKey')->willReturn($this->stripeClient);
        $this->webhookEndpoints->method('delete')->willThrowException($this->buildApiError());

        $this->expectException(WebhookRegistrationException::class);
        $this->api->delete('sk_test', 'we_del');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildEndpointObject(string $id, ?string $secret, string $url = 'https://shop.example.com/webhook'): WebhookEndpoint
    {
        return WebhookEndpoint::constructFrom([
            'id'     => $id,
            'secret' => $secret,
            'url'    => $url,
            'object' => 'webhook_endpoint',
        ]);
    }

    /**
     * @param list<WebhookEndpoint> $items
     */
    private function buildCollection(array $items): Collection
    {
        $data = array_map(
            static fn(WebhookEndpoint $ep): array => $ep->toArray(),
            $items
        );

        return Collection::constructFrom([
            'object'   => 'list',
            'data'     => $data,
            'has_more' => false,
            'url'      => '/v1/webhook_endpoints',
        ]);
    }

    private function buildApiError(): ApiErrorException
    {
        return InvalidRequestException::factory(
            'Test API error',
            400,
            '{}',
            null,
            null
        );
    }
}
