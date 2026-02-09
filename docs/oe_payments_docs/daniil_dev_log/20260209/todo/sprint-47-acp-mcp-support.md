# Sprint 47: ACP/MCP Support — Two-Module Implementation

**Date:** 2026-02-09
**Status:** TODO
**Priority:** High (New Feature Track)
**Prerequisites:** Sprint 46 completed (all quality gates green, 799 tests)
**Principle:** Build the thinnest possible layer that connects ACP/MCP protocols to the existing event-driven architecture — no new state machines, no parallel checkout flows. Provider-agnostic infrastructure goes to payment-component; only Stripe-specific payment confirmation stays in stripe.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | SRP, OCP, LSP, ISP, DIP in every class |
| DI | Depend on abstractions, wire via services.yaml |
| LSP | Subtypes must be substitutable for their base types |
| DRY | Reuse existing event chain — no duplicate checkout logic |
| No Overengineering | No UCP support yet, no custom condition types, no product feed yet |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

**Testing Command:**
```bash
./bin/pre-commit-check.sh --full
```

---

## Objective

Add an ACP/MCP layer that allows AI agents to:

1. **Discover** available checkout tools and shop resources via MCP
2. **Create** checkout sessions via ACP-compliant endpoints
3. **Query** checkout status via contract state
4. **Complete** checkout using delegated payment tokens
5. **Cancel** checkout sessions

The implementation spans **two modules**:

- **payment-component** — MCP server infrastructure, ACP tool definitions, response formatting, abstract checkout service (provider-agnostic)
- **stripe** — SPT payment confirmation, controller registration, services.yaml wiring (provider-specific)

### What This Sprint Covers

- MCP server infrastructure in payment-component (JSON-RPC routing, tool registry, auth)
- ACP tool definitions in payment-component (6 tools with JSON Schema)
- ACP response formatting in payment-component (contract → ACP JSON)
- Abstract checkout service in payment-component (create/get/update/cancel via contract interfaces)
- SPT payment confirmation in stripe (Shared Payment Token → PaymentIntent)
- Controller registration in stripe (OXID metadata.php entry point)
- services.yaml wiring in stripe (binds interfaces, tags tools)

### What This Sprint Does NOT Cover (future sprints)

