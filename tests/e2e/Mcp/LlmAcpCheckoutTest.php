<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\e2e\Mcp;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\FeatherlessClient;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\LlmToolExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Full E2E: LLM autonomously discovers products, creates a checkout,
 * and the test verifies the contract state in the database.
 *
 * @group sprint-54
 * @group mcp-e2e
 * @group llm
 */
final class LlmAcpCheckoutTest extends TestCase
{
    private FeatherlessClient $llm;
    private AgentTestHelper $agent;
    private LlmToolExecutor $executor;
    private ContractRepositoryInterface $contractRepository;

    /** @var list<array{type: string, function: array<string, mixed>}> */
    private array $tools;

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

        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->contractRepository = $container->get(ContractRepositoryInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Contract repository not available: ' . $e->getMessage());
        }

        // Pre-fetch tool schemas
        $toolsResponse = $this->agent->listTools();
        $this->tools = $this->convertMcpToolsToOpenAiFormat($toolsResponse['body']['result']['tools']);
    }

    public function testLlmCreatesCheckoutAutonomously(): void
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a shopping agent. Use the tools to: 1) Find products, '
                    . '2) Create a checkout with the first product found. '
                    . 'Use buyer email "llm-e2e@example.com", first_name "LLM", last_name "Test".',
            ],
            [
                'role' => 'user',
                'content' => 'Buy me the first product you can find.',
            ],
        ];

        $checkoutId = null;
        $maxTurns = 6;
        $trace = [];

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = $this->llm->chatCompletion($messages, $this->tools);

            $turnTrace = ['turn' => $turn, 'finish_reason' => $response['finish_reason']];

            if (empty($response['tool_calls'])) {
                $turnTrace['action'] = 'no_tool_calls';
                $turnTrace['content'] = $response['content'];
                $trace[] = $turnTrace;
                if ($response['content'] !== null) {
                    preg_match('/[a-f0-9]{32}/', $response['content'], $matches);
                    if (!empty($matches)) {
                        $checkoutId = $matches[0];
                    }
                }
                break;
            }

            $toolNames = array_map(fn($tc) => $tc['function']['name'], $response['tool_calls']);
            $toolArgs = array_map(fn($tc) => $tc['function']['arguments'], $response['tool_calls']);
            $turnTrace['action'] = 'tool_calls';
            $turnTrace['tools'] = $toolNames;
            $turnTrace['arguments'] = $toolArgs;

            $assistantMsg = [
                'role' => 'assistant',
                'content' => $response['content'],
                'tool_calls' => $response['tool_calls'],
            ];
            if (isset($response['reasoning_content'])) {
                $assistantMsg['reasoning_content'] = $response['reasoning_content'];
            }
            $messages[] = $assistantMsg;

            $toolResults = $this->executor->executeAll($response['tool_calls']);
            $turnTrace['results'] = array_map(fn($r) => mb_substr($r['content'], 0, 500), $toolResults);

            foreach ($toolResults as $result) {
                $messages[] = $result;

                $decoded = json_decode($result['content'], true);
                if (is_array($decoded) && isset($decoded['id']) && isset($decoded['status'])) {
                    $checkoutId = $decoded['id'];
                }
            }

            $trace[] = $turnTrace;
        }

        $traceJson = json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertNotNull($checkoutId, "LLM should have created a checkout.\nTrace:\n{$traceJson}");

        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertNotNull($contract, 'Contract should exist in DB');

        $agentId = $contract->getMetadata('acp_agent_id');
        $this->assertNotNull($agentId, 'Contract should have acp_agent_id metadata');
    }

    public function testLlmCreatesAndCancelsCheckout(): void
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a shopping agent. Use the tools to: 1) Find products, '
                    . '2) Create a checkout, 3) Then cancel it immediately. '
                    . 'Use buyer email "llm-cancel@example.com".',
            ],
            [
                'role' => 'user',
                'content' => 'Create a checkout for any product, then cancel it right away.',
            ],
        ];

        $checkoutId = null;
        $wasCancelled = false;
        $maxTurns = 8;
        $trace = [];

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = $this->llm->chatCompletion($messages, $this->tools);

            $turnTrace = ['turn' => $turn, 'finish_reason' => $response['finish_reason']];

            if (empty($response['tool_calls'])) {
                $turnTrace['action'] = 'no_tool_calls';
                $turnTrace['content'] = $response['content'];
                $trace[] = $turnTrace;
                break;
            }

            $toolNames = array_map(fn($tc) => $tc['function']['name'], $response['tool_calls']);
            $toolArgs = array_map(fn($tc) => $tc['function']['arguments'], $response['tool_calls']);
            $turnTrace['action'] = 'tool_calls';
            $turnTrace['tools'] = $toolNames;
            $turnTrace['arguments'] = $toolArgs;

            $assistantMsg = [
                'role' => 'assistant',
                'content' => $response['content'],
                'tool_calls' => $response['tool_calls'],
            ];
            if (isset($response['reasoning_content'])) {
                $assistantMsg['reasoning_content'] = $response['reasoning_content'];
            }
            $messages[] = $assistantMsg;

            foreach ($response['tool_calls'] as $tc) {
                if ($tc['function']['name'] === 'cancel_checkout') {
                    $wasCancelled = true;
                }
            }

            $toolResults = $this->executor->executeAll($response['tool_calls']);
            $turnTrace['results'] = array_map(fn($r) => mb_substr($r['content'], 0, 500), $toolResults);

            foreach ($toolResults as $result) {
                $messages[] = $result;

                $decoded = json_decode($result['content'], true);
                if (is_array($decoded) && isset($decoded['id']) && !$wasCancelled) {
                    $checkoutId = $decoded['id'];
                }
            }

            $trace[] = $turnTrace;
        }

        $traceJson = json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertNotNull($checkoutId, "LLM should have created a checkout.\nTrace:\n{$traceJson}");
        $this->assertTrue($wasCancelled, "LLM should have called cancel_checkout.\nTrace:\n{$traceJson}");

        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertNotNull($contract);
        $this->assertSame('cancelled', $contract->getStateValue());
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}> $mcpTools
     * @return list<array{type: string, function: array<string, mixed>}>
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
