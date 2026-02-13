# Sprint 54: MCP/ACP Integration Tests + LLM E2E

**Date:** 2026-02-12
**Status:** TODO
**Priority:** High
**Prerequisites:** Sprint 47 completed (MCP/ACP/UCP infrastructure)
**Principle:** Test the full MCP/ACP stack at three levels — unit (already done), integration (PHP service layer + DB), and E2E (real LLM → HTTP → shop). All tests are PHPUnit inside Docker. LLM E2E uses Featherless headless inference.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Tests ARE the deliverable |
| SOLID | Test classes organized by layer: Unit / Integration / E2E |
| DI | Integration tests resolve services from real DI container |
| LSP | Same checkout lifecycle works via MCP JSON-RPC and UCP REST |
| DRY | Shared fixtures (`AgentTestHelper`, `McpRequestBuilder`) |
| No Overengineering | PHPUnit only — no Playwright, no external test runners |
| Clean Code | AAA pattern, descriptive names, no magic values |

---

## Decisions (from Q&A)

| Question | Answer | Implication |
|----------|--------|-------------|
| Scope | Both layers | Tests cover payment-component ACP services AND Stripe SPT flow |
| LLM E2E | Real LLM in CI | PHPUnit test calls Featherless API → LLM decides which tools to call → tools hit shop HTTP |
| Model | Configurable | `FEATHERLESS_MODEL` env var, default `Qwen/Qwen2.5-72B-Instruct` |
| Runtime | Inside Docker | All tests run via `docker compose exec php php vendor/bin/phpunit` |
| Transport | HTTP to localhost | E2E tests POST to `http://localhost/?cl=stripemcp` from inside Docker container |

---

## Environment Variables

```bash
# === Required for Integration Tests ===
SHOP_URL=http://localhost                    # Shop URL reachable from inside Docker
STRIPE_AGENT_API_KEY=test-agent-key-e2e      # Must match sStripeAgentApiKey in OXID admin

# === Required for LLM E2E Tests ===
FEATHERLESS_API_KEY=<your-featherless-key>   # Featherless.ai API key (CI secret)
FEATHERLESS_API_URL=https://api.featherless.ai/v1  # Featherless OpenAI-compatible endpoint
FEATHERLESS_MODEL=Qwen/Qwen2.5-72B-Instruct # Model ID (configurable, free tier)

# === Optional ===
STRIPE_TEST_SECRET_KEY=sk_test_...           # For SPT payment confirmation tests
LLM_E2E_TIMEOUT=120                          # Max seconds for LLM conversation (default: 120)
LLM_E2E_SKIP=false                           # Set to "true" to skip LLM E2E in local dev
```

**CI Setup:** Add `FEATHERLESS_API_KEY` and `STRIPE_TEST_SECRET_KEY` as repository secrets. The PHPUnit test skips gracefully if `FEATHERLESS_API_KEY` is not set.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  Test Levels                                                     │
│                                                                  │
│  Level 1: Integration (PHP)                                      │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ PHPUnit → AcpCheckoutServiceInterface → DB              │    │
│  │ PHPUnit → McpServer::handleJsonRpc() → Tools → DB       │    │
│  │ PHPUnit → SptPaymentService → Stripe test API           │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  Level 2: HTTP Integration                                       │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ PHPUnit → curl POST http://localhost/?cl=stripemcp      │    │
│  │ PHPUnit → curl POST http://localhost/?cl=stripeucp      │    │
│  │ PHPUnit → curl GET  http://localhost/?cl=stripeucpprofile│    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  Level 3: LLM E2E                                                │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │ PHPUnit → Featherless API (OpenAI-compatible)           │    │
│  │         → LLM receives tool schemas from shop           │    │
│  │         → LLM autonomously calls: list_products →       │    │
│  │           create_checkout → complete_checkout            │    │
│  │         → PHPUnit asserts on final contract state        │    │
│  └─────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Test File Structure