- Product feed specification (catalog sync to AI agents)
- UCP (Google's Universal Commerce Protocol) support
- Custom ContractCondition types for agent-specific conditions
- Webhook delivery to AI agents (fulfillment updates)
- Stripe's hosted ACP endpoint integration (Agentic Commerce Suite)
- OAuth agent authentication (Bearer token is sufficient for v1)

---

## Architecture Decision: MCP-First, ACP-as-Tools

ACP can be implemented as either REST endpoints or MCP tools. We choose **MCP-first**:

| Approach | Pros | Cons |
|----------|------|------|
| REST endpoints | Standard HTTP, easy to test with curl | Separate auth, separate discovery, another controller layer |
| **MCP tools** (chosen) | Single protocol, built-in discovery, auth handled by MCP | Requires MCP server infrastructure |

**Rationale:** MCP provides tool discovery for free — agents call `tools/list` and get all checkout capabilities with input schemas. No separate API documentation needed. ACP checkout operations become MCP tools with JSON Schema input validation.

---

## Boundary Rule Applied

The test: **"Could this work with PayPal/Unzer instead of Stripe?"**

| Component | Provider-Agnostic? | Module | Rationale |
|-----------|-------------------|--------|-----------|
| `McpServer` (JSON-RPC router) | Yes | payment-component | Any provider module can reuse MCP infrastructure |
| `McpToolInterface` | Yes | payment-component | Tool contract is protocol-level, not provider-level |
| `McpAuthGuard` (Bearer token) | Yes | payment-component | Token injected as DI param — no module config dependency |
| `AgentContext`, `AuthResult` | Yes | payment-component | Value objects carry agent identity, not provider data |
| ACP tool classes (6 tools) | Yes | payment-component | Schemas follow ACP standard; delegate to service interfaces |
| `AcpResponseFormatter` | Yes | payment-component | Reads `PaymentContractInterface` + `BasketSnapshot` — both payment-component types |
| `AbstractAcpCheckoutService` | Yes | payment-component | create/get/update/cancel use `ContractServiceInterface`, `ContractRepositoryInterface` |
| `AcpProductServiceInterface` | Yes | payment-component | Product listing is shop-level, not provider-level |
| `StripeAcpCheckoutService` | **No** | stripe | Implements `completePayment()` with SPT → PaymentIntent |
| `SptPaymentService` | **No** | stripe | SPT is a Stripe-only payment primitive |
| `McpController` | **No** | stripe | OXID controller registered in stripe's `metadata.php` |

This mirrors existing patterns:

| Existing Pattern | MCP/ACP Equivalent |
|-----------------|-------------------|
| `AbstractWebhookProcessor` → `StripeWebhookProcessor` | `AbstractAcpCheckoutService` → `StripeAcpCheckoutService` |
| `WebhookEventHandlerInterface` → `ChargeRefundedHandler` | `McpToolInterface` → tools registered by stripe |
| `PaymentAdapterInterface` → `StripeAdapter` | `AcpCheckoutServiceInterface` → `StripeAcpCheckoutService` |
| `WebhookController` in stripe metadata.php | `McpController` in stripe metadata.php |

---

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────────┐
│                      AI Agent (MCP Client)                        │
└───────────────────────────┬──────────────────────────────────────┘
                            │ JSON-RPC 2.0 over HTTP
┌───────────────────────────▼──────────────────────────────────────┐
│  stripe module                                                    │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ McpController (HTTP entry, metadata.php registered)       │    │
│  └─────────────────────────┬────────────────────────────────┘    │
│                             │ delegates                           │
│  ┌──────────────────────────▼───────────────────────────────┐    │
│  │ StripeAcpCheckoutService                                  │    │
│  │ (extends AbstractAcpCheckoutService)                      │    │
│  │  └─ completePayment() → SptPaymentService → StripeAdapter│    │
│  └──────────────────────────────────────────────────────────┘    │
│                                                                   │
│  services.yaml: wires interfaces, tags tools, binds SPT service   │
└───────────────────────────┬──────────────────────────────────────┘
                            │ uses
┌───────────────────────────▼──────────────────────────────────────┐
│  payment-component (provider-agnostic)                            │
│                                                                   │
│  ┌───────────────┐  ┌──────────────┐  ┌──────────────────────┐  │
│  │  McpServer     │  │ McpAuthGuard │  │ AcpResponseFormatter │  │
│  │  (JSON-RPC     │  │ (Bearer      │  │ (contract → ACP      │  │
│  │   router +     │  │  token)      │  │  JSON response)      │  │
│  │   tool registry│  │              │  │                      │  │
│  └───────┬───────┘  └──────────────┘  └──────────────────────┘  │
│          │                                                        │
│  ┌───────▼───────────────────────────────────────────────────┐   │
│  │  ACP Tools (6 tools, tagged via services.yaml)             │   │
│  │  create_checkout | get_checkout | update_checkout           │   │
│  │  complete_checkout | cancel_checkout | list_products        │   │
│  │  (all delegate to AcpCheckoutServiceInterface)             │   │
│  └───────────────────────────┬───────────────────────────────┘   │
│                               │                                   │
│  ┌───────────────────────────▼───────────────────────────────┐   │
│  │  AbstractAcpCheckoutService                                │   │
│  │  create/get/update/cancel → ContractService + Repository   │   │
│  │  completePayment() → abstract (provider must implement)    │   │
│  └───────────────────────────────────────────────────────────┘   │
│                                                                   │
│  Existing: ContractService, ContractRepository, EventDispatcher,  │
│            PaymentContractInterface, BasketSnapshot, EventContext  │
└──────────────────────────────────────────────────────────────────┘
```

---

## Part A: payment-component Changes

**Namespace:** `OxidEsales\PaymentComponent\Mcp\`

### New Directory Structure

```
payment-component/src/Mcp/
├── McpServerInterface.php
├── McpServer.php
├── McpToolInterface.php
├── AgentContext.php
├── Auth/
│   ├── McpAuthGuardInterface.php
│   ├── McpAuthGuard.php
│   └── AuthResult.php
├── Event/
│   └── McpRequestReceivedEvent.php
├── Handler/
│   └── McpRequestHandler.php
├── Http/
│   ├── HttpClientInterface.php
│   └── HttpClientResponse.php
└── Acp/
    ├── AcpCheckoutServiceInterface.php
    ├── AbstractAcpCheckoutService.php
    ├── AcpResponseFormatterInterface.php
    ├── AcpResponseFormatter.php
    ├── AcpProductServiceInterface.php
    └── Tool/
        ├── CreateCheckoutTool.php
        ├── GetCheckoutTool.php
        ├── UpdateCheckoutTool.php
        ├── CompleteCheckoutTool.php
        ├── CancelCheckoutTool.php
        └── ListProductsTool.php
```

---

### A1. MCP Server Infrastructure

#### A1.1 McpToolInterface

**File:** `payment-component/src/Mcp/McpToolInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp;

interface McpToolInterface
{
    /**
     * Unique tool name (e.g., 'create_checkout').
     */
    public function getName(): string;

    /**
     * Human-readable description shown to AI agents.
     */
    public function getDescription(): string;

    /**
     * JSON Schema defining the tool's input parameters.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with validated arguments.
     *
     * @param array<string, mixed> $arguments Validated input
     * @param AgentContext $agentContext Authenticated agent
     * @return array<string, mixed> Tool result (MCP content format)
     */
    public function execute(array $arguments, AgentContext $agentContext): array;
}
```

#### A1.2 AgentContext (Value Object)

**File:** `payment-component/src/Mcp/AgentContext.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp;

/**
 * Immutable value object representing an authenticated AI agent.
 */
readonly class AgentContext
{
    public function __construct(
        private string $agentId,
        private string $token,
        private array $metadata = []
    ) {}

    public function getAgentId(): string
    {
        return $this->agentId;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }
}
```

#### A1.3 McpServerInterface

**File:** `payment-component/src/Mcp/McpServerInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp;

interface McpServerInterface
{
    /**
     * Handle a JSON-RPC 2.0 request string and return the response payload.
     *
     * Supported methods: initialize, tools/list, tools/call
     *
     * @param string $rawJsonRpc Raw JSON-RPC request body
     * @param AgentContext $agentContext Authenticated agent
     * @return array<string, mixed> JSON-RPC 2.0 response
     */
    public function handleJsonRpc(string $rawJsonRpc, AgentContext $agentContext): array;
}
```

#### A1.4 McpServer

**File:** `payment-component/src/Mcp/McpServer.php`

JSON-RPC 2.0 router with tagged tool registry. No provider knowledge.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp;

class McpServer implements McpServerInterface
{
    private const PROTOCOL_VERSION = '2025-06-18';

    /** @var array<string, McpToolInterface> */
    private array $tools;

    private string $serverName;
    private string $serverVersion;

    /**
     * @param iterable<McpToolInterface> $taggedTools Collected via !tagged_iterator
     * @param string $serverName Configurable per provider module
     * @param string $serverVersion Configurable per provider module
     */
    public function __construct(
        iterable $taggedTools,
        string $serverName = 'oxid-payment-mcp',
        string $serverVersion = '1.0.0'
    ) {
        $this->serverName = $serverName;
        $this->serverVersion = $serverVersion;
        $this->tools = [];
        foreach ($taggedTools as $tool) {
            $this->tools[$tool->getName()] = $tool;
        }
    }

    public function handleJsonRpc(string $rawJsonRpc, AgentContext $agentContext): array
    {
        $request = $this->parseRequest($rawJsonRpc);
        if ($request === null) {
            return $this->errorResponse(null, -32700, 'Parse error');
        }

        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        return match ($method) {
            'initialize' => $this->handleInitialize($id, $params),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $params, $agentContext),
            default => $this->errorResponse($id, -32601, "Method not found: {$method}"),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseRequest(string $rawJsonRpc): ?array
    {
        try {
            $decoded = json_decode($rawJsonRpc, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleInitialize(int|string|null $id, array $params): array
    {
        $clientVersion = $params['protocolVersion'] ?? '';

        return $this->successResponse($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => true],
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsList(int|string|null $id): array
    {
        $toolList = [];
        foreach ($this->tools as $tool) {
            $toolList[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return $this->successResponse($id, ['tools' => $toolList]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(
        int|string|null $id,
        array $params,
        AgentContext $agentContext
    ): array {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$toolName])) {
            return $this->errorResponse($id, -32602, "Unknown tool: {$toolName}");
        }

        try {
            $result = $this->tools[$toolName]->execute($arguments, $agentContext);
            return $this->successResponse($id, [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($id, -32000, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function successResponse(int|string|null $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(int|string|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
```

#### A1.5 McpAuthGuardInterface

**File:** `payment-component/src/Mcp/Auth/McpAuthGuardInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

interface McpAuthGuardInterface
{
    /**
     * Authenticate an incoming MCP request.
     *
     * @return AuthResult Contains success/failure and AgentContext on success
     */
    public function authenticate(): AuthResult;
}
```

#### A1.6 AuthResult (Value Object)

**File:** `payment-component/src/Mcp/Auth/AuthResult.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\AgentContext;

readonly class AuthResult
{
    private function __construct(
        private bool $authenticated,
        private ?AgentContext $agentContext,
        private ?string $errorMessage
    ) {}

    public static function success(AgentContext $agentContext): self
    {
        return new self(true, $agentContext, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function getAgentContext(): AgentContext
    {
        if ($this->agentContext === null) {
            throw new \LogicException('Cannot get agent context from failed auth result');
        }
        return $this->agentContext;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
```

#### A1.7 McpAuthGuard (Bearer Token)

**File:** `payment-component/src/Mcp/Auth/McpAuthGuard.php`

Provider-agnostic. The expected token is injected as a string parameter — the provider module's `services.yaml` resolves where the token comes from (module config, env var, etc.).

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\AgentContext;

class McpAuthGuard implements McpAuthGuardInterface
{
    /**
     * @param string $expectedToken Injected via DI from provider module config
     */
    public function __construct(
        private readonly string $expectedToken
    ) {}

    public function authenticate(): AuthResult
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return AuthResult::failed('Missing Bearer token');
        }

        $token = substr($header, 7);

        if ($this->expectedToken === '' || !hash_equals($this->expectedToken, $token)) {
            return AuthResult::failed('Invalid token');
        }

        return AuthResult::success(new AgentContext(
            agentId: $this->deriveAgentId($token),
            token: $token
        ));
    }

    /**
     * Derive a stable agent identifier from the token.
     * Uses first 8 chars of SHA-256 hash — not sensitive, just an ID.
     */
    private function deriveAgentId(string $token): string
    {
        return 'agent_' . substr(hash('sha256', $token), 0, 8);
    }
}
```

---

### A2. ACP Response Formatting

#### A2.1 AcpResponseFormatterInterface

**File:** `payment-component/src/Mcp/Acp/AcpResponseFormatterInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

