<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\E2e\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\FeatherlessClient;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\LlmToolExecutor;
use PHPUnit\Framework\TestCase;

/**
 * A real LLM is given the shop's MCP tool schemas and asked to find products.
 * The test verifies that the LLM autonomously calls list_products.
 *
 * @group sprint-54
 * @group mcp-e2e
 * @group llm
 */
final class LlmProductDiscoveryTest extends TestCase
{
    private FeatherlessClient $llm;
    private AgentTestHelper $agent;
    private LlmToolExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $apiKey = getenv('FEATHERLESS_API_KEY');
        $shopUrl = getenv('SHOP_URL');
        $agentApiKey = getenv('STRIPE_AGENT_API_KEY');

        if (empty($apiKey) || getenv('LLM_E2E_SKIP') === 'true') {
            $this->markTestSkipped('FEATHERLESS_API_KEY not set or LLM_E2E_SKIP=true');
        }
        if (empty($shopUrl) || empty($agentApiKey)) {
            $this->markTestSkipped('SHOP_URL or STRIPE_AGENT_API_KEY not set');
        }

        $this->llm = new FeatherlessClient(
            getenv('FEATHERLESS_API_URL') ?: 'https://api.featherless.ai/v1',
            $apiKey,
            getenv('FEATHERLESS_MODEL') ?: 'Qwen/Qwen2.5-72B-Instruct',
            (int) (getenv('LLM_E2E_TIMEOUT') ?: 120)
        );

        $this->agent = new AgentTestHelper($shopUrl, $agentApiKey);
        $this->executor = new LlmToolExecutor($this->agent);
    }

    public function testLlmFindsProductsUsingMcpTools(): void
    {
        // 1. Get tool schemas from shop
        $toolsResponse = $this->agent->listTools();
        $this->assertSame(200, $toolsResponse['httpCode']);
        $tools = $this->convertMcpToolsToOpenAiFormat($toolsResponse['body']['result']['tools']);

        // 2. Start LLM conversation
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a shopping assistant. Use the available tools to help the user. '
                    . 'Always use tools when asked about products.',
            ],
            [
                'role' => 'user',
                'content' => 'What products do you have available? Show me a few.',
            ],
        ];

        // 3. LLM decides to call list_products
        $response = $this->llm->chatCompletion($messages, $tools);

        $this->assertNotNull($response['tool_calls'], 'LLM should have called a tool');
        $this->assertGreaterThan(0, count($response['tool_calls']));

        $calledToolNames = array_map(
            fn($tc) => $tc['function']['name'],
            $response['tool_calls']
        );
        $this->assertContains('list_products', $calledToolNames);

        // 4. Execute tool calls against shop
        $toolResults = $this->executor->executeAll($response['tool_calls']);
        $this->assertNotEmpty($toolResults);

        // 5. Verify tool result contains products
        $firstResult = json_decode($toolResults[0]['content'], true);
        $this->assertArrayHasKey('products', $firstResult);
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}> $mcpTools
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function convertMcpToolsToOpenAiFormat(array $mcpTools): array
    {
        return array_map(fn(array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['inputSchema'],
            ],
        ], $mcpTools);
    }
}