```
tests/
├── Integration/
│   └── Mcp/
│       ├── AcpCheckoutFlowTest.php          # Contract lifecycle via service layer
│       ├── McpServerIntegrationTest.php      # MCP server with real DI + DB
│       ├── UcpCheckoutFlowTest.php           # UCP REST routing via handler
│       └── SptPaymentServiceTest.php         # SPT confirmation against Stripe test API
├── Http/
│   └── Mcp/
│       ├── McpEndpointTest.php               # MCP JSON-RPC via HTTP
│       ├── UcpCheckoutEndpointTest.php       # UCP REST via HTTP
│       └── UcpProfileEndpointTest.php        # UCP profile via HTTP
├── E2e/
│   └── Mcp/
│       ├── LlmAcpCheckoutTest.php            # Real LLM → MCP → checkout lifecycle
│       └── LlmProductDiscoveryTest.php       # Real LLM → MCP → product search
└── Fixture/
    └── Mcp/
        ├── AgentTestHelper.php               # HTTP client for agent simulation
        ├── McpRequestBuilder.php             # JSON-RPC request factory
        ├── FeatherlessClient.php             # OpenAI-compatible LLM client
        └── LlmToolExecutor.php              # Bridges LLM tool_calls → shop HTTP
```

---

## Part A: Test Fixtures

### A1: AgentTestHelper

HTTP client that simulates an AI agent calling MCP/UCP endpoints.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture\Mcp;

class AgentTestHelper
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $bearerToken
    ) {
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function mcpRequest(string $method, array $params = [], int $id = 1): array
    {
        return $this->httpPost(
            $this->baseUrl . '/?cl=stripemcp',
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => $method,
                'params' => $params,
            ]
        );
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function initialize(): array
    {
        return $this->mcpRequest('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-agent', 'version' => '1.0.0'],
        ]);
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function listTools(): array
    {
        return $this->mcpRequest('tools/list');
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function callTool(string $toolName, array $arguments = []): array
    {
        return $this->mcpRequest('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    public function ucpRequest(string $method, string $path, array $body = []): array
    {
        $url = $this->baseUrl . '/?cl=stripeucp' . $path;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->bearerToken,
            'Request-Id: ' . uniqid('test-', true),
        ];

        return match ($method) {
            'POST' => $this->doRequest('POST', $url, $body, $headers),
            'GET' => $this->doRequest('GET', $url, [], $headers),
            'PUT' => $this->doRequest('PUT', $url, $body, $headers),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    /** @return array{httpCode: int, body: array<string, mixed>} */
    private function httpPost(string $url, array $body): array
    {
        return $this->doRequest('POST', $url, $body, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->bearerToken,
        ]);
    }

    /**
     * @param list<string> $headers
     * @return array{httpCode: int, body: array<string, mixed>}
     */
    private function doRequest(string $method, string $url, array $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['httpCode' => 0, 'body' => ['error' => 'curl_init failed']];
        }

        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'httpCode' => $httpCode,
            'body' => is_string($response) ? (json_decode($response, true) ?? []) : [],
        ];
    }
}
```

### A2: McpRequestBuilder

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture\Mcp;

class McpRequestBuilder
{
    public static function initialize(): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-agent', 'version' => '1.0.0'],
            ],
        ]);
    }

    public static function toolsList(): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);
    }

    public static function toolsCall(string $name, array $arguments): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }

    public static function createCheckout(array $items, array $buyer = []): string
    {
        return self::toolsCall('create_checkout', [
            'items' => $items,
            'buyer' => array_merge([
                'email' => 'agent-test@example.com',
                'first_name' => 'Test',
                'last_name' => 'Agent',
            ], $buyer),
            'currency' => 'EUR',
        ]);
    }
}
```

### A3: FeatherlessClient

OpenAI-compatible client for calling Featherless headless LLM inference.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture\Mcp;

/**
 * Minimal OpenAI-compatible client for Featherless headless inference.
 *
 * Supports tool_use (function calling) via the OpenAI chat completions API.
 * Featherless serves open-source models with OpenAI-compatible endpoints.
 */