interface AcpResponseFormatterInterface
{
    /**
     * Format a contract as an ACP checkout response.
     *
     * @return array<string, mixed> ACP-compliant checkout JSON
     */
    public function formatCheckout(PaymentContractInterface $contract): array;

    /**
     * Format a completed checkout as an ACP order response.
     *
     * @return array<string, mixed> ACP order JSON with id, checkout_session_id, permalink_url
     */
    public function formatOrder(PaymentContractInterface $contract, string $orderPermalink): array;

    /**
     * Format a not-found error.
     *
     * @return array<string, mixed> ACP error response
     */
    public function notFoundError(string $checkoutId): array;

    /**
     * Format a validation error.
     *
     * @return array<string, mixed> ACP error response
     */
    public function validationError(string $message, ?string $param = null): array;
}
```

#### A2.2 AcpResponseFormatter

**File:** `payment-component/src/Mcp/Acp/AcpResponseFormatter.php`

Reads exclusively from `PaymentContractInterface` and `BasketSnapshot` — both payment-component types. Zero provider knowledge.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

class AcpResponseFormatter implements AcpResponseFormatterInterface
{
    /**
     * @var array<string, string> Provider names to include in payment_providers.
     *                            Injected via DI so each provider adds itself.
     */
    private array $supportedProviders;

    /**
     * @param array<string, string[]> $paymentProviders e.g. [['provider'=>'stripe','supported_payment_methods'=>['card']]]
     */
    public function __construct(
        private readonly array $paymentProviders = []
    ) {}

    public function formatCheckout(PaymentContractInterface $contract): array
    {
        $snapshot = $contract->getBasketSnapshot();

        return [
            'id' => $contract->getId(),
            'status' => $this->mapContractStateToAcpStatus($contract->getStateValue()),
            'currency' => strtolower($snapshot->currency),
            'line_items' => $this->formatLineItems($snapshot),
            'totals' => $this->formatTotals($snapshot),
            'payment_providers' => $this->paymentProviders,
        ];
    }

    public function formatOrder(PaymentContractInterface $contract, string $orderPermalink): array
    {
        return [
            'id' => $contract->getOrderId(),
            'checkout_session_id' => $contract->getId(),
            'permalink_url' => $orderPermalink,
        ];
    }

    public function notFoundError(string $checkoutId): array
    {
        return [
            'error' => [
                'type' => 'invalid_request',
                'message' => "Checkout not found: {$checkoutId}",
            ],
        ];
    }

    public function validationError(string $message, ?string $param = null): array
    {
        $error = [
            'type' => 'invalid_request',
            'message' => $message,
            'code' => 'invalid',
        ];
        if ($param !== null) {
            $error['param'] = $param;
        }
        return ['error' => $error];
    }

    /**
     * Contract state → ACP checkout status.
     *
     * ACP defines: not_ready_for_payment, ready_for_payment, completed, canceled
     * Note: ACP uses American spelling 'canceled' (one 'l').
     */
    private function mapContractStateToAcpStatus(string $contractState): string
    {
        return match ($contractState) {
            'draft', 'not_finished' => 'not_ready_for_payment',
            'pending', 'authorized' => 'ready_for_payment',
            'ready_to_commit', 'committed', 'fulfilled' => 'completed',
            'cancelled', 'expired', 'failed' => 'canceled',
            default => 'not_ready_for_payment',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatLineItems(BasketSnapshot $snapshot): array
    {
        $lineItems = [];
        foreach ($snapshot->items as $index => $item) {
            $lineItems[] = [
                'id' => 'li_' . ($index + 1),
                'item' => [
                    'id' => $item['articleId'] ?? $item['id'] ?? '',
                    'quantity' => (int) ($item['quantity'] ?? 1),
                ],
                'base_amount' => $this->toMinorUnits($item['grossPrice'] ?? $item['price'] ?? 0.0),
                'subtotal' => $this->toMinorUnits($item['netPrice'] ?? $item['price'] ?? 0.0),
                'tax' => $this->toMinorUnits($item['vatValue'] ?? 0.0),
                'total' => $this->toMinorUnits($item['grossPrice'] ?? $item['price'] ?? 0.0),
            ];
        }
        return $lineItems;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatTotals(BasketSnapshot $snapshot): array
    {
        return [
            ['type' => 'subtotal', 'amount' => $this->toMinorUnits($snapshot->totalNet)],
            ['type' => 'tax', 'amount' => $this->toMinorUnits($snapshot->totalVat)],
            ['type' => 'total', 'amount' => $this->toMinorUnits($snapshot->totalGross)],
        ];
    }

    /**
     * Convert float amount to integer minor units (cents).
     * ACP amounts are always integers in the smallest currency unit.
     */
    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
```

---

### A3. ACP Checkout Service (Abstract)

#### A3.1 AcpCheckoutServiceInterface

**File:** `payment-component/src/Mcp/Acp/AcpCheckoutServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

use OxidEsales\PaymentComponent\Mcp\AgentContext;

interface AcpCheckoutServiceInterface
{
    /**
     * Create a new checkout session from ACP request data.
     *
     * @param array<string, mixed> $arguments ACP create_checkout input
     * @param AgentContext $agentContext Authenticated agent
     * @return array<string, mixed> ACP checkout response
     */
    public function createCheckout(array $arguments, AgentContext $agentContext): array;

    /**
     * Get checkout session status.
     *
     * @return array<string, mixed> ACP checkout response or error
     */
    public function getCheckout(string $checkoutId): array;

    /**
     * Update checkout session (shipping, options).
     *
     * @param array<string, mixed> $data Update fields
     * @return array<string, mixed> ACP checkout response or error
     */
    public function updateCheckout(string $checkoutId, array $data, AgentContext $agentContext): array;

    /**
     * Complete checkout with delegated payment token.
     *
     * @param array<string, mixed> $paymentData Token, provider, billing address
     * @return array<string, mixed> ACP order response or error
     */
    public function completeCheckout(
        string $checkoutId,
        array $paymentData,
        AgentContext $agentContext
    ): array;

    /**
     * Cancel a checkout session.
     *
     * @return array<string, mixed> ACP checkout response (status: canceled)
     */
    public function cancelCheckout(string $checkoutId): array;
}
```

#### A3.2 AbstractAcpCheckoutService

**File:** `payment-component/src/Mcp/Acp/AbstractAcpCheckoutService.php`

Implements create/get/update/cancel using payment-component interfaces. Only `completePayment()` is abstract — provider modules implement it with their token-based payment flow.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;

abstract class AbstractAcpCheckoutService implements AcpCheckoutServiceInterface
{
    public function __construct(
        protected readonly ContractServiceInterface $contractService,
        protected readonly ContractRepositoryInterface $contractRepository,
        protected readonly EventDispatcherInterface $eventDispatcher,
        protected readonly AcpResponseFormatterInterface $formatter
    ) {}

    public function getCheckout(string $checkoutId): array
    {
        $contract = $this->contractRepository->findById($checkoutId);
        if ($contract === null) {
            return $this->formatter->notFoundError($checkoutId);
        }

        return $this->formatter->formatCheckout($contract);
    }

