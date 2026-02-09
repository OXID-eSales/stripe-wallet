# Sprint 54: E2E Tests for MCP/ACP Full-Scale Testing

**Date:** 2026-02-09
**Status:** TODO
**Priority:** High
**Prerequisites:** Sprints 47-53 (all MCP/ACP infrastructure)
**Principle:** End-to-end tests that exercise the entire agentic commerce stack — from MCP initialization through product discovery, checkout creation, payment completion, and fulfillment notification. Tests run against a live OXID shop with Stripe test mode.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Tests are the deliverable — this IS the testing sprint |
| SOLID | Test classes organized by protocol (MCP, ACP, UCP) and flow |
| DI | Test fixtures use the real DI container (integration tests) |
| LSP | Same test scenarios run against both self-hosted and hosted paths |
| DRY | Shared test helpers for HTTP requests, assertions, fixture setup |
| No Overengineering | PHPUnit integration tests + Playwright for browser flows only if needed |
| Clean Code | AAA pattern, descriptive test names, no magic values |

---

## Objective

Comprehensive test coverage across three levels:

### Level 1: PHPUnit Integration Tests
Test the full PHP stack inside Docker against real database — contract creation, state transitions, SPT mocking, webhook processing.

### Level 2: HTTP Integration Tests
Test actual HTTP endpoints (`stripemcp`, `stripeproductfeed`, `stripeucp`) with real HTTP requests against the running OXID shop.

### Level 3: Playwright E2E Tests
Test agentic flows that touch both browser and API — product feed download, MCP tool discovery, checkout completion with mock agent.

---

## Test Architecture

```
tests/
├── Integration/
│   └── Mcp/
│       ├── McpServerIntegrationTest.php
│       ├── AcpCheckoutFlowTest.php
│       ├── ProductFeedIntegrationTest.php
│       ├── AgentNotificationTest.php
│       ├── SptWebhookIntegrationTest.php
│       ├── ConditionTypeRegistryIntegrationTest.php
│       └── UcpCheckoutFlowTest.php
├── Http/
│   └── Mcp/
│       ├── McpEndpointTest.php
│       ├── ProductFeedEndpointTest.php
│       ├── UcpProfileEndpointTest.php
│       └── UcpCheckoutEndpointTest.php
├── e2e/
│   └── playwright/
│       └── tests/
│           └── agentic/
│               ├── mcp-discovery.spec.ts
│               ├── acp-checkout-flow.spec.ts
│               └── product-feed.spec.ts
└── Fixture/
    ├── AgentTestHelper.php
    ├── McpRequestBuilder.php
    └── AcpFixtures.php
```

---

## Test Fixtures and Helpers

### AgentTestHelper

Shared helper for simulating AI agent behavior in tests.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture;

class AgentTestHelper
{
    private string $baseUrl;
    private string $bearerToken;