class FeatherlessClient
{
    private string $apiUrl;
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $model,
        int $timeout = 120
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * Send a chat completion request with optional tools.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param list<array<string, mixed>> $tools OpenAI-format tool definitions
     * @return array{
     *     role: string,
     *     content: string|null,
     *     tool_calls: list<array{id: string, function: array{name: string, arguments: string}}>|null,
     *     finish_reason: string
     * }
     */
    public function chatCompletion(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.0,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] => 'auto';
        }

        $response = $this->post('/chat/completions', $payload);

        $choice = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        return [
            'role' => $message['role'] ?? 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? null,
            'finish_reason' => $choice['finish_reason'] ?? 'stop',
        ];
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        $ch = curl_init($this->apiUrl . $path);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Featherless API error: HTTP %d — %s',
                $httpCode,
                $error ?: (is_string($response) ? $response : 'empty response')
            ));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Featherless API returned invalid JSON');
        }

        return $decoded;
    }
}
```

### A4: LlmToolExecutor

Bridges LLM `tool_calls` responses to real HTTP calls against the shop.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture\Mcp;

/**
 * Executes LLM tool_calls by forwarding them as MCP JSON-RPC requests
 * to the shop's stripemcp endpoint.
 *
 * The LLM returns tool_calls in OpenAI format:
 *   { "id": "call_123", "function": { "name": "list_products", "arguments": "{...}" } }
 *
 * This class translates them to MCP JSON-RPC:
 *   { "jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": { "name": "...", "arguments": {...} } }
 *
 * And returns the result as an OpenAI tool message for the next conversation turn.
 */
class LlmToolExecutor
{
    public function __construct(
        private readonly AgentTestHelper $agent
    ) {
    }

    /**
     * Execute all tool_calls from an LLM response and return tool result messages.
     *
     * @param list<array{id: string, function: array{name: string, arguments: string}}> $toolCalls
     * @return list<array{role: string, tool_call_id: string, content: string}>
     */
    public function executeAll(array $toolCalls): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'] ?? '';
            $argumentsJson = $toolCall['function']['arguments'] ?? '{}';
            $callId = $toolCall['id'] ?? '';

            $arguments = json_decode($argumentsJson, true);
            if (!is_array($arguments)) {
                $arguments = [];
            }

            $mcpResponse = $this->agent->callTool($functionName, $arguments);
            $content = $mcpResponse['body']['result']['content'][0]['text']
                ?? json_encode($mcpResponse['body']);

            $results[] = [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'content' => is_string($content) ? $content : json_encode($content),
            ];
        }

        return $results;
    }
}
```

---

## Part B: Integration Tests (Level 1 — PHP Service Layer)

### B1: McpServerIntegrationTest

Tests the MCP server with real tool registry from DI container and real database.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\McpServerInterface;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\McpRequestBuilder;
use PHPUnit\Framework\TestCase;

class McpServerIntegrationTest extends TestCase
{
    private McpServerInterface $server;
    private AgentContext $agentContext;

    protected function setUp(): void
    {
        // Resolve from real DI container
        $this->server = $this->getContainer()->get(McpServerInterface::class);
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

    public function testToolsListReturnsAll6Tools(): void
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
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'unknown/method']),
            $this->agentContext
        );

        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32601, $response['error']['code']);
    }
}
```

### B2: AcpCheckoutFlowTest

Full ACP checkout lifecycle — create -> get -> update -> cancel — exercising the real contract repository and database.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\TestCase;

class AcpCheckoutFlowTest extends TestCase
{
    private AcpCheckoutServiceInterface $checkoutService;
    private ContractRepositoryInterface $contractRepository;
    private AgentContext $agentContext;

    protected function setUp(): void
    {
        $this->checkoutService = $this->getContainer()->get(AcpCheckoutServiceInterface::class);
        $this->contractRepository = $this->getContainer()->get(ContractRepositoryInterface::class);
        $this->agentContext = new AgentContext('e2e-test-agent', 'test-token');
    }

    public function testCreateCheckoutReturnsContractWithId(): void
    {
        $result = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('line_items', $result);
        $this->assertNotEmpty($result['line_items']);
    }

    public function testGetCheckoutReturnsExistingContract(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $retrieved = $this->checkoutService->getCheckout($created['id']);

        $this->assertSame($created['id'], $retrieved['id']);
        $this->assertArrayHasKey('line_items', $retrieved);
    }

    public function testGetCheckoutNotFoundReturnsError(): void
    {
        $result = $this->checkoutService->getCheckout('nonexistent-contract-id');

        $this->assertArrayHasKey('error', $result);
    }

    public function testUpdateCheckoutStoresMetadata(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $updated = $this->checkoutService->updateCheckout(
            $created['id'],
            ['selected_fulfillment_option_id' => 'shipping_standard'],
            $this->agentContext
        );

        $this->assertSame($created['id'], $updated['id']);

        // Verify metadata persisted in DB
        $contract = $this->contractRepository->findById($created['id']);
        $this->assertNotNull($contract);
        $this->assertSame(
            'shipping_standard',
            $contract->getMetadata('acp_selected_fulfillment_option_id')
        );
    }

    public function testCancelCheckoutTransitionsContract(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $cancelled = $this->checkoutService->cancelCheckout($created['id']);

        $this->assertSame('canceled', $cancelled['status']);

        // Verify contract state in DB
        $contract = $this->contractRepository->findById($created['id']);
        $this->assertSame('cancelled', $contract->getStateValue());
    }

    public function testDoubleCancelReturnsError(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $this->checkoutService->cancelCheckout($created['id']);
        $result = $this->checkoutService->cancelCheckout($created['id']);

        $this->assertArrayHasKey('error', $result);
    }

    public function testCompleteCheckoutWithoutTokenReturnsError(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $result = $this->checkoutService->completeCheckout(
            $created['id'],
            ['token' => '', 'provider' => 'stripe'],
            $this->agentContext
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function testAgentIdStoredInContractMetadata(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $contract = $this->contractRepository->findById($created['id']);
        $this->assertSame('e2e-test-agent', $contract->getMetadata('acp_agent_id'));
    }

    /** @return array<string, mixed> */
    private function createCheckoutArgs(): array
    {
        return [
            'items' => [
                ['id' => $this->getTestArticleId(), 'quantity' => 1],
            ],
            'buyer' => [
                'email' => 'integration-test@example.com',
                'first_name' => 'Integration',
                'last_name' => 'Test',
            ],
            'currency' => 'EUR',
        ];
    }

    private function getTestArticleId(): string
    {
        // Known OXID demo data article ID
        return 'dc5ffdf380e15674b56dd562a7cb6aec';  // OXID demo "Kuyichi Lederguertel"
    }
}
```