    public function updateCheckout(string $checkoutId, array $data, AgentContext $agentContext): array
    {
        $contract = $this->contractRepository->findById($checkoutId);
        if ($contract === null) {
            return $this->formatter->notFoundError($checkoutId);
        }

        // Store update data as contract metadata
        foreach ($data as $key => $value) {
            $contract->setMetadata('acp_' . $key, $value);
        }

        // Store selected fulfillment option if provided
        if (isset($data['selected_fulfillment_option_id'])) {
            $contract->setMetadata(
                'fulfillment_option',
                $data['selected_fulfillment_option_id']
            );
        }

        $this->contractRepository->save($contract);

        return $this->formatter->formatCheckout($contract);
    }

    public function cancelCheckout(string $checkoutId): array
    {
        $contract = $this->contractRepository->findById($checkoutId);
        if ($contract === null) {
            return $this->formatter->notFoundError($checkoutId);
        }

        if ($contract->getState()->isTerminal()) {
            return $this->formatter->validationError(
                'Checkout is already in a terminal state',
                'checkout_id'
            );
        }

        $contract->cancel();
        $this->contractRepository->save($contract);

        return $this->formatter->formatCheckout($contract);
    }

    /**
     * Provider-specific payment confirmation.
     *
     * Called by completeCheckout() after contract validation.
     * Stripe implements this with SPT → PaymentIntent.
     * Other providers implement with their own token flow.
     *
     * @param PaymentContractInterface $contract Validated, non-terminal contract
     * @param array<string, mixed> $paymentData Token, provider, billing address
     * @param AgentContext $agentContext Authenticated agent
     * @return array<string, mixed> ACP order response or error
     */
    abstract protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContext $agentContext
    ): array;

    public function completeCheckout(
        string $checkoutId,
        array $paymentData,
        AgentContext $agentContext
    ): array {
        $contract = $this->contractRepository->findById($checkoutId);
        if ($contract === null) {
            return $this->formatter->notFoundError($checkoutId);
        }

        if ($contract->getState()->isTerminal()) {
            return $this->formatter->validationError(
                'Checkout is already in a terminal state',
                'checkout_id'
            );
        }

        $token = $paymentData['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return $this->formatter->validationError(
                'Payment token is required',
                'payment_data.token'
            );
        }

        // Store agent info on contract
        $contract->setMetadata('acp_agent_id', $agentContext->getAgentId());
        $contract->setMetadata('acp_completed_at', time());
        $this->contractRepository->save($contract);

        return $this->completePayment($contract, $paymentData, $agentContext);
    }
}
```

---

### A4. ACP Product Service Interface

**File:** `payment-component/src/Mcp/Acp/AcpProductServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

interface AcpProductServiceInterface
{
    /**
     * List available products in ACP format.
     *
     * @param array<string, mixed> $filters Optional filters (category, search, limit, offset)
     * @return array<string, mixed> ACP product list
     */
    public function listProducts(array $filters = []): array;

    /**
     * Get a single product by ID in ACP format.
     *
     * @return array<string, mixed>|null ACP product or null if not found
     */
    public function getProduct(string $productId): ?array;
}
```

---

### A5. ACP Tool Definitions

All 6 tools live in payment-component. They are thin wrappers — each delegates to `AcpCheckoutServiceInterface` or `AcpProductServiceInterface`. The provider-specific logic is in the service implementation, not the tool.

#### A5.1 CreateCheckoutTool

**File:** `payment-component/src/Mcp/Acp/Tool/CreateCheckoutTool.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class CreateCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {}

    public function getName(): string
    {
        return 'create_checkout';
    }

    public function getDescription(): string
    {
        return 'Create an ACP checkout session for the given items and buyer information';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'Products to purchase',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'Product/article ID'],
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'required' => ['id', 'quantity'],
                    ],
                ],
                'buyer' => [
                    'type' => 'object',
                    'description' => 'Buyer information',
                    'properties' => [
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'phone_number' => ['type' => 'string'],
                    ],
                    'required' => ['email'],
                ],
                'fulfillment_address' => [
                    'type' => 'object',
                    'description' => 'Shipping address',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'line_one' => ['type' => 'string'],
                        'line_two' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'state' => ['type' => 'string'],
                        'country' => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2'],
                        'postal_code' => ['type' => 'string'],
                    ],
                    'required' => ['line_one', 'city', 'country', 'postal_code'],
                ],
                'currency' => [
                    'type' => 'string',
                    'description' => 'ISO 4217 currency code',
                    'default' => 'EUR',
                ],
            ],
            'required' => ['items', 'buyer'],
        ];
    }

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        return $this->checkoutService->createCheckout($arguments, $agentContext);
    }
}
```

#### A5.2 GetCheckoutTool

**File:** `payment-component/src/Mcp/Acp/Tool/GetCheckoutTool.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class GetCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {}

    public function getName(): string
    {
        return 'get_checkout';
    }

    public function getDescription(): string
    {
        return 'Retrieve the current status of a checkout session';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checkout_id' => [
                    'type' => 'string',
                    'description' => 'Checkout session ID',
                ],
            ],
            'required' => ['checkout_id'],
        ];
    }

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        return $this->checkoutService->getCheckout($arguments['checkout_id']);
    }
}
```

#### A5.3 UpdateCheckoutTool

**File:** `payment-component/src/Mcp/Acp/Tool/UpdateCheckoutTool.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class UpdateCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {}

    public function getName(): string
    {
        return 'update_checkout';
    }

    public function getDescription(): string
    {
        return 'Update a checkout session with shipping selection or other options';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checkout_id' => [
                    'type' => 'string',
                    'description' => 'Checkout session ID',
                ],
                'selected_fulfillment_option_id' => [
                    'type' => 'string',
                    'description' => 'Selected shipping/delivery option ID',
                ],
            ],
            'required' => ['checkout_id'],
        ];
    }

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        $checkoutId = $arguments['checkout_id'];
        unset($arguments['checkout_id']);

        return $this->checkoutService->updateCheckout($checkoutId, $arguments, $agentContext);
    }
}
```

#### A5.4 CompleteCheckoutTool

**File:** `payment-component/src/Mcp/Acp/Tool/CompleteCheckoutTool.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class CompleteCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {}

    public function getName(): string
    {
        return 'complete_checkout';
    }

    public function getDescription(): string
    {
        return 'Complete checkout and process payment using a delegated payment token';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checkout_id' => [
                    'type' => 'string',
                    'description' => 'Checkout session ID',
                ],
                'payment_data' => [
                    'type' => 'object',
                    'description' => 'Delegated payment credentials',
                    'properties' => [
                        'token' => [
                            'type' => 'string',
                            'description' => 'Delegated payment token from payment provider',
                        ],
                        'provider' => [
                            'type' => 'string',
                            'description' => 'Payment provider name',
                        ],
                        'billing_address' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'line_one' => ['type' => 'string'],
                                'line_two' => ['type' => 'string'],
                                'city' => ['type' => 'string'],
                                'state' => ['type' => 'string'],
                                'country' => ['type' => 'string'],
                                'postal_code' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'required' => ['token', 'provider'],
                ],
            ],
            'required' => ['checkout_id', 'payment_data'],
        ];
    }

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        return $this->checkoutService->completeCheckout(
            $arguments['checkout_id'],
            $arguments['payment_data'],
            $agentContext
        );
    }
}
```

#### A5.5 CancelCheckoutTool

**File:** `payment-component/src/Mcp/Acp/Tool/CancelCheckoutTool.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class CancelCheckoutTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {}

    public function getName(): string
    {
        return 'cancel_checkout';
    }

    public function getDescription(): string
    {
        return 'Cancel an active checkout session';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'checkout_id' => [
                    'type' => 'string',
                    'description' => 'Checkout session ID',
                ],
            ],
            'required' => ['checkout_id'],
        ];
    }

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        return $this->checkoutService->cancelCheckout($arguments['checkout_id']);
    }
}
```

#### A5.6 ListProductsTool

**File:** `payment-component/src/Mcp/Acp/Tool/ListProductsTool.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp\Tool;

