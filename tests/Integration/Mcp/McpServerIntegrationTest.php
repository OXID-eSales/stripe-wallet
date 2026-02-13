<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;
use OxidEsales\PaymentComponent\Mcp\McpServerInterface;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\McpRequestBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the MCP server with real DI container and database.
 *
 * @group sprint-54
 * @group mcp-integration
 */
final class McpServerIntegrationTest extends TestCase
{
    private McpServerInterface $server;
    private AgentContextInterface $agentContext;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->server = $container->get(McpServerInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MCP server not available in DI container: ' . $e->getMessage());
        }

        $this->agentContext = new AgentContext('integration-test-agent', 'test-token');
    }

    public function testInitializeReturnsServerInfo(): void
    {
        $response = $this->server->handleJsonRpc(
            McpRequestBuilder::initialize(),
            $this->agentContext
        );

        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertArrayHasKey('result', $response);
        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
        $this->assertSame('oxid-stripe-acp', $response['result']['serverInfo']['name']);
    }

    public function testToolsListReturns6Tools(): void
    {
        $response = $this->server->handleJsonRpc(
            McpRequestBuilder::toolsList(),
            $this->agentContext
        );

        $tools = $response['result']['tools'];
        $toolNames = array_column($tools, 'name');

        $this->assertCount(6, $tools);
        $this->assertContains('create_checkout', $toolNames);
        $this->assertContains('get_checkout', $toolNames);
        $this->assertContains('update_checkout', $toolNames);
        $this->assertContains('complete_checkout', $toolNames);
        $this->assertContains('cancel_checkout', $toolNames);
        $this->assertContains('list_products', $toolNames);
    }

    public function testEachToolHasValidInputSchema(): void
    {
        $response = $this->server->handleJsonRpc(
            McpRequestBuilder::toolsList(),
            $this->agentContext
        );

        foreach ($response['result']['tools'] as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function testListProductsReturnsProducts(): void
    {
        $response = $this->server->handleJsonRpc(
            McpRequestBuilder::toolsCall('list_products', ['limit' => 5]),
            $this->agentContext
        );

        $this->assertArrayHasKey('result', $response);
        $content = json_decode($response['result']['content'][0]['text'], true);
        $this->assertArrayHasKey('products', $content);
        $this->assertIsArray($content['products']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $response = $this->server->handleJsonRpc(
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'unknown/method']),
            $this->agentContext
        );

        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32601, $response['error']['code']);
    }
}