    public function __construct(string $baseUrl, string $bearerToken)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->bearerToken = $bearerToken;
    }

    /**
     * Send an MCP JSON-RPC request.
     *
     * @return array{httpCode: int, body: array}
     */
    public function mcpRequest(string $method, array $params = [], int $id = 1): array
    {
        return $this->httpPost(
            $this->baseUrl . '/index.php?cl=stripemcp',
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => $method,
                'params' => $params,
            ]
        );
    }

    /**
     * MCP initialize handshake.
     */
    public function initialize(): array
    {
        return $this->mcpRequest('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-agent', 'version' => '1.0.0'],
        ]);
    }

    /**
     * List available MCP tools.
     */
    public function listTools(): array
    {
        return $this->mcpRequest('tools/list');
    }

    /**
     * Call an MCP tool.
     */
    public function callTool(string $toolName, array $arguments = []): array
    {
        return $this->mcpRequest('tools/call', [
            'name' => $toolName,
            'arguments' => $arguments,
        ]);
    }

    /**
     * Fetch product feed.
     *
     * @return array{httpCode: int, body: string, contentType: string}
     */
    public function fetchProductFeed(): array
    {
        return $this->httpGet($this->baseUrl . '/index.php?cl=stripeproductfeed');
    }

    /**
     * Send UCP checkout request.
     *
     * @return array{httpCode: int, body: array}
     */
    public function ucpCheckout(string $method, string $path, array $body = []): array
    {
        $url = $this->baseUrl . '/index.php?cl=stripeucp' . $path;

        return match ($method) {
            'POST' => $this->httpPost($url, $body),
            'GET' => $this->httpGet($url),
            'PUT' => $this->httpPut($url, $body),
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    private function httpPost(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->bearerToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'httpCode' => $httpCode,
            'body' => json_decode($response, true) ?? [],
        ];
    }

    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->bearerToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        return [
            'httpCode' => $httpCode,
            'body' => $response,
            'contentType' => $contentType,
        ];
    }

    private function httpPut(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->bearerToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'httpCode' => $httpCode,
            'body' => json_decode($response, true) ?? [],
        ];
    }
}
```

### McpRequestBuilder

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture;

class McpRequestBuilder
{
    public static function initialize(): string
    {
        return json_encode([
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
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);
    }

    public static function toolsCall(string $name, array $arguments): string
    {
        return json_encode([
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

---

## Integration Tests (PHPUnit)

### Test 1: McpServerIntegrationTest

Tests the MCP server with real tool registry from DI container.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\McpServerInterface;
use PHPUnit\Framework\TestCase;

class McpServerIntegrationTest extends TestCase
{
    private McpServerInterface $server;
    private AgentContext $agentContext;

    protected function setUp(): void
    {
        // Resolve from real DI container
        $this->server = $this->getContainer()->get(McpServerInterface::class);
        $this->agentContext = new AgentContext('test-agent', 'test-token');
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
        $this->assertSame('oxid-stripe-acp', $response['result']['serverInfo']['name']);
    }

    public function testToolsListReturnsAllRegisteredTools(): void
    {
        $response = $this->server->handleJsonRpc(
            McpRequestBuilder::toolsList(),
            $this->agentContext
        );

        $tools = $response['result']['tools'];
        $toolNames = array_column($tools, 'name');

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
            $this->assertArrayHasKey('name', $tool, "Tool missing name");
            $this->assertArrayHasKey('description', $tool, "Tool {$tool['name']} missing description");
            $this->assertArrayHasKey('inputSchema', $tool, "Tool {$tool['name']} missing inputSchema");
            $this->assertSame('object', $tool['inputSchema']['type'], "Tool {$tool['name']} schema not object");
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
}
```

### Test 2: AcpCheckoutFlowTest