### B3: UcpCheckoutFlowTest

Tests UCP REST routing through the handler layer.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\Payments\Stripe\Mcp\Event\UcpCheckoutRequestEvent;
use OxidEsales\Payments\Stripe\Mcp\Handler\UcpCheckoutRequestHandler;
use PHPUnit\Framework\TestCase;

class UcpCheckoutFlowTest extends TestCase
{
    private UcpCheckoutRequestHandler $handler;
    private AcpCheckoutServiceInterface $checkoutService;
    private AgentContext $agentContext;

    protected function setUp(): void
    {
        $this->checkoutService = $this->getContainer()->get(AcpCheckoutServiceInterface::class);
        $this->handler = new UcpCheckoutRequestHandler($this->checkoutService);
        $this->agentContext = new AgentContext('ucp-test-agent', 'test-token');
    }

    public function testPostCheckoutCreatesSession(): void
    {
        $context = $this->dispatchUcpRequest('POST', ['checkout'], [
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'ucp-test@example.com'],
            'currency' => 'EUR',
        ]);

        $this->assertSame(201, $context->get('httpStatusCode'));
        $responseData = $context->get('responseData');
        $this->assertArrayHasKey('id', $responseData);
    }

    public function testGetCheckoutRetrievesSession(): void
    {
        // Create first
        $createCtx = $this->dispatchUcpRequest('POST', ['checkout'], [
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'ucp-test@example.com'],
        ]);
        $checkoutId = $createCtx->get('responseData')['id'];

        // GET
        $getCtx = $this->dispatchUcpRequest('GET', ['checkout', $checkoutId], []);

        $this->assertSame(200, $getCtx->get('httpStatusCode'));
        $this->assertSame($checkoutId, $getCtx->get('responseData')['id']);
    }

    public function testCancelCheckoutViaUcp(): void
    {
        $createCtx = $this->dispatchUcpRequest('POST', ['checkout'], [
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'ucp-test@example.com'],
        ]);
        $checkoutId = $createCtx->get('responseData')['id'];

        $cancelCtx = $this->dispatchUcpRequest('POST', ['checkout', $checkoutId, 'cancel'], []);

        $this->assertSame(200, $cancelCtx->get('httpStatusCode'));
        $this->assertSame('canceled', $cancelCtx->get('responseData')['status']);
    }

    public function testUnknownRouteReturns404(): void
    {
        $context = $this->dispatchUcpRequest('DELETE', ['checkout', 'some-id'], []);

        $this->assertSame(404, $context->get('httpStatusCode'));
    }

    private function dispatchUcpRequest(string $method, array $segments, array $body): EventContext
    {
        $context = new EventContext([
            'httpMethod' => $method,
            'pathSegments' => $segments,
            'requestBody' => $body,
            'agentContext' => $this->agentContext,
        ]);

        $event = new UcpCheckoutRequestEvent($context);
        $this->handler->handle($event);

        return $context;
    }

    private function getTestArticleId(): string
    {
        return 'dc5ffdf380e15674b56dd562a7cb6aec';
    }
}
```

### B4: SptPaymentServiceTest (Integration)

Tests real Stripe API calls with test keys. Skipped if `STRIPE_TEST_SECRET_KEY` is not set.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentServiceInterface;
use PHPUnit\Framework\TestCase;

class SptPaymentServiceTest extends TestCase
{
    private SptPaymentServiceInterface $sptService;

    protected function setUp(): void
    {
        $stripeKey = getenv('STRIPE_TEST_SECRET_KEY');
        if (empty($stripeKey)) {
            $this->markTestSkipped('STRIPE_TEST_SECRET_KEY not set — skipping SPT integration test');
        }

        $this->sptService = $this->getContainer()->get(SptPaymentServiceInterface::class);
    }

    public function testConfirmWithInvalidTokenReturnsFailure(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('test-contract-spt');
        $contract->method('getAmount')->willReturn(1999);
        $contract->method('getCurrency')->willReturn('EUR');

        $result = $this->sptService->confirmWithSpt($contract, 'spt_invalid_token', []);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->getErrorMessage());
    }
}
```