use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;

class ListProductsTool implements McpToolInterface
{
    public function __construct(
        private readonly AcpProductServiceInterface $productService
    ) {}

    public function getName(): string
    {
        return 'list_products';
    }

    public function getDescription(): string
    {
        return 'Search and list available products in the shop catalog';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Search query for product title or description',
                ],
                'category_id' => [
                    'type' => 'string',
                    'description' => 'Filter by category ID',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset',
                    'default' => 0,
                    'minimum' => 0,
                ],
            ],
        ];
    }

    public function execute(array $arguments, AgentContext $agentContext): array
    {
        return $this->productService->listProducts($arguments);
    }
}
```

---

### A6. Contract State → ACP Status Mapping

This mapping lives in `AcpResponseFormatter` and is the canonical reference:

| Contract State | ACP Status | Rationale |
|---------------|------------|-----------|
| `draft` | `not_ready_for_payment` | Contract just created, no session yet |
| `not_finished` | `not_ready_for_payment` | Order created but payment not ready |
| `pending` | `ready_for_payment` | Awaiting payment |
| `authorized` | `ready_for_payment` | Payment authorized, awaiting capture |
| `ready_to_commit` | `completed` | All conditions met |
| `committed` | `completed` | Order finalized |
| `fulfilled` | `completed` | Payment captured |
| `cancelled` | `canceled` | Cancelled by agent or system |
| `expired` | `canceled` | Session timed out |
| `failed` | `canceled` | Payment failed |

**Note:** ACP uses American spelling `canceled` (one 'l').

---

### A7. MCP Request Event + Handler

The McpController (in stripe) must NOT call McpServer directly — it follows the strict event-only pattern. The event/handler pair lives in payment-component so any provider module can use it.

#### A7.1 McpRequestReceivedEvent

**File:** `payment-component/src/Mcp/Event/McpRequestReceivedEvent.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Event;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;

class McpRequestReceivedEvent
{
    public function __construct(
        private readonly EventContext $context
    ) {}

    public function getContext(): EventContext
    {
        return $this->context;
    }
}
```

#### A7.2 McpRequestHandler

**File:** `payment-component/src/Mcp/Handler/McpRequestHandler.php`

Routes the MCP JSON-RPC request through `McpServerInterface`. This handler does the actual work — the controller only dispatches.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Event\McpRequestReceivedEvent;
use OxidEsales\PaymentComponent\Mcp\McpServerInterface;

class McpRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly McpServerInterface $mcpServer
    ) {}

    public static function getHandledEventClass(): string
    {
        return McpRequestReceivedEvent::class;
    }

    public function handle(object $event): void
    {
        /** @var McpRequestReceivedEvent $event */
        $context = $event->getContext();
        $rawJsonRpc = $context->get('rawJsonRpc');
        $agentContext = $context->get('agentContext');

        $response = $this->mcpServer->handleJsonRpc($rawJsonRpc, $agentContext);

        $context->set('mcpResponse', $response);
    }
}
```

---

### A8. HttpClientInterface

Abstraction for outbound HTTP calls. Used by Sprint 50 (agent notifications) and Sprint 52 (token introspection). Lives in payment-component because any provider module may need outbound HTTP. Stripe module provides the concrete implementation (Guzzle or cURL-based).

#### A8.1 HttpClientInterface

**File:** `payment-component/src/Mcp/Http/HttpClientInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Http;

interface HttpClientInterface
{
    /**
     * Send an HTTP POST request.
     *
     * @param string $url Target URL
     * @param string $body Request body
     * @param array<string, string> $headers Request headers
     * @param int $timeoutSeconds Request timeout
     */
    public function post(
        string $url,
        string $body,
        array $headers = [],
        int $timeoutSeconds = 10
    ): HttpClientResponse;

    /**
     * Send an HTTP GET request.
     *
     * @param string $url Target URL
     * @param array<string, string> $headers Request headers
     * @param int $timeoutSeconds Request timeout
     */
    public function get(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 10
    ): HttpClientResponse;
}
```

#### A8.2 HttpClientResponse

**File:** `payment-component/src/Mcp/Http/HttpClientResponse.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Http;

readonly class HttpClientResponse
{
    public function __construct(
        private int $statusCode,
        private string $body,
        private ?string $error = null
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isSuccessful(): bool
    {
        return $this->error === null && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public static function failed(string $error): self
    {
        return new self(0, '', $error);
    }
}
```

---

### A9. payment-component File Summary

| # | File | Purpose | Est. Lines |
|---|------|---------|-----------|
| 1 | `src/Mcp/McpServerInterface.php` | Server contract | ~15 |
| 2 | `src/Mcp/McpServer.php` | JSON-RPC routing, tool registry | ~135 |
| 3 | `src/Mcp/McpToolInterface.php` | Tool contract | ~25 |
| 4 | `src/Mcp/AgentContext.php` | Agent value object | ~35 |
| 5 | `src/Mcp/Auth/McpAuthGuardInterface.php` | Auth contract | ~12 |
| 6 | `src/Mcp/Auth/McpAuthGuard.php` | Bearer token validation | ~40 |
| 7 | `src/Mcp/Auth/AuthResult.php` | Auth result value object | ~35 |
| 8 | `src/Mcp/Event/McpRequestReceivedEvent.php` | MCP request event | ~20 |
| 9 | `src/Mcp/Handler/McpRequestHandler.php` | MCP request → McpServer | ~30 |
| 10 | `src/Mcp/Http/HttpClientInterface.php` | HTTP client contract | ~25 |
| 11 | `src/Mcp/Http/HttpClientResponse.php` | HTTP response value object | ~35 |
| 12 | `src/Mcp/Acp/AcpCheckoutServiceInterface.php` | Checkout operations contract | ~35 |
| 13 | `src/Mcp/Acp/AbstractAcpCheckoutService.php` | Base implementation (4/5 methods) | ~100 |
| 14 | `src/Mcp/Acp/AcpResponseFormatterInterface.php` | Formatter contract | ~25 |
| 15 | `src/Mcp/Acp/AcpResponseFormatter.php` | Contract → ACP response | ~120 |
| 16 | `src/Mcp/Acp/AcpProductServiceInterface.php` | Product service contract | ~18 |
| 17 | `src/Mcp/Acp/Tool/CreateCheckoutTool.php` | ACP create checkout | ~75 |
| 18 | `src/Mcp/Acp/Tool/GetCheckoutTool.php` | ACP get checkout | ~35 |
| 19 | `src/Mcp/Acp/Tool/UpdateCheckoutTool.php` | ACP update checkout | ~45 |
| 20 | `src/Mcp/Acp/Tool/CompleteCheckoutTool.php` | ACP complete checkout | ~60 |
| 21 | `src/Mcp/Acp/Tool/CancelCheckoutTool.php` | ACP cancel checkout | ~35 |
| 22 | `src/Mcp/Acp/Tool/ListProductsTool.php` | Product listing | ~50 |
| | **Total payment-component** | | **~1,030** |

