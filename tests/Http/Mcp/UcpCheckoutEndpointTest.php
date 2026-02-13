<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Http\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests UCP REST endpoints via actual HTTP to the running shop.
 *
 * @group sprint-54
 * @group mcp-http
 * @group ucp
 */
final class UcpCheckoutEndpointTest extends TestCase
{
    private AgentTestHelper $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $shopUrl = getenv('SHOP_URL');
        $apiKey = getenv('STRIPE_AGENT_API_KEY');

        if (empty($shopUrl) || empty($apiKey)) {
            $this->markTestSkipped('SHOP_URL or STRIPE_AGENT_API_KEY not set');
        }

        $this->agent = new AgentTestHelper($shopUrl, $apiKey);
    }

    public function testUcpCreateAndGetCheckout(): void
    {
        // POST /checkout — create
        $create = $this->agent->ucpRequest('POST', '/checkout', [
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'ucp-http@example.com'],
            'currency' => 'EUR',
        ]);

        $this->assertSame(201, $create['httpCode']);
        $this->assertArrayHasKey('id', $create['body']);
        $checkoutId = $create['body']['id'];

        // GET /checkout/{id}
        $get = $this->agent->ucpRequest('GET', '/checkout/' . $checkoutId);
        $this->assertSame(200, $get['httpCode']);
        $this->assertSame($checkoutId, $get['body']['id']);
    }

    public function testUcpCancelCheckout(): void
    {
        $create = $this->agent->ucpRequest('POST', '/checkout', [
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'ucp-cancel@example.com'],
        ]);
        $checkoutId = $create['body']['id'];

        $cancel = $this->agent->ucpRequest('POST', '/checkout/' . $checkoutId . '/cancel');
        $this->assertSame(200, $cancel['httpCode']);
        $this->assertSame('canceled', $cancel['body']['status']);
    }

    private function getTestArticleId(): string
    {
        return 'dc5ffdf380e15674b56dd562a7cb6aec';
    }
}