---

## Part C: HTTP Integration Tests (Level 2 — Real HTTP)

### C1: McpEndpointTest

Tests MCP JSON-RPC via actual HTTP POST to the running shop.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Http\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use PHPUnit\Framework\TestCase;

class McpEndpointTest extends TestCase
{
    private AgentTestHelper $agent;

    protected function setUp(): void
    {
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
```

### C2: UcpCheckoutEndpointTest

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Http\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use PHPUnit\Framework\TestCase;

class UcpCheckoutEndpointTest extends TestCase
{
    private AgentTestHelper $agent;

    protected function setUp(): void
    {
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
```

### C3: UcpProfileEndpointTest

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Http\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use PHPUnit\Framework\TestCase;

class UcpProfileEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        if (empty(getenv('SHOP_URL'))) {
            $this->markTestSkipped('SHOP_URL not set');
        }
    }

    public function testProfileReturnsUcpFormat(): void
    {
        $shopUrl = getenv('SHOP_URL') ?: 'http://localhost';

        $ch = curl_init($shopUrl . '/?cl=stripeucpprofile');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $this->assertSame(200, $httpCode);
        $this->assertStringContainsString('application/json', $contentType);

        $body = json_decode($response, true);
        $this->assertArrayHasKey('ucp_version', $body);
        $this->assertSame('2026-01-11', $body['ucp_version']);
        $this->assertArrayHasKey('services', $body);
        $this->assertArrayHasKey('capabilities', $body);
        $this->assertArrayHasKey('payment', $body);
    }
}
```

---

## Part D: LLM E2E Tests (Level 3 — Real LLM)

### D1: LlmProductDiscoveryTest

A real LLM is given the shop's MCP tool schemas and asked to find products. The test verifies that the LLM autonomously calls `list_products`.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\E2e\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\FeatherlessClient;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\LlmToolExecutor;
use PHPUnit\Framework\TestCase;

class LlmProductDiscoveryTest extends TestCase
{
    private FeatherlessClient $llm;
    private AgentTestHelper $agent;
    private LlmToolExecutor $executor;

    protected function setUp(): void
    {
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
                'content' => 'You are a shopping assistant. Use the available tools to help the user. Always use tools when asked about products.',
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
            fn ($tc) => $tc['function']['name'],
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
     * Convert MCP tool schemas to OpenAI function calling format.
     *
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}> $mcpTools
     * @return list<array{type: string, function: array{name: string, description: string, parameters: array<string, mixed>}}>
     */
    private function convertMcpToolsToOpenAiFormat(array $mcpTools): array
    {
        return array_map(fn (array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['inputSchema'],
            ],
        ], $mcpTools);
    }
}
```

### D2: LlmAcpCheckoutTest

Full E2E: LLM autonomously discovers products, creates a checkout, and the test verifies the contract state in the database.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\E2e\Mcp;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\AgentTestHelper;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\FeatherlessClient;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\LlmToolExecutor;
use PHPUnit\Framework\TestCase;

class LlmAcpCheckoutTest extends TestCase
{
    private FeatherlessClient $llm;
    private AgentTestHelper $agent;
    private LlmToolExecutor $executor;
    private ContractRepositoryInterface $contractRepository;