---

## Part B: stripe Module Changes

**Namespace:** `OxidEsales\Payments\Stripe\Mcp\`

### New Directory Structure

```
stripe/src/Stripe/Mcp/
├── Controller/
│   └── McpController.php
├── Http/
│   └── CurlHttpClient.php
└── Service/
    ├── StripeAcpCheckoutService.php
    ├── SptPaymentServiceInterface.php
    ├── SptPaymentService.php
    └── SptPaymentResult.php
```

---

### B1. McpController (OXID Entry Point)

**File:** `stripe/src/Stripe/Mcp/Controller/McpController.php`

Thin HTTP entry — **event-only pattern**. Validates input, creates EventContext, dispatches event, reads response from context. Does NOT call McpServer directly.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Event\McpRequestReceivedEvent;

class McpController
{
    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function handleRequest(): void
    {
        // 1. Validate auth
        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32000, 'message' => $authResult->getErrorMessage()],
            ]);
            return;
        }

        // 2. Validate input
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || $rawBody === '') {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Empty request body'],
            ]);
            return;
        }

        // 3. Create context — ONLY DATA, NO LOGIC
        $context = new EventContext([
            'rawJsonRpc' => $rawBody,
            'agentContext' => $authResult->getAgentContext(),
        ]);

        // 4. Dispatch event — HANDLERS DO THE WORK
        $event = new McpRequestReceivedEvent($context);
        $this->eventDispatcher->dispatch($event);

        // 5. Read result from context
        $response = $context->get('mcpResponse') ?? [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32603, 'message' => 'No handler produced a response'],
        ];

        header('Content-Type: application/json');
        echo json_encode($response, JSON_THROW_ON_ERROR);
    }
}
```

**metadata.php addition:**
```php
'controllers' => [
    // ... existing controllers ...
    'stripemcp' => \OxidEsales\Payments\Stripe\Mcp\Controller\McpController::class,
],
```

**New module setting:**
```php
// In metadata.php settings, group STRIPE_GENERAL:
[
    'name' => 'sStripeAgentApiKey',
    'type' => 'str',
    'value' => '',
],
```

---

### B2. StripeAcpCheckoutService

**File:** `stripe/src/Stripe/Mcp/Service/StripeAcpCheckoutService.php`

Extends `AbstractAcpCheckoutService`. Implements `createCheckout()` (dispatches Stripe-specific event) and `completePayment()` (SPT → PaymentIntent).

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AbstractAcpCheckoutService;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpResponseFormatterInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\ShopAdapterInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;

class StripeAcpCheckoutService extends AbstractAcpCheckoutService
{
    public function __construct(
        ContractServiceInterface $contractService,
        ContractRepositoryInterface $contractRepository,
        EventDispatcherInterface $eventDispatcher,
        AcpResponseFormatterInterface $formatter,
        private readonly SptPaymentServiceInterface $sptPaymentService,
        private readonly ShopAdapterInterface $shopAdapter
    ) {
        parent::__construct($contractService, $contractRepository, $eventDispatcher, $formatter);
    }

    public function createCheckout(array $arguments, AgentContext $agentContext): array
    {
        // Build context from ACP arguments
        $context = new EventContext([
            'acp_items' => $arguments['items'] ?? [],
            'acp_buyer' => $arguments['buyer'] ?? [],
            'acp_fulfillment_address' => $arguments['fulfillment_address'] ?? [],
            'acp_currency' => $arguments['currency'] ?? 'EUR',
            'acp_agent_id' => $agentContext->getAgentId(),
            'source' => 'acp',
        ]);

        // Dispatch the same event as the browser checkout flow
        // This triggers: StripeContractCreationHandler (100)
        //                 → EarlyOrderCreationHandler (100)
        //                 → StripeCheckoutSessionHandler (0)
        $event = new StripeCheckoutSessionRequestEvent($context);
        $this->eventDispatcher->dispatch($event);

        // Read contract from context (set by StripeContractCreationHandler)
        $contract = $context->getContract();
        if ($contract === null) {
            return $this->formatter->validationError('Failed to create checkout session');
        }

        return $this->formatter->formatCheckout($contract);
    }

    protected function completePayment(
        PaymentContractInterface $contract,
        array $paymentData,
        AgentContext $agentContext
    ): array {
        $sptToken = $paymentData['token'];
        $billingAddress = $paymentData['billing_address'] ?? [];

        // Confirm payment via SPT
        $result = $this->sptPaymentService->confirmWithSpt($contract, $sptToken, $billingAddress);

        if (!$result->isSuccessful()) {
            return $this->formatter->validationError(
                $result->getErrorMessage() ?? 'Payment failed'
            );
        }

        // Dispatch PaymentAuthorizedEvent to enter existing handler chain
        // This triggers: PaymentAuthorizedEventHandler (90)
        //                → ContractReadyToCommitEvent
        //                    → StripeOrderCreationHandler (80)
        //                → FraudCheckHandler (85)
        $context = new EventContext([
            'paymentIntentId' => $result->getPaymentIntentId(),
            'source' => 'acp_spt',
        ]);
        $context->setContract($contract);

        $authorizedEvent = new PaymentAuthorizedEvent($contract, $context);
        $this->eventDispatcher->dispatch($authorizedEvent);

        // Build order permalink
        $orderId = $contract->getOrderId();
        $orderPermalink = $this->shopAdapter->getShopUrl() . '?cl=order_confirm&order=' . $orderId;

        return $this->formatter->formatOrder($contract, $orderPermalink);
    }
}
```

---

### B3. SptPaymentService

**File:** `stripe/src/Stripe/Mcp/Service/SptPaymentServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

interface SptPaymentServiceInterface
{
    /**
     * Confirm a PaymentIntent using a Shared Payment Token.
     *
     * @param PaymentContractInterface $contract The contract to pay for
     * @param string $sptToken Granted SPT token (spt_granted_*)
     * @param array<string, mixed> $billingAddress Optional billing address
     */
    public function confirmWithSpt(
        PaymentContractInterface $contract,
        string $sptToken,
        array $billingAddress = []
    ): SptPaymentResult;
}
```

**File:** `stripe/src/Stripe/Mcp/Service/SptPaymentService.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;

class SptPaymentService implements SptPaymentServiceInterface
{
    public function __construct(
        private readonly StripeAdapterInterface $stripeAdapter,
        private readonly ?FileLoggerInterface $requestLogger = null
    ) {}

