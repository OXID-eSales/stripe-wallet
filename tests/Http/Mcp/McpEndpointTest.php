<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Http\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests MCP JSON-RPC via actual HTTP POST to the running shop.
 *
 * @group sprint-54
 * @group mcp-http
 */
final class McpEndpointTest extends TestCase
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

    public function testInitializeHandshake(): void
    {
        $response = $this->agent->initialize();

        $this->assertSame(200, $response['httpCode']);
        $this->assertSame('2.0', $response['body']['jsonrpc']);
        $this->assertArrayHasKey('result', $response['body']);
        $this->assertSame('2025-06-18', $response['body']['result']['protocolVersion']);
    }

    public function testUnauthorizedWithoutToken(): void
    {
        $shopUrl = getenv('SHOP_URL') ?: 'http://localhost';
        $agent = new AgentTestHelper($shopUrl, '');

        $response = $agent->initialize();
        $this->assertSame(401, $response['httpCode']);
    }

    public function testToolDiscoveryReturns6Tools(): void
    {
        $response = $this->agent->listTools();

        $this->assertSame(200, $response['httpCode']);
        $tools = $response['body']['result']['tools'];
        $this->assertCount(6, $tools);
    }

    public function testFullMcpCheckoutLifecycle(): void
    {
        // 1. Initialize
        $init = $this->agent->initialize();
        $this->assertSame(200, $init['httpCode']);

        // 2. List products
        $products = $this->agent->callTool('list_products', ['limit' => 3]);
        $this->assertSame(200, $products['httpCode']);
        $content = json_decode($products['body']['result']['content'][0]['text'], true);

        if (empty($content['products'])) {
            $this->markTestSkipped('No products in test database');
        }

        $productId = $content['products'][0]['id'];

        // 3. Create checkout
        $checkout = $this->agent->callTool('create_checkout', [
            'items' => [['id' => $productId, 'quantity' => 1]],
            'buyer' => ['email' => 'http-test@example.com', 'first_name' => 'HTTP', 'last_name' => 'Test'],
        ]);
        $this->assertSame(200, $checkout['httpCode']);
        $checkoutData = json_decode($checkout['body']['result']['content'][0]['text'], true);
        $this->assertArrayHasKey('id', $checkoutData);

        // 4. Get checkout
        $get = $this->agent->callTool('get_checkout', ['checkout_id' => $checkoutData['id']]);
        $this->assertSame(200, $get['httpCode']);

        // 5. Cancel checkout
        $cancel = $this->agent->callTool('cancel_checkout', ['checkout_id' => $checkoutData['id']]);
        $this->assertSame(200, $cancel['httpCode']);
        $cancelData = json_decode($cancel['body']['result']['content'][0]['text'], true);
        $this->assertSame('canceled', $cancelData['status']);
    }
}