    /** @var list<array{type: string, function: array<string, mixed>}> */
    private array $tools;

    protected function setUp(): void
    {
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
        $this->contractRepository = $this->getContainer()->get(ContractRepositoryInterface::class);

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
        $maxTurns = 6;  // Safety limit

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = $this->llm->chatCompletion($messages, $this->tools);

            // If LLM is done talking (no tool calls), break
            if (empty($response['tool_calls'])) {
                // Check if the LLM's final message mentions a checkout ID
                if ($response['content'] !== null) {
                    preg_match('/[a-f0-9]{32}/', $response['content'], $matches);
                    if (!empty($matches)) {
                        $checkoutId = $matches[0];
                    }
                }
                break;
            }

            // Add assistant message with tool_calls
            $messages[] = [
                'role' => 'assistant',
                'content' => $response['content'],
                'tool_calls' => $response['tool_calls'],
            ];

            // Execute tool calls against shop
            $toolResults = $this->executor->executeAll($response['tool_calls']);
            foreach ($toolResults as $result) {
                $messages[] = $result;

                // Check if create_checkout was called — extract ID
                $decoded = json_decode($result['content'], true);
                if (is_array($decoded) && isset($decoded['id']) && isset($decoded['status'])) {
                    $checkoutId = $decoded['id'];
                }
            }
        }

        // === Assertions ===
        $this->assertNotNull($checkoutId, 'LLM should have created a checkout');

        // Verify contract exists in database
        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertNotNull($contract, 'Contract should exist in DB');

        // Verify it was created by an agent
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

        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $response = $this->llm->chatCompletion($messages, $this->tools);

            if (empty($response['tool_calls'])) {
                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response['content'],
                'tool_calls' => $response['tool_calls'],
            ];

            // Check what tools were called
            foreach ($response['tool_calls'] as $tc) {
                if ($tc['function']['name'] === 'cancel_checkout') {
                    $wasCancelled = true;
                }
            }

            $toolResults = $this->executor->executeAll($response['tool_calls']);
            foreach ($toolResults as $result) {
                $messages[] = $result;

                $decoded = json_decode($result['content'], true);
                if (is_array($decoded) && isset($decoded['id']) && !$wasCancelled) {
                    $checkoutId = $decoded['id'];
                }
            }
        }

        $this->assertNotNull($checkoutId, 'LLM should have created a checkout');
        $this->assertTrue($wasCancelled, 'LLM should have called cancel_checkout');

        // Verify contract is cancelled in DB
        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertNotNull($contract);
        $this->assertSame('cancelled', $contract->getStateValue());
    }

    /** @return list<array{type: string, function: array<string, mixed>}> */
    private function convertMcpToolsToOpenAiFormat(array $mcpTools): array
    {
        return array_map(fn (array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['inputSchema'],
            ],
        ], $mcpTools);
    }
}
```

---

## Part E: Test Configuration

### E1: phpunit.xml Additions

Add new test suites to the existing `tests/phpunit.xml`:

```xml
<!-- Add alongside existing Unit and Integration suites -->
<testsuite name="McpIntegration">
    <directory>tests/Integration/Mcp</directory>
</testsuite>
<testsuite name="McpHttp">
    <directory>tests/Http/Mcp</directory>
</testsuite>
<testsuite name="McpE2e">
    <directory>tests/E2e/Mcp</directory>
</testsuite>
```

### E2: Test Execution Commands

```bash
# Level 1: Integration tests (requires DB)
docker compose exec -T php php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml \
    --testsuite McpIntegration

# Level 2: HTTP tests (requires running shop)
docker compose exec -T php \
    -e SHOP_URL=http://localhost \
    -e STRIPE_AGENT_API_KEY=test-key \
    php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml \
    --testsuite McpHttp

# Level 3: LLM E2E tests (requires Featherless API key + running shop)
docker compose exec -T php \
    -e SHOP_URL=http://localhost \
    -e STRIPE_AGENT_API_KEY=test-key \
    -e FEATHERLESS_API_KEY=$FEATHERLESS_API_KEY \
    -e FEATHERLESS_MODEL=Qwen/Qwen2.5-72B-Instruct \
    php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml \
    --testsuite McpE2e

# All MCP tests together
docker compose exec -T php \
    -e SHOP_URL=http://localhost \
    -e STRIPE_AGENT_API_KEY=test-key \
    -e FEATHERLESS_API_KEY=$FEATHERLESS_API_KEY \
    php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml \
    --testsuite McpIntegration,McpHttp,McpE2e