Full ACP checkout lifecycle — create → get → update → complete → verify contract state.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
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

    public function testFullCheckoutLifecycle(): void
    {
        // 1. CREATE checkout
        $createResult = $this->checkoutService->createCheckout([
            'items' => [
                ['id' => $this->getTestArticleId(), 'quantity' => 1],
            ],
            'buyer' => [
                'email' => 'e2e-agent@example.com',
                'first_name' => 'E2E',
                'last_name' => 'Agent',
            ],
            'currency' => 'EUR',
        ], $this->agentContext);

        $this->assertArrayHasKey('id', $createResult);
        $this->assertArrayHasKey('status', $createResult);
        $checkoutId = $createResult['id'];

        // 2. GET checkout
        $getResult = $this->checkoutService->getCheckout($checkoutId);
        $this->assertSame($checkoutId, $getResult['id']);
        $this->assertArrayHasKey('line_items', $getResult);
        $this->assertNotEmpty($getResult['line_items']);

        // 3. Verify contract exists in database
        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertNotNull($contract);
        $this->assertSame('e2e-test-agent', $contract->getMetadata('acp_agent_id'));

        // 4. CANCEL checkout
        $cancelResult = $this->checkoutService->cancelCheckout($checkoutId);
        $this->assertSame('canceled', $cancelResult['status']);

        // 5. Verify contract state
        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertSame('cancelled', $contract->getStateValue());
    }

    public function testGetCheckoutNotFound(): void
    {
        $result = $this->checkoutService->getCheckout('nonexistent-id');
        $this->assertArrayHasKey('error', $result);
    }

    public function testCancelAlreadyCancelledCheckout(): void
    {
        // Create and cancel
        $createResult = $this->checkoutService->createCheckout([
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'e2e@example.com'],
        ], $this->agentContext);

        $this->checkoutService->cancelCheckout($createResult['id']);

        // Cancel again — should return error
        $result = $this->checkoutService->cancelCheckout($createResult['id']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testCompleteCheckoutWithoutToken(): void
    {
        $createResult = $this->checkoutService->createCheckout([
            'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
            'buyer' => ['email' => 'e2e@example.com'],
        ], $this->agentContext);

        $result = $this->checkoutService->completeCheckout(
            $createResult['id'],
            ['token' => '', 'provider' => 'stripe'],
            $this->agentContext
        );

        $this->assertArrayHasKey('error', $result);
    }

    private function getTestArticleId(): string
    {
        // Return a known test article ID from the OXID test data
        return 'e2e_test_article_001';
    }
}
```

### Test 3: ProductFeedIntegrationTest

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use PHPUnit\Framework\TestCase;

class ProductFeedIntegrationTest extends TestCase
{
    public function testProductServiceReturnsProducts(): void
    {
        $service = $this->getContainer()->get(AcpProductServiceInterface::class);
        $result = $service->listProducts(['limit' => 10]);

        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertLessThanOrEqual(10, count($result['products']));
    }

    public function testProductsHaveRequiredFields(): void
    {
        $service = $this->getContainer()->get(AcpProductServiceInterface::class);
        $result = $service->listProducts(['limit' => 1]);

        if (empty($result['products'])) {
            $this->markTestSkipped('No products in test database');
        }

        $product = $result['products'][0];
        $requiredFields = ['id', 'title', 'price', 'availability', 'url'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $product, "Missing required field: {$field}");
        }
    }

    public function testCsvFeedGeneratorProducesValidCsv(): void
    {
        $service = $this->getContainer()->get(AcpProductServiceInterface::class);
        $generator = $this->getContainer()->get(ProductFeedGeneratorInterface::class);

        $result = $service->listProducts(['limit' => 5]);
        $csv = $generator->generate($result['products']);

        $lines = explode("\n", trim($csv));
        $this->assertGreaterThan(1, count($lines), 'CSV should have header + data rows');

        // Parse header
        $header = str_getcsv($lines[0]);
        $this->assertContains('ID', $header);
        $this->assertContains('Title', $header);
        $this->assertContains('Price', $header);
    }

    public function testPaginationWorks(): void
    {
        $service = $this->getContainer()->get(AcpProductServiceInterface::class);

        $page1 = $service->listProducts(['limit' => 2, 'offset' => 0]);
        $page2 = $service->listProducts(['limit' => 2, 'offset' => 2]);

        if ($page1['total'] > 2) {
            $ids1 = array_column($page1['products'], 'id');
            $ids2 = array_column($page2['products'], 'id');
            $this->assertEmpty(array_intersect($ids1, $ids2), 'Pages should not overlap');
        }
    }
}
```

### Test 4: AgentNotificationTest

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Mcp\Notification\AgentCallbackRegistryInterface;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationPayload;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationServiceInterface;
use PHPUnit\Framework\TestCase;

class AgentNotificationTest extends TestCase
{
    public function testCallbackRegistrationPersistsInContractMetadata(): void
    {
        $registry = $this->getContainer()->get(AgentCallbackRegistryInterface::class);

        // Create a test contract first
        $contractId = $this->createTestContract();

        $registry->register($contractId, 'test-agent', 'https://agent.example.com/webhook');

        $this->assertSame(
            'https://agent.example.com/webhook',
            $registry->getCallbackUrl($contractId)
        );
        $this->assertSame('test-agent', $registry->getAgentId($contractId));
    }

    public function testNotificationPayloadFormat(): void
    {
        $payload = new AgentNotificationPayload(
            'order.fulfilled',
            'contract_123',
            'fulfilled',
            'order_456',
            'https://shop.example.com/order/456'
        );

        $data = $payload->toArray();

        $this->assertSame('order.fulfilled', $data['event_type']);
        $this->assertSame('contract_123', $data['checkout_session_id']);
        $this->assertSame('fulfilled', $data['status']);
        $this->assertSame('order_456', $data['order']['id']);
        $this->assertArrayHasKey('timestamp', $data);
    }