    public function confirmWithSpt(
        PaymentContractInterface $contract,
        string $sptToken,
        array $billingAddress = []
    ): SptPaymentResult {
        $this->requestLogger?->log('SptPaymentService: Confirming with SPT', [
            'contractId' => $contract->getId(),
            'tokenPrefix' => substr($sptToken, 0, 15) . '...',
        ]);

        try {
            $params = [
                'amount' => $this->toMinorUnits($contract->getAmount()),
                'currency' => strtolower($contract->getCurrency()),
                'shared_payment_granted_token' => $sptToken,
                'metadata' => [
                    'contract_id' => $contract->getId(),
                    'order_id' => $contract->getOrderId() ?? '',
                    'source' => 'acp',
                ],
            ];

            $paymentIntent = $this->stripeAdapter->createPaymentIntent($params);

            $this->requestLogger?->log('SptPaymentService: PaymentIntent created', [
                'paymentIntentId' => $paymentIntent->id,
                'status' => $paymentIntent->status,
            ]);

            if ($paymentIntent->status === 'succeeded' || $paymentIntent->status === 'requires_capture') {
                return SptPaymentResult::success($paymentIntent->id, $paymentIntent->status);
            }

            return SptPaymentResult::failed(
                "Unexpected PaymentIntent status: {$paymentIntent->status}",
                $paymentIntent->id
            );
        } catch (\Throwable $e) {
            $this->requestLogger?->log('SptPaymentService: SPT confirmation failed', [
                'error' => $e->getMessage(),
            ]);

            return SptPaymentResult::failed($e->getMessage());
        }
    }

    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
```

**File:** `stripe/src/Stripe/Mcp/Service/SptPaymentResult.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

readonly class SptPaymentResult
{
    private function __construct(
        private bool $successful,
        private ?string $paymentIntentId,
        private ?string $status,
        private ?string $errorMessage
    ) {}

    public static function success(string $paymentIntentId, string $status): self
    {
        return new self(true, $paymentIntentId, $status, null);
    }

    public static function failed(string $errorMessage, ?string $paymentIntentId = null): self
    {
        return new self(false, $paymentIntentId, null, $errorMessage);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getPaymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
```

---

### B4. CurlHttpClient (HttpClientInterface Implementation)

**File:** `stripe/src/Stripe/Mcp/Http/CurlHttpClient.php`

Concrete implementation of `HttpClientInterface`. cURL-based — no Guzzle dependency.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Http;

use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientResponse;

class CurlHttpClient implements HttpClientInterface
{
    public function post(
        string $url,
        string $body,
        array $headers = [],
        int $timeoutSeconds = 10
    ): HttpClientResponse {
        return $this->sendRequest('POST', $url, $body, $headers, $timeoutSeconds);
    }

    public function get(
        string $url,
        array $headers = [],
        int $timeoutSeconds = 10
    ): HttpClientResponse {
        return $this->sendRequest('GET', $url, '', $headers, $timeoutSeconds);
    }

    private function sendRequest(
        string $method,
        string $url,
        string $body,
        array $headers,
        int $timeoutSeconds
    ): HttpClientResponse {
        $ch = curl_init($url);

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_HTTPHEADER => $curlHeaders,
        ]);

        if ($method === 'POST' && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return HttpClientResponse::failed($error);
        }

        return new HttpClientResponse($httpCode, is_string($response) ? $response : '');
    }
}
```

---

### B5. WebhookController Refactoring (Event-Only)

**Existing file:** `stripe/src/Stripe/Controller/Webhook/WebhookController.php`

The existing WebhookController calls `StripeWebhookProcessor::process()` directly — this violates the "controllers can only emit events" rule. Refactor it to follow the same event-only pattern as McpController.

**Before (current):**
```php
// WebhookController calls processor directly:
$this->webhookProcessor->process($rawBody, $signatureHeader);
```

**After (refactored):**
```php
// WebhookController emits event, handler calls processor:
$context = new EventContext([
    'rawBody' => $rawBody,
    'signatureHeader' => $signatureHeader,
]);
$event = new WebhookPayloadReceivedEvent($context);
$this->eventDispatcher->dispatch($event);
$statusCode = $context->get('httpStatusCode') ?? 200;
```

New event: `WebhookPayloadReceivedEvent` (same pattern as `McpRequestReceivedEvent`)
New handler: `WebhookPayloadHandler` — calls `StripeWebhookProcessor::process()`

**Note:** This is a refactoring of existing code, not new functionality. The behavior is unchanged — only the dispatch path changes.

---

### B6. services.yaml Wiring (stripe)

```yaml
# === MCP Server (payment-component classes, wired by stripe) ===

OxidEsales\PaymentComponent\Mcp\McpServerInterface:
    class: OxidEsales\PaymentComponent\Mcp\McpServer
    arguments:
        $taggedTools: !tagged_iterator payment.mcp_tool
        $serverName: 'oxid-stripe-acp'
        $serverVersion: '1.0.0'
    public: true

OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface:
    class: OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuard
    arguments:
        $expectedToken: '%stripe.agent_api_key%'

# === MCP Event Handler (event-only controller pattern) ===

OxidEsales\PaymentComponent\Mcp\Handler\McpRequestHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

# === HTTP Client (payment-component interface → stripe implementation) ===

OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Http\CurlHttpClient

# === ACP Tools (payment-component classes, tagged by stripe) ===

OxidEsales\PaymentComponent\Mcp\Acp\Tool\CreateCheckoutTool:
    tags: [{ name: payment.mcp_tool }]

OxidEsales\PaymentComponent\Mcp\Acp\Tool\GetCheckoutTool:
    tags: [{ name: payment.mcp_tool }]

OxidEsales\PaymentComponent\Mcp\Acp\Tool\UpdateCheckoutTool:
    tags: [{ name: payment.mcp_tool }]

OxidEsales\PaymentComponent\Mcp\Acp\Tool\CompleteCheckoutTool:
    tags: [{ name: payment.mcp_tool }]

OxidEsales\PaymentComponent\Mcp\Acp\Tool\CancelCheckoutTool:
    tags: [{ name: payment.mcp_tool }]

OxidEsales\PaymentComponent\Mcp\Acp\Tool\ListProductsTool:
    tags: [{ name: payment.mcp_tool }]

# === ACP Services ===

OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\StripeAcpCheckoutService

OxidEsales\PaymentComponent\Mcp\Acp\AcpResponseFormatterInterface:
    class: OxidEsales\PaymentComponent\Mcp\Acp\AcpResponseFormatter
    arguments:
        $paymentProviders:
            - { provider: 'stripe', supported_payment_methods: ['card'] }

OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\StripeAcpProductService

# === Stripe-specific SPT Service ===

OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentServiceInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentService
    arguments:
        $requestLogger: '@stripe.request_file_logger'

# === Parameter resolved from module config ===

parameters:
    stripe.agent_api_key: ''  # Resolved at runtime from sStripeAgentApiKey
```

---

### B7. stripe File Summary

| # | File | Purpose | Est. Lines |
|---|------|---------|-----------|
| 1 | `src/Stripe/Mcp/Controller/McpController.php` | HTTP entry point (event-only) | ~50 |
| 2 | `src/Stripe/Mcp/Http/CurlHttpClient.php` | HttpClientInterface implementation | ~55 |
| 3 | `src/Stripe/Mcp/Service/StripeAcpCheckoutService.php` | Stripe-specific checkout (SPT + event dispatch) | ~110 |
| 4 | `src/Stripe/Mcp/Service/SptPaymentServiceInterface.php` | SPT service contract | ~18 |
| 5 | `src/Stripe/Mcp/Service/SptPaymentService.php` | SPT → PaymentIntent confirmation | ~75 |
| 6 | `src/Stripe/Mcp/Service/SptPaymentResult.php` | SPT result value object | ~40 |
| | **Total stripe (new)** | | **~348** |
| | **Modified: metadata.php** | Controller + setting | ~10 |
| | **Modified: services.yaml** | Wiring + tags + handler + HttpClient | ~55 |
| | **Modified: WebhookController.php** | Refactored to event-only | ~20 |

---

## Combined Totals

| Module | New Files | New Lines | Modified Files | Modified Lines |
|--------|-----------|-----------|----------------|---------------|
| payment-component | 22 | ~1,030 | 0 | 0 |
| stripe | 6 | ~348 | 3 | ~85 |
| **Total** | **28** | **~1,378** | **3** | **~85** |
| **Test files** | ~14 | **~800** | | |

Split: **75% payment-component / 25% stripe** — protocol infrastructure is provider-agnostic, only payment confirmation is Stripe-specific.

**New vs Sprint 47 v1:** +5 files, +230 lines for event-only controller pattern, HttpClientInterface, WebhookController refactoring.

---

## TDD Approach

### Step 1: McpServer Unit Tests (RED → GREEN)

**Location:** `payment-component/tests/Unit/Mcp/McpServerTest.php`

Test JSON-RPC routing: `initialize`, `tools/list`, `tools/call`, unknown method, malformed JSON.

```php
class McpServerTest extends TestCase
{
    public function testInitializeReturnsProtocolVersion(): void
    {
        $server = new McpServer([], 'test-server', '1.0.0');
        $response = $server->handleJsonRpc(
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
            new AgentContext('agent_1', 'token')
        );
        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
        $this->assertSame('test-server', $response['result']['serverInfo']['name']);
    }

    public function testToolsListReturnsRegisteredTools(): void { ... }
    public function testToolsCallExecutesTool(): void { ... }
    public function testToolsCallReturnsErrorForUnknownTool(): void { ... }
    public function testUnknownMethodReturnsError(): void { ... }
    public function testMalformedJsonReturnsParseError(): void { ... }
}
```

### Step 2: Auth Guard Tests (RED → GREEN)

**Location:** `payment-component/tests/Unit/Mcp/Auth/McpAuthGuardTest.php`

Test: missing header, malformed header, wrong token, empty expected token, valid token.

### Step 3: AuthResult + AgentContext Tests (RED → GREEN)

**Location:** `payment-component/tests/Unit/Mcp/Auth/AuthResultTest.php`

Test: success factory, failed factory, getAgentContext throws on failed, getErrorMessage.

### Step 4: AcpResponseFormatter Tests (RED → GREEN)

**Location:** `payment-component/tests/Unit/Mcp/Acp/AcpResponseFormatterTest.php`

Test every contract state → ACP status mapping. Test line item formatting from BasketSnapshot. Test totals with minor unit conversion. Test error formatters.

### Step 5: AbstractAcpCheckoutService Tests (RED → GREEN)

**Location:** `payment-component/tests/Unit/Mcp/Acp/AbstractAcpCheckoutServiceTest.php`

Use a concrete test subclass that implements `completePayment()` as a stub. Test getCheckout, updateCheckout, cancelCheckout. Test terminal state rejection. Test not-found handling.

### Step 6: Individual Tool Tests (RED → GREEN)

**Location:** `payment-component/tests/Unit/Mcp/Acp/Tool/`

Each tool gets its own test class. Mock `AcpCheckoutServiceInterface` to verify correct argument delegation.

### Step 7: StripeAcpCheckoutService Tests (RED → GREEN)

**Location:** `stripe/tests/Unit/Stripe/Mcp/Service/StripeAcpCheckoutServiceTest.php`

Test `createCheckout()` dispatches `StripeCheckoutSessionRequestEvent`. Test `completePayment()` calls `SptPaymentService` and dispatches `PaymentAuthorizedEvent`.

### Step 8: SptPaymentService Tests (RED → GREEN)

**Location:** `stripe/tests/Unit/Stripe/Mcp/Service/SptPaymentServiceTest.php`

Test SPT → PaymentIntent creation with mocked StripeAdapter. Test success and failure paths.

### Step 9: McpController Test (RED → GREEN)

**Location:** `stripe/tests/Unit/Stripe/Mcp/Controller/McpControllerTest.php`

Test auth failure returns 401. Test empty body returns 400. Test valid request delegates to McpServer.

### Step 10: Full Validation

```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

### payment-component

- [ ] `McpServer` routes initialize, tools/list, tools/call correctly
- [ ] `McpServer` returns JSON-RPC errors for unknown methods and malformed input
- [ ] `McpAuthGuard` rejects missing/invalid/empty tokens
- [ ] `McpAuthGuard` accepts valid tokens with constant-time comparison
- [ ] `AuthResult` enforces success/failed state correctly
- [ ] `AgentContext` is immutable and carries agent identity
- [ ] `AcpResponseFormatter` maps all 10 contract states to correct ACP statuses
- [ ] `AcpResponseFormatter` converts amounts to minor units (cents)
- [ ] `AcpResponseFormatter` extracts line items from BasketSnapshot
- [ ] `AbstractAcpCheckoutService` get/update/cancel work via contract interfaces
- [ ] `AbstractAcpCheckoutService` rejects terminal-state contracts
- [ ] All 6 tools have valid JSON Schema input definitions
- [ ] All 6 tools delegate to correct service methods
- [ ] PHPCS, PHPStan, PHPMD pass on payment-component

### stripe

- [ ] `McpController` follows event-only pattern (dispatches `McpRequestReceivedEvent`)
- [ ] `McpController` handles auth failure, empty body, valid request
- [ ] `McpRequestHandler` routes JSON-RPC to `McpServerInterface`
- [ ] `CurlHttpClient` implements `HttpClientInterface` (POST and GET)
- [ ] `WebhookController` refactored to dispatch event instead of calling processor
- [ ] `StripeAcpCheckoutService.createCheckout()` dispatches `StripeCheckoutSessionRequestEvent`
- [ ] `StripeAcpCheckoutService.completePayment()` uses SPT and dispatches `PaymentAuthorizedEvent`
- [ ] `SptPaymentService` creates PaymentIntent with `shared_payment_granted_token`
- [ ] `SptPaymentResult` captures success/failure correctly
- [ ] `metadata.php` registers `stripemcp` controller
- [ ] `metadata.php` adds `sStripeAgentApiKey` setting
- [ ] `services.yaml` wires all interfaces and tags all tools
- [ ] All 799+ existing tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| MCP PHP SDK is pre-v1.0 | Medium | Build our own thin JSON-RPC layer — no SDK dependency |
| SPT API is new, may have edge cases | High | Comprehensive test coverage, `SptPaymentResult` captures all states |
| `StripeCheckoutSessionRequestEvent` may need ACP-specific context keys | Medium | Existing handlers ignore unknown context keys — no breakage |
| OXID article queries may be slow for large catalogs | Low | Pagination in `list_products`, defer optimization |
| Agent auth via simple Bearer token may be insufficient | Medium | Start simple, add OAuth in future sprint. Interface allows swap |
| ACP spec may evolve (latest: 2026-01-30) | Medium | Interface-based design — swap implementations without changing tools |
| payment-component has no services.yaml — stripe must wire everything | Low | This is the existing pattern (stripe wires all payment-component services) |

---

## Acceptance Criteria

1. An MCP client can connect to `https://shop.example.com/index.php?cl=stripemcp` and complete `initialize` handshake
2. `tools/list` returns 6 tools with valid JSON Schema input definitions
3. `create_checkout` creates a contract via existing event chain and returns ACP-formatted response
4. `get_checkout` returns current checkout state in ACP format
5. `complete_checkout` accepts an SPT token, creates PaymentIntent, and advances contract through existing handler chain
6. `cancel_checkout` cancels the contract and returns `canceled` status
7. `list_products` returns OXID articles in ACP product format
8. All 799+ existing tests continue to pass
9. PHPCS, PHPStan (level max), PHPMD pass with zero new violations
10. An Unzer module could reuse all payment-component classes by only implementing `AbstractAcpCheckoutService.completePayment()` and wiring `services.yaml`