# Skip LLM E2E in local dev
LLM_E2E_SKIP=true docker compose exec -T php php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml \
    --testsuite McpIntegration,McpHttp,McpE2e
```

---

## LLM E2E Flow Diagram

```
PHPUnit Test                    Featherless API                Shop (localhost)
     │                                │                              │
     │  chatCompletion(messages,      │                              │
     │  tools=[6 MCP tool schemas])   │                              │
     ├───────────────────────────────►│                              │
     │                                │ LLM reasons:                 │
     │                                │ "I need to find products"    │
     │  ◄─── tool_calls: [{          │                              │
     │    name: "list_products",      │                              │
     │    arguments: {limit: 5}       │                              │
     │  }]                            │                              │
     │                                │                              │
     │  LlmToolExecutor translates:   │                              │
     │  POST /?cl=stripemcp           │                              │
     │  {"jsonrpc":"2.0","method":    │                              │
     │   "tools/call","params":{      │                              │
     │   "name":"list_products",...}} ─┼─────────────────────────────►│
     │                                │                              │ McpServer
     │                                │                              │ → ListProductsTool
     │  ◄─────────────────────────────┼──────────────────────────────┤ → DB query
     │  {products: [...]}             │                              │
     │                                │                              │
     │  chatCompletion(messages +     │                              │
     │  tool_result, tools)           │                              │
     ├───────────────────────────────►│                              │
     │                                │ LLM reasons:                 │
     │                                │ "I'll buy product X"         │
     │  ◄─── tool_calls: [{          │                              │
     │    name: "create_checkout",    │                              │
     │    arguments: {items: [...]}   │                              │
     │  }]                            │                              │
     │                                │                              │
     │  POST /?cl=stripemcp ──────────┼─────────────────────────────►│
     │  ◄─────────────────────────────┼──────────────────────────────┤
     │  {id: "abc123", status: ...}   │                              │
     │                                │                              │
     │  ASSERT: contract "abc123"     │                              │
     │  exists in DB with             │                              │
     │  acp_agent_id metadata         │                              │
     └                                └                              └
```

---

## File Summary

| # | Type | File | Purpose | Est. Lines |
|---|------|------|---------|-----------|
| 1 | Fixture | `tests/Fixture/Mcp/AgentTestHelper.php` | HTTP client for agent simulation | ~110 |
| 2 | Fixture | `tests/Fixture/Mcp/McpRequestBuilder.php` | JSON-RPC request factory | ~60 |
| 3 | Fixture | `tests/Fixture/Mcp/FeatherlessClient.php` | OpenAI-compatible LLM client | ~90 |
| 4 | Fixture | `tests/Fixture/Mcp/LlmToolExecutor.php` | Bridge: LLM tool_calls → shop HTTP | ~55 |
| 5 | Integration | `tests/Integration/Mcp/McpServerIntegrationTest.php` | MCP server + DI + DB | ~70 |
| 6 | Integration | `tests/Integration/Mcp/AcpCheckoutFlowTest.php` | Full checkout lifecycle | ~120 |
| 7 | Integration | `tests/Integration/Mcp/UcpCheckoutFlowTest.php` | UCP REST routing | ~80 |
| 8 | Integration | `tests/Integration/Mcp/SptPaymentServiceTest.php` | SPT against Stripe test API | ~35 |
| 9 | HTTP | `tests/Http/Mcp/McpEndpointTest.php` | MCP via real HTTP | ~75 |
| 10 | HTTP | `tests/Http/Mcp/UcpCheckoutEndpointTest.php` | UCP via real HTTP | ~55 |
| 11 | HTTP | `tests/Http/Mcp/UcpProfileEndpointTest.php` | Profile endpoint test | ~35 |
| 12 | E2E | `tests/E2e/Mcp/LlmProductDiscoveryTest.php` | LLM → list_products | ~80 |
| 13 | E2E | `tests/E2e/Mcp/LlmAcpCheckoutTest.php` | LLM → full checkout lifecycle | ~150 |
| | | **Total** | **13 files** | **~1,015** |

---

## Graceful Degradation

Tests skip cleanly when credentials are missing — no failures in local dev:

| Level | Missing Variable | Behavior |
|-------|-----------------|----------|
| Integration | _(none — uses DI container)_ | Always runs |
| HTTP | `SHOP_URL` or `STRIPE_AGENT_API_KEY` | `markTestSkipped()` |
| E2E | `FEATHERLESS_API_KEY` | `markTestSkipped()` |
| E2E | `LLM_E2E_SKIP=true` | `markTestSkipped()` |
| SPT Integration | `STRIPE_TEST_SECRET_KEY` | `markTestSkipped()` |

---

## CI Configuration

### GitHub Actions Example

```yaml
env:
  SHOP_URL: http://localhost
  STRIPE_AGENT_API_KEY: ${{ secrets.STRIPE_AGENT_API_KEY }}
  STRIPE_TEST_SECRET_KEY: ${{ secrets.STRIPE_TEST_SECRET_KEY }}
  FEATHERLESS_API_KEY: ${{ secrets.FEATHERLESS_API_KEY }}
  FEATHERLESS_MODEL: Qwen/Qwen2.5-72B-Instruct