    public function testNotificationSkippedWhenNoCallbackRegistered(): void
    {
        $service = $this->getContainer()->get(AgentNotificationServiceInterface::class);

        $payload = new AgentNotificationPayload(
            'order.created',
            'no-callback-contract',
            'created'
        );

        $result = $service->notify('no-callback-contract', $payload);

        $this->assertFalse($result->isDelivered());
        $this->assertSame('No callback URL registered', $result->getErrorMessage());
    }
}
```

### Test 5: SptWebhookIntegrationTest

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\WebhookHandler\SptTokenUsedHandler;
use OxidEsales\Payments\Stripe\WebhookHandler\SptTokenDeactivatedHandler;
use PHPUnit\Framework\TestCase;

class SptWebhookIntegrationTest extends TestCase
{
    public function testSptUsedUpdatesContractMetadata(): void
    {
        $contractId = $this->createTestContract();
        $handler = $this->getContainer()->get(SptTokenUsedHandler::class);

        $handler->handle([
            'data' => [
                'object' => [
                    'id' => 'spt_test_123',
                    'seller_details' => ['external_id' => $contractId],
                    'payment_method' => [
                        'card' => ['brand' => 'visa', 'last4' => '4242'],
                    ],
                ],
            ],
        ]);

        $repository = $this->getContainer()->get(ContractRepositoryInterface::class);
        $contract = $repository->findById($contractId);

        $this->assertSame('spt_test_123', $contract->getMetadata('spt_token_id'));
        $this->assertSame('visa', $contract->getMetadata('spt_card_brand'));
        $this->assertSame('4242', $contract->getMetadata('spt_card_last4'));
    }

    public function testSptDeactivatedCancelsContract(): void
    {
        $contractId = $this->createTestContract();
        $handler = $this->getContainer()->get(SptTokenDeactivatedHandler::class);

        $handler->handle([
            'data' => [
                'object' => [
                    'id' => 'spt_test_456',
                    'deactivated_reason' => 'expired',
                    'seller_details' => ['external_id' => $contractId],
                ],
            ],
        ]);

        $repository = $this->getContainer()->get(ContractRepositoryInterface::class);
        $contract = $repository->findById($contractId);

        $this->assertSame('cancelled', $contract->getStateValue());
        $this->assertSame('expired', $contract->getMetadata('spt_deactivated_reason'));
    }
}
```

### Test 6: ConditionTypeRegistryIntegrationTest

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\PaymentComponent\Contract\ConditionTypeRegistryInterface;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

class ConditionTypeRegistryIntegrationTest extends TestCase
{
    public function testRegistryContainsAllSixTypes(): void
    {
        $registry = $this->getContainer()->get(ConditionTypeRegistryInterface::class);
        $types = $registry->getRegisteredTypes();

        // 4 core + 2 agent
        $this->assertContains('payment_authorized', $types);
        $this->assertContains('fraud_check', $types);
        $this->assertContains('compliance_check', $types);
        $this->assertContains('address_validated', $types);
        $this->assertContains('agent_identity_verified', $types);
        $this->assertContains('agent_consent_confirmed', $types);
    }

    public function testAgentConditionFactoryMethods(): void
    {
        $condition = ContractCondition::agentIdentityVerified();
        $this->assertSame('agent_identity_verified', $condition->getType());

        $condition = ContractCondition::agentConsentConfirmed();
        $this->assertSame('agent_consent_confirmed', $condition->getType());
    }