jobs:
  mcp-tests:
    steps:
      - name: Start Docker
        run: make up

      - name: Run MCP Integration Tests
        run: |
          docker compose exec -T php php vendor/bin/phpunit \
            -c extensions/stripe/tests/phpunit.xml \
            --testsuite McpIntegration

      - name: Run MCP HTTP Tests
        run: |
          docker compose exec -T php php vendor/bin/phpunit \
            -c extensions/stripe/tests/phpunit.xml \
            --testsuite McpHttp

      - name: Run MCP LLM E2E Tests
        run: |
          docker compose exec -T php php vendor/bin/phpunit \
            -c extensions/stripe/tests/phpunit.xml \
            --testsuite McpE2e
```

---

## Verification Checklist

### Integration Tests
- [ ] MCP server resolves all 6 tools from DI container
- [ ] Full checkout lifecycle: create → get → update → cancel
- [ ] Contract metadata persists agent_id in DB
- [ ] Double-cancel returns validation error
- [ ] Complete without token returns error
- [ ] UCP routing: POST create, GET, PUT update, POST cancel
- [ ] UCP unknown route returns 404
- [ ] SPT service returns failure for invalid token

### HTTP Tests
- [ ] MCP endpoint responds to JSON-RPC (initialize, tools/list, tools/call)
- [ ] Unauthorized requests return 401
- [ ] Full MCP checkout lifecycle over HTTP
- [ ] UCP create + get + cancel over HTTP
- [ ] UCP profile returns valid JSON with ucp_version

### LLM E2E Tests
- [ ] LLM autonomously calls `list_products` when asked about products
- [ ] LLM autonomously calls `create_checkout` when asked to buy
- [ ] LLM creates checkout → contract exists in DB with agent metadata
- [ ] LLM creates and cancels checkout → contract state = `cancelled`
- [ ] Tests skip gracefully when `FEATHERLESS_API_KEY` not set
- [ ] Tests complete within `LLM_E2E_TIMEOUT` seconds

### Cross-Sprint
- [ ] All existing 799+ tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations
- [ ] Test suites added to `phpunit.xml`: McpIntegration, McpHttp, McpE2e

---

## Acceptance Criteria

1. **Integration tests** pass against real OXID database inside Docker
2. **HTTP tests** pass against running OXID shop (localhost)
3. **LLM E2E tests** pass with Featherless API — a real LLM autonomously discovers tools and creates checkouts
4. Tests **skip gracefully** when credentials are missing (no CI failures without secrets)
5. All test data uses `e2e_` / `integration-test` / `llm-e2e` prefixes for identification
6. `FEATHERLESS_MODEL` is configurable — default model runs on free tier
7. No new PHPMD/PHPStan/PHPCS violations
8. Test fixtures (`AgentTestHelper`, `FeatherlessClient`, `LlmToolExecutor`) are reusable for future sprints

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Featherless API rate limits | Medium | Low request volume (~10 calls/test), configurable timeout |
| LLM non-determinism | High | `temperature: 0.0`, explicit system prompt, max 6-8 turns, assert on tool calls not text |
| Model doesn't support tool_use | Medium | `Qwen/Qwen2.5-72B-Instruct` confirmed supports function calling; model is configurable |
| Featherless downtime in CI | Low | `markTestSkipped()` — CI still passes, just skips E2E |
| Slow LLM responses | Medium | Configurable timeout, separate test suite (can exclude in fast CI) |
| Test data conflicts | Low | Unique email prefixes per test, contract IDs are UUIDs |