    public function testInvalidTypeStillThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ContractCondition('totally_invalid_type');
    }
}
```

---

## HTTP Integration Tests

### Test 7: McpEndpointTest

Tests the actual HTTP endpoint with curl.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Http\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\AgentTestHelper;
use PHPUnit\Framework\TestCase;

class McpEndpointTest extends TestCase
{
    private AgentTestHelper $agent;

    protected function setUp(): void
    {
        $this->agent = new AgentTestHelper(
            getenv('SHOP_URL') ?: 'http://localhost.local',
            getenv('AGENT_API_KEY') ?: 'test-agent-key'
        );
    }

    public function testInitializeHandshake(): void
    {
        $response = $this->agent->initialize();

        $this->assertSame(200, $response['httpCode']);
        $this->assertSame('2.0', $response['body']['jsonrpc']);
        $this->assertArrayHasKey('result', $response['body']);
        $this->assertArrayHasKey('serverInfo', $response['body']['result']);
    }

    public function testUnauthorizedWithoutToken(): void
    {
        $agent = new AgentTestHelper(
            getenv('SHOP_URL') ?: 'http://localhost.local',
            ''  // No token
        );

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

    public function testFullMcpFlow(): void
    {
        // 1. Initialize
        $init = $this->agent->initialize();
        $this->assertSame(200, $init['httpCode']);

        // 2. Discover tools
        $tools = $this->agent->listTools();
        $this->assertSame(200, $tools['httpCode']);

        // 3. List products
        $products = $this->agent->callTool('list_products', ['limit' => 3]);
        $this->assertSame(200, $products['httpCode']);
        $this->assertArrayNotHasKey('error', $products['body']);

        // 4. Create checkout (if products exist)
        $content = json_decode($products['body']['result']['content'][0]['text'], true);
        if (!empty($content['products'])) {
            $productId = $content['products'][0]['id'];

            $checkout = $this->agent->callTool('create_checkout', [
                'items' => [['id' => $productId, 'quantity' => 1]],
                'buyer' => ['email' => 'http-test@example.com'],
            ]);

            $this->assertSame(200, $checkout['httpCode']);
            $checkoutData = json_decode($checkout['body']['result']['content'][0]['text'], true);
            $this->assertArrayHasKey('id', $checkoutData);

            // 5. Get checkout
            $get = $this->agent->callTool('get_checkout', [
                'checkout_id' => $checkoutData['id'],
            ]);
            $this->assertSame(200, $get['httpCode']);

            // 6. Cancel checkout
            $cancel = $this->agent->callTool('cancel_checkout', [
                'checkout_id' => $checkoutData['id'],
            ]);
            $this->assertSame(200, $cancel['httpCode']);
        }
    }
}
```

### Test 8: ProductFeedEndpointTest

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Http\Mcp;

use OxidEsales\Payments\Stripe\Tests\Fixture\AgentTestHelper;
use PHPUnit\Framework\TestCase;

class ProductFeedEndpointTest extends TestCase
{
    public function testProductFeedReturnsCSV(): void
    {
        $agent = new AgentTestHelper(
            getenv('SHOP_URL') ?: 'http://localhost.local',
            getenv('AGENT_API_KEY') ?: 'test-agent-key'
        );

        $response = $agent->fetchProductFeed();

        $this->assertSame(200, $response['httpCode']);
        $this->assertStringContains('text/csv', $response['contentType']);

        $lines = explode("\n", trim($response['body']));
        $this->assertGreaterThanOrEqual(1, count($lines), 'Feed should have at least a header');

        // Validate header contains required columns
        $header = $lines[0];
        $this->assertStringContains('ID', $header);
        $this->assertStringContains('Title', $header);
    }

    public function testProductFeedRequiresAuth(): void
    {
        $agent = new AgentTestHelper(
            getenv('SHOP_URL') ?: 'http://localhost.local',
            ''
        );

        $response = $agent->fetchProductFeed();
        $this->assertSame(401, $response['httpCode']);
    }
}
```

---

## Playwright E2E Tests

### Test 9: MCP Discovery Flow

**File:** `tests/e2e/playwright/tests/agentic/mcp-discovery.spec.ts`

```typescript
import { test, expect } from '@playwright/test';

const SHOP_URL = process.env.SHOP_URL || 'http://localhost.local';
const AGENT_KEY = process.env.AGENT_API_KEY || 'test-agent-key';

test.describe('MCP Agent Discovery', () => {
    test('agent can initialize and discover tools', async ({ request }) => {
        // Initialize
        const initResponse = await request.post(`${SHOP_URL}/index.php?cl=stripemcp`, {
            data: {
                jsonrpc: '2.0',
                id: 1,
                method: 'initialize',
                params: {
                    protocolVersion: '2025-06-18',
                    capabilities: {},
                    clientInfo: { name: 'playwright-agent', version: '1.0.0' },
                },
            },
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${AGENT_KEY}`,
            },
        });

        expect(initResponse.ok()).toBeTruthy();
        const initBody = await initResponse.json();
        expect(initBody.result.serverInfo.name).toBe('oxid-stripe-acp');

        // List tools
        const toolsResponse = await request.post(`${SHOP_URL}/index.php?cl=stripemcp`, {
            data: {
                jsonrpc: '2.0',
                id: 2,
                method: 'tools/list',
                params: {},
            },
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${AGENT_KEY}`,
            },
        });

        expect(toolsResponse.ok()).toBeTruthy();
        const toolsBody = await toolsResponse.json();
        const toolNames = toolsBody.result.tools.map((t: any) => t.name);

        expect(toolNames).toContain('create_checkout');
        expect(toolNames).toContain('list_products');
        expect(toolNames).toContain('complete_checkout');
    });

    test('unauthorized agent is rejected', async ({ request }) => {
        const response = await request.post(`${SHOP_URL}/index.php?cl=stripemcp`, {
            data: { jsonrpc: '2.0', id: 1, method: 'initialize', params: {} },
            headers: { 'Content-Type': 'application/json' },
        });

        expect(response.status()).toBe(401);
    });
});
```

### Test 10: ACP Checkout Flow

**File:** `tests/e2e/playwright/tests/agentic/acp-checkout-flow.spec.ts`

```typescript
import { test, expect } from '@playwright/test';

const SHOP_URL = process.env.SHOP_URL || 'http://localhost.local';
const AGENT_KEY = process.env.AGENT_API_KEY || 'test-agent-key';

async function mcpCall(request: any, method: string, params: any) {
    const response = await request.post(`${SHOP_URL}/index.php?cl=stripemcp`, {
        data: { jsonrpc: '2.0', id: 1, method, params },
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${AGENT_KEY}`,
        },
    });
    return response.json();
}

test.describe('ACP Checkout Flow', () => {
    test('full checkout lifecycle: create → get → cancel', async ({ request }) => {
        // 1. Find a product
        const productsResult = await mcpCall(request, 'tools/call', {
            name: 'list_products',
            arguments: { limit: 1 },
        });

        const products = JSON.parse(productsResult.result.content[0].text);
        expect(products.products.length).toBeGreaterThan(0);

        const productId = products.products[0].id;

        // 2. Create checkout
        const createResult = await mcpCall(request, 'tools/call', {
            name: 'create_checkout',
            arguments: {
                items: [{ id: productId, quantity: 1 }],
                buyer: { email: 'playwright@example.com', first_name: 'Play', last_name: 'Wright' },
            },
        });

        const checkout = JSON.parse(createResult.result.content[0].text);
        expect(checkout.id).toBeTruthy();
        expect(checkout.line_items.length).toBe(1);

        // 3. Get checkout
        const getResult = await mcpCall(request, 'tools/call', {
            name: 'get_checkout',
            arguments: { checkout_id: checkout.id },
        });

        const retrieved = JSON.parse(getResult.result.content[0].text);
        expect(retrieved.id).toBe(checkout.id);

        // 4. Cancel checkout
        const cancelResult = await mcpCall(request, 'tools/call', {
            name: 'cancel_checkout',
            arguments: { checkout_id: checkout.id },
        });

        const canceled = JSON.parse(cancelResult.result.content[0].text);
        expect(canceled.status).toBe('canceled');

        // 5. Verify double-cancel returns error
        const doubleCancelResult = await mcpCall(request, 'tools/call', {
            name: 'cancel_checkout',
            arguments: { checkout_id: checkout.id },
        });

        const errorResult = JSON.parse(doubleCancelResult.result.content[0].text);
        expect(errorResult.error).toBeTruthy();
    });

    test('complete checkout fails without payment token', async ({ request }) => {
        // Create checkout
        const productsResult = await mcpCall(request, 'tools/call', {
            name: 'list_products',
            arguments: { limit: 1 },
        });

        const products = JSON.parse(productsResult.result.content[0].text);
        if (products.products.length === 0) {
            test.skip();
            return;
        }

        const createResult = await mcpCall(request, 'tools/call', {
            name: 'create_checkout',
            arguments: {
                items: [{ id: products.products[0].id, quantity: 1 }],
                buyer: { email: 'notoken@example.com' },
            },
        });

        const checkout = JSON.parse(createResult.result.content[0].text);

        // Try to complete without token
        const completeResult = await mcpCall(request, 'tools/call', {
            name: 'complete_checkout',
            arguments: {
                checkout_id: checkout.id,
                payment_data: { token: '', provider: 'stripe' },
            },
        });

        const result = JSON.parse(completeResult.result.content[0].text);
        expect(result.error).toBeTruthy();
    });
});
```

### Test 11: Product Feed

**File:** `tests/e2e/playwright/tests/agentic/product-feed.spec.ts`

```typescript
import { test, expect } from '@playwright/test';

const SHOP_URL = process.env.SHOP_URL || 'http://localhost.local';
const AGENT_KEY = process.env.AGENT_API_KEY || 'test-agent-key';

test.describe('Product Feed', () => {
    test('CSV feed is downloadable and valid', async ({ request }) => {
        const response = await request.get(`${SHOP_URL}/index.php?cl=stripeproductfeed`, {
            headers: { 'Authorization': `Bearer ${AGENT_KEY}` },
        });

        expect(response.ok()).toBeTruthy();
        expect(response.headers()['content-type']).toContain('text/csv');

        const csv = await response.text();
        const lines = csv.trim().split('\n');

        // At least header row
        expect(lines.length).toBeGreaterThanOrEqual(1);

        // Header contains required fields
        const header = lines[0];
        expect(header).toContain('ID');
        expect(header).toContain('Title');
        expect(header).toContain('Price');
        expect(header).toContain('Availability');
    });

    test('product feed requires authentication', async ({ request }) => {
        const response = await request.get(`${SHOP_URL}/index.php?cl=stripeproductfeed`);
        expect(response.status()).toBe(401);
    });

    test('MCP list_products matches feed data', async ({ request }) => {
        // Get products via MCP
        const mcpResponse = await request.post(`${SHOP_URL}/index.php?cl=stripemcp`, {
            data: {
                jsonrpc: '2.0',
                id: 1,
                method: 'tools/call',
                params: { name: 'list_products', arguments: { limit: 5 } },
            },
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${AGENT_KEY}`,
            },
        });

        const mcpBody = await mcpResponse.json();
        const products = JSON.parse(mcpBody.result.content[0].text);

        // Get products via feed
        const feedResponse = await request.get(`${SHOP_URL}/index.php?cl=stripeproductfeed`, {
            headers: { 'Authorization': `Bearer ${AGENT_KEY}` },
        });
        const csv = await feedResponse.text();

        // Both sources should return products (if any exist)
        if (products.products.length > 0) {
            expect(csv.trim().split('\n').length).toBeGreaterThan(1);
        }
    });
});
```

---

## Test Configuration

### phpunit.xml Additions

```xml
<!-- Add to existing phpunit.xml -->
<testsuite name="McpIntegration">
    <directory>tests/Integration/Mcp</directory>
</testsuite>
<testsuite name="McpHttp">
    <directory>tests/Http/Mcp</directory>
</testsuite>
```

### Playwright Config Addition

```typescript
// In playwright.config.ts, add:
{
    name: 'agentic',
    testDir: './tests/agentic',
    use: {
        baseURL: process.env.SHOP_URL || 'http://localhost.local',
    },
}
```

### Environment Variables

```bash
# Required for HTTP and E2E tests
SHOP_URL=http://localhost.local
AGENT_API_KEY=test-agent-key-for-e2e

# Set in OXID module config (sStripeAgentApiKey)
# Must match AGENT_API_KEY above
```

---

## File Summary

| # | Type | File | Purpose | Est. Lines |
|---|------|------|---------|-----------|
| 1 | Fixture | `tests/Fixture/AgentTestHelper.php` | HTTP client for agent simulation | ~130 |
| 2 | Fixture | `tests/Fixture/McpRequestBuilder.php` | JSON-RPC request factory | ~50 |
| 3 | Integration | `tests/Integration/Mcp/McpServerIntegrationTest.php` | MCP server + DI test | ~60 |
| 4 | Integration | `tests/Integration/Mcp/AcpCheckoutFlowTest.php` | Full checkout lifecycle | ~100 |
| 5 | Integration | `tests/Integration/Mcp/ProductFeedIntegrationTest.php` | Feed generation test | ~60 |
| 6 | Integration | `tests/Integration/Mcp/AgentNotificationTest.php` | Notification delivery test | ~50 |
| 7 | Integration | `tests/Integration/Mcp/SptWebhookIntegrationTest.php` | SPT webhook handling | ~60 |
| 8 | Integration | `tests/Integration/Mcp/ConditionTypeRegistryIntegrationTest.php` | Registry test | ~30 |
| 9 | HTTP | `tests/Http/Mcp/McpEndpointTest.php` | Live HTTP endpoint test | ~80 |
| 10 | HTTP | `tests/Http/Mcp/ProductFeedEndpointTest.php` | Feed endpoint test | ~40 |
| 11 | Playwright | `tests/e2e/playwright/tests/agentic/mcp-discovery.spec.ts` | MCP discovery E2E | ~50 |
| 12 | Playwright | `tests/e2e/playwright/tests/agentic/acp-checkout-flow.spec.ts` | Checkout lifecycle E2E | ~90 |
| 13 | Playwright | `tests/e2e/playwright/tests/agentic/product-feed.spec.ts` | Feed download E2E | ~50 |
| | | **Total** | | **~850** |

---

## Test Execution

```bash
# 1. Unit tests (Sprints 47-53 — already exist)
docker compose exec -T php php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# 2. Integration tests (database required)
docker compose exec -T php php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml --testsuite McpIntegration

# 3. HTTP integration tests (running OXID shop required)
docker compose exec -T php php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml --testsuite McpHttp

# 4. Playwright E2E tests (running OXID shop + browser required)
cd tests/e2e/playwright && \
    SHOP_URL=http://localhost.local \
    AGENT_API_KEY=test-agent-key \
    npx playwright test tests/agentic/

# 5. Full pre-commit
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

### Integration Tests
- [ ] MCP server resolves all 6 tools from DI container
- [ ] Full checkout lifecycle passes (create → get → cancel)
- [ ] Product feed generates valid CSV with required fields
- [ ] Agent notifications skip non-agent contracts
- [ ] SPT webhook handlers update contract metadata
- [ ] Condition type registry contains all 6 types
- [ ] Double-cancel returns validation error

### HTTP Tests
- [ ] MCP endpoint responds to JSON-RPC requests
- [ ] Unauthorized requests return 401
- [ ] Product feed returns CSV with correct Content-Type
- [ ] Full MCP flow works over HTTP (init → tools → create → cancel)

### Playwright E2E Tests
- [ ] Agent can initialize, discover tools, and list products
- [ ] Full checkout lifecycle works end-to-end via HTTP
- [ ] Product feed downloads as valid CSV
- [ ] MCP and feed endpoints are consistent
- [ ] Unauthorized agent is rejected

### Cross-Sprint
- [ ] All existing 799+ tests continue to pass
- [ ] New test count: ~850 lines across 13 test files
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Acceptance Criteria

1. All integration tests pass against real OXID database
2. All HTTP tests pass against running OXID shop
3. All Playwright E2E tests pass in headless mode
4. Test coverage spans: MCP server, ACP checkout, product feed, notifications, webhooks, condition types, UCP
5. Tests are repeatable (proper setup/teardown, `e2e_` prefix for test data)
6. Test fixtures are reusable (AgentTestHelper, McpRequestBuilder)
7. CI-ready: all tests can run in Docker with environment variables
8. No flaky tests — deterministic assertions, no sleep/timing dependencies
