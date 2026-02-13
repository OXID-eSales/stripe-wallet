# Sprint 47: ACP + UCP + MCP Support — Two-Module Implementation

**Date:** 2026-02-11 (merged from Sprint 47 + Sprint 53)
**Status:** TODO
**Priority:** High (New Feature Track)
**Prerequisites:** Sprint 46 completed (all quality gates green, 799 tests)
**Principle:** Build the thinnest possible layer that connects ACP/MCP and UCP protocols to the existing event-driven architecture — no new state machines, no parallel checkout flows. Both ACP (Stripe+OpenAI) and UCP (Google) are supported from day one, sharing the same contract backend. Provider-agnostic infrastructure goes to payment-component; only Stripe-specific payment confirmation stays in stripe.

**Merge rationale:** Originally UCP was Sprint 53 (Low priority, after OAuth). But ACP and UCP serve the same purpose — agentic checkout — and share the same backend (`AbstractAcpCheckoutService`). Building both from the start avoids retrofitting UCP later and ensures the architecture is protocol-agnostic from the beginning.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | SRP, OCP, LSP, ISP, DIP in every class |
| DI | Depend on abstractions, wire via services.yaml |
| LSP | Subtypes must be substitutable for their base types |
| DRY | Reuse existing event chain — no duplicate checkout logic; UCP reuses `AbstractAcpCheckoutService` |
| No Overengineering | No custom condition types, no product feed yet; UCP REST binding only — no gRPC, no A2A |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

**Testing Command:**
```bash
./bin/pre-commit-check.sh --full
```

---

## Objective

Add an ACP/MCP + UCP layer that allows AI agents to:

1. **Discover** available checkout tools and shop resources via MCP (Anthropic/OpenAI agents) or `/.well-known/ucp` (Google agents)
2. **Negotiate capabilities** (UCP only — dynamic capability intersection)
3. **Create** checkout sessions via ACP tools (MCP `tools/call`) or UCP REST endpoints
4. **Query** checkout status via contract state
5. **Complete** checkout using delegated payment tokens (SPT)
6. **Cancel** checkout sessions

The implementation spans **two modules**:

- **payment-component** — MCP server infrastructure, ACP tool definitions, UCP profile/capability/response formatting, abstract checkout service (provider-agnostic)
- **stripe** — SPT payment confirmation, MCP + UCP controller registration, services.yaml wiring (provider-specific)

### What This Sprint Covers

**MCP/ACP (from original Sprint 47):**
- MCP server infrastructure in payment-component (JSON-RPC routing, tool registry, auth)
- ACP tool definitions in payment-component (6 tools with JSON Schema)
- ACP response formatting in payment-component (contract → ACP JSON)
- Abstract checkout service in payment-component (create/get/update/cancel via contract interfaces)
- SPT payment confirmation in stripe (Shared Payment Token → PaymentIntent)
- MCP controller registration in stripe (OXID metadata.php entry point)

**UCP (from original Sprint 53):**
- UCP profile and discovery in payment-component (`/.well-known/ucp`)
- UCP capability negotiation in payment-component
- UCP response formatting in payment-component (contract → UCP JSON)
- UCP request validation in payment-component (UCP headers)
- UCP REST controllers in stripe (profile + checkout endpoints)
- UCP event + handler in stripe (event-only pattern)

**Shared:**
- services.yaml wiring in stripe (binds interfaces, tags tools, wires UCP)
- HttpClientInterface in payment-component + CurlHttpClient in stripe
- WebhookController refactored to event-only pattern

### What This Sprint Does NOT Cover (future sprints)

- Product feed specification (Sprint 48)
- Custom ContractCondition types for agent-specific conditions (Sprint 49)
- Webhook delivery to AI agents (Sprint 50)
- Stripe's hosted ACP endpoint integration (Sprint 51)
- OAuth agent authentication (Sprint 52)

---

## ACP vs UCP: Side-by-Side

| Aspect | ACP (Stripe+OpenAI) | UCP (Google) |
|--------|---------------------|-------------|
| Discovery | Manual agent onboarding | `/.well-known/ucp` auto-discovery |
| Transport | MCP JSON-RPC 2.0 over HTTP | REST (POST/GET/PUT) |
| Negotiation | Fixed capability set | Dynamic capability intersection |
| Checkout states | `not_ready_for_payment`, `ready_for_payment`, `completed`, `canceled` | `incomplete`, `requires_escalation`, `ready_for_complete`, `completed`, `canceled` |
| Payment | SPT (Stripe-specific) | Payment handler abstraction (multi-PSP) |
| Auth | `Authorization: Bearer` | `Authorization: Bearer` + `UCP-Agent`, `Request-Id`, `Idempotency-Key` |
| Extensibility | Spec versions | Reverse-domain namespaced extensions |

**Key insight:** Both protocols share the same backend:

```
MCP tools/call ──→ AcpCheckoutServiceInterface ──→ AbstractAcpCheckoutService
                                                    ├── ContractService
                                                    ├── ContractRepository
                                                    └── EventDispatcher

UCP REST ────────→ AcpCheckoutServiceInterface ──→ AbstractAcpCheckoutService
                                                    (same backend)
```

Only the **protocol translation layer** differs — ACP uses MCP JSON-RPC, UCP uses REST with different headers and response formatting.

---

## Architecture Decision: MCP-First, ACP-as-Tools

ACP can be implemented as either REST endpoints or MCP tools. We choose **MCP-first**:

| Approach | Pros | Cons |
|----------|------|------|
| REST endpoints | Standard HTTP, easy to test with curl | Separate auth, separate discovery, another controller layer |
| **MCP tools** (chosen) | Single protocol, built-in discovery, auth handled by MCP | Requires MCP server infrastructure |

**Rationale:** MCP provides tool discovery for free — agents call `tools/list` and get all checkout capabilities with input schemas. No separate API documentation needed. ACP checkout operations become MCP tools with JSON Schema input validation.

UCP by contrast requires REST — Google's spec mandates `/.well-known/ucp` discovery and standard HTTP methods. Both coexist.

---

## Architecture Overview

```
┌──────────────────────────────────────────────────────────────────┐
│  AI Agents                                                        │
│  ┌────────────────────┐  ┌─────────────────────────────────┐     │
│  │ Claude / ChatGPT   │  │ Gemini / Google AI Mode          │     │
│  │ (MCP Client)       │  │ (UCP Client)                     │     │
│  └──────────┬─────────┘  └──────────────┬──────────────────┘     │
└─────────────┼───────────────────────────┼────────────────────────┘
              │ JSON-RPC 2.0              │ REST + UCP headers
┌─────────────▼───────────────────────────▼────────────────────────┐
│  stripe module                                                    │
│  ┌──────────────────────┐  ┌──────────────────────────────┐      │
│  │ McpController        │  │ UcpProfileController          │      │
│  │ (stripemcp)          │  │ (stripeucpprofile)            │      │
│  │  └─ event dispatch   │  │  └─ /.well-known/ucp          │      │
│  └──────────┬───────────┘  │                               │      │
│             │              │ UcpCheckoutController          │      │
│             │              │ (stripeucp)                    │      │
│             │              │  └─ event dispatch              │      │
│             │              └──────────────┬─────────────────┘      │
│  ┌──────────▼────────────────────────────▼───────────────────┐    │
│  │ StripeAcpCheckoutService                                   │    │
│  │ (extends AbstractAcpCheckoutService)                       │    │
│  │  └─ completePayment() → SptPaymentService → StripeAdapter │    │
│  └────────────────────────────────────────────────────────────┘    │
│  services.yaml: wires all interfaces, tags tools, binds UCP       │
└───────────────────────────┬───────────────────────────────────────┘
                            │ uses
┌───────────────────────────▼───────────────────────────────────────┐
│  payment-component (provider-agnostic)                             │
│                                                                    │
│  ┌─────────────────┐ ┌─────────────────┐ ┌────────────────────┐  │
│  │ McpServer        │ │ McpAuthGuard    │ │ AcpResponseFormatter│  │
│  │ (JSON-RPC router │ │ (Bearer token)  │ │ (contract → ACP)   │  │
│  │  + tool registry)│ │                 │ │                    │  │
│  └────────┬────────┘ └─────────────────┘ └────────────────────┘  │
│           │                                                       │
│  ┌────────▼──────────────────────────────────────────────────┐   │
│  │ ACP Tools (6 tools, tagged via services.yaml)              │   │
│  │ create_checkout | get_checkout | update_checkout            │   │
│  │ complete_checkout | cancel_checkout | list_products         │   │
│  └────────────────────────────┬──────────────────────────────┘   │
│                                │                                  │
│  ┌─────────────────┐ ┌────────▼──────────────────────────────┐   │
│  │ UcpProfile       │ │ AbstractAcpCheckoutService            │   │
│  │ UcpCapability    │ │  create/get/update/cancel             │   │
│  │ UcpNegotiation   │ │  completePayment() → abstract        │   │
│  │ UcpResponseFmt   │ └──────────────────────────────────────┘   │
│  │ UcpRequestVal    │                                            │
│  └─────────────────┘                                             │
│                                                                    │
│  Existing: ContractService, ContractRepository, EventDispatcher    │
└────────────────────────────────────────────────────────────────────┘
```

---

## Boundary Rule Applied

The test: **"Could this work with PayPal/Unzer instead of Stripe?"**

### MCP/ACP Components

| Component | Provider-Agnostic? | Module | Rationale |
|-----------|-------------------|--------|-----------|
| `McpServer` (JSON-RPC router) | Yes | payment-component | Any provider module can reuse MCP infrastructure |
| `McpToolInterface` | Yes | payment-component | Tool contract is protocol-level, not provider-level |
| `McpAuthGuard` (Bearer token) | Yes | payment-component | Token injected as DI param — no module config dependency |
| `AgentContext`, `AuthResult` | Yes | payment-component | Value objects carry agent identity, not provider data |
| ACP tool classes (6 tools) | Yes | payment-component | Schemas follow ACP standard; delegate to service interfaces |
| `AcpResponseFormatter` | Yes | payment-component | Reads `PaymentContractInterface` + `BasketSnapshot` |
| `AbstractAcpCheckoutService` | Yes | payment-component | create/get/update/cancel use contract interfaces |
| `AcpProductServiceInterface` | Yes | payment-component | Product listing is shop-level |
| `HttpClientInterface` | Yes | payment-component | Abstraction for outbound HTTP |
| `StripeAcpCheckoutService` | **No** | stripe | Implements `completePayment()` with SPT → PaymentIntent |
| `SptPaymentService` | **No** | stripe | SPT is a Stripe-only payment primitive |
| `McpController` | **No** | stripe | OXID controller registered in stripe's `metadata.php` |
| `CurlHttpClient` | **No** | stripe | Concrete HTTP client implementation |

### UCP Components

| Component | Provider-Agnostic? | Module | Rationale |
|-----------|-------------------|--------|-----------|
| `UcpProfileInterface` / `UcpProfile` | Yes | payment-component | Profile structure is protocol-level |
| `UcpCapability` | Yes | payment-component | Value object, no provider knowledge |
| `UcpCapabilityNegotiationService` | Yes | payment-component | Intersection algorithm is protocol-level |
| `UcpResponseFormatterInterface` / `UcpResponseFormatter` | Yes | payment-component | Contract → UCP response mapping |
| `UcpRequestValidator` | Yes | payment-component | Header validation is protocol-level |
| `UcpProfileController` | **No** | stripe | OXID controller in stripe's `metadata.php` |
| `UcpCheckoutController` | **No** | stripe | OXID controller in stripe's `metadata.php` |
| `UcpCheckoutRequestEvent` + Handler | **No** | stripe | Stripe-specific event-only wiring |

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
├── Acp/
│   ├── AcpCheckoutServiceInterface.php
│   ├── AbstractAcpCheckoutService.php
│   ├── AcpResponseFormatterInterface.php
│   ├── AcpResponseFormatter.php
│   ├── AcpProductServiceInterface.php
│   └── Tool/
│       ├── CreateCheckoutTool.php
│       ├── GetCheckoutTool.php
│       ├── UpdateCheckoutTool.php
│       ├── CompleteCheckoutTool.php
│       ├── CancelCheckoutTool.php
│       └── ListProductsTool.php
└── Ucp/
    ├── UcpProfileInterface.php
    ├── UcpProfile.php
    ├── UcpCapability.php
    ├── UcpCapabilityNegotiationService.php
    ├── UcpResponseFormatterInterface.php
    ├── UcpResponseFormatter.php
    └── UcpRequestValidator.php
```

### A1–A8: MCP/ACP Infrastructure (unchanged from original Sprint 47)

All MCP server infrastructure, ACP tools, response formatting, abstract checkout service, auth, HTTP client — exactly as specified in the original Sprint 47 document. See [sprint-47-acp-mcp-support.md](../../20260209/todo/sprint-47-acp-mcp-support.md) sections A1–A8 for full code listings.

Summary:
- A1: MCP Server (McpToolInterface, AgentContext, McpServerInterface, McpServer, McpAuthGuard, AuthResult)
- A2: ACP Response Formatting (AcpResponseFormatterInterface, AcpResponseFormatter)
- A3: Abstract Checkout Service (AcpCheckoutServiceInterface, AbstractAcpCheckoutService)
- A4: ACP Product Service Interface
- A5: ACP Tools (6 tools: create, get, update, complete, cancel, list_products)
- A6: Contract State → ACP Status Mapping
- A7: MCP Request Event + Handler (event-only controller pattern)
- A8: HttpClientInterface + HttpClientResponse

### A9: UCP Profile and Discovery

#### A9.1 UcpCapability (Value Object)

**File:** `payment-component/src/Mcp/Ucp/UcpCapability.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

readonly class UcpCapability
{
    public function __construct(
        private string $name,
        private string $version,
        private ?string $spec = null,
        private array $extensions = []
    ) {}

    public function getName(): string { return $this->name; }
    public function getVersion(): string { return $this->version; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'version' => $this->version,
        ];

        if ($this->spec !== null) {
            $result['spec'] = $this->spec;
        }

        if (!empty($this->extensions)) {
            $result['extensions'] = array_map(
                fn (UcpCapability $ext) => $ext->toArray(),
                $this->extensions
            );
        }

        return $result;
    }
}
```

#### A9.2 UcpProfileInterface

**File:** `payment-component/src/Mcp/Ucp/UcpProfileInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

interface UcpProfileInterface
{
    /** @return array<string, mixed> */
    public function toArray(): array;

    /** @return array<UcpCapability> */
    public function getCapabilities(): array;
}
```

#### A9.3 UcpProfile

**File:** `payment-component/src/Mcp/Ucp/UcpProfile.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

class UcpProfile implements UcpProfileInterface
{
    private const UCP_VERSION = '2026-01-11';

    /**
     * @param string $restEndpoint UCP REST endpoint URL
     * @param array<UcpCapability> $capabilities Supported capabilities
     * @param array<array{id: string, spec: string, version: string}> $paymentHandlers
     */
    public function __construct(
        private readonly string $restEndpoint,
        private readonly array $capabilities,
        private readonly array $paymentHandlers = []
    ) {}

    public function toArray(): array
    {
        return [
            'ucp_version' => self::UCP_VERSION,
            'services' => [
                'dev.ucp.shopping' => [
                    'rest' => [
                        'endpoint' => $this->restEndpoint,
                    ],
                ],
            ],
            'capabilities' => array_map(
                fn (UcpCapability $cap) => $cap->toArray(),
                $this->capabilities
            ),
            'payment' => [
                'handlers' => $this->paymentHandlers,
            ],
        ];
    }

    public function getCapabilities(): array
    {
        return $this->capabilities;
    }
}
```

### A10: UCP Capability Negotiation

**File:** `payment-component/src/Mcp/Ucp/UcpCapabilityNegotiationService.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

class UcpCapabilityNegotiationService
{
    /**
     * @param array<UcpCapability> $businessCapabilities
     * @param array<array{name: string, version: string}> $agentCapabilities
     * @return array<UcpCapability>
     */
    public function negotiate(array $businessCapabilities, array $agentCapabilities): array
    {
        $agentNames = array_column($agentCapabilities, 'name');
        $agentMap = array_combine($agentNames, $agentCapabilities);

        $negotiated = [];
        foreach ($businessCapabilities as $capability) {
            if (isset($agentMap[$capability->getName()])) {
                $negotiated[] = $capability;
            }
        }

        return $negotiated;
    }
}
```

### A11: UCP Response Formatting

#### A11.1 UcpResponseFormatterInterface

**File:** `payment-component/src/Mcp/Ucp/UcpResponseFormatterInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

interface UcpResponseFormatterInterface
{
    /** @return array<string, mixed> */
    public function formatCheckoutSession(PaymentContractInterface $contract): array;

    /** @return array<string, mixed> */
    public function formatError(string $type, string $message, ?string $param = null): array;
}
```

#### A11.2 UcpResponseFormatter

**File:** `payment-component/src/Mcp/Ucp/UcpResponseFormatter.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

class UcpResponseFormatter implements UcpResponseFormatterInterface
{
    public function formatCheckoutSession(PaymentContractInterface $contract): array
    {
        $snapshot = $contract->getBasketSnapshot();

        return [
            'id' => $contract->getId(),
            'status' => $this->mapContractStateToUcpStatus($contract->getStateValue()),
            'currency' => strtolower($snapshot->currency),
            'line_items' => $this->formatLineItems($snapshot),
            'totals' => [
                'subtotal' => $this->toMinorUnits($snapshot->totalNet),
                'tax' => $this->toMinorUnits($snapshot->totalVat),
                'total' => $this->toMinorUnits($snapshot->totalGross),
            ],
        ];
    }

    public function formatError(string $type, string $message, ?string $param = null): array
    {
        $error = ['type' => $type, 'message' => $message];
        if ($param !== null) {
            $error['param'] = $param;
        }
        return ['error' => $error];
    }

    /**
     * Contract state → UCP checkout status.
     * UCP: incomplete, requires_escalation, ready_for_complete, completed, canceled
     */
    private function mapContractStateToUcpStatus(string $contractState): string
    {
        return match ($contractState) {
            'draft', 'not_finished', 'pending' => 'incomplete',
            'authorized' => 'ready_for_complete',
            'ready_to_commit', 'committed', 'fulfilled' => 'completed',
            'cancelled', 'expired', 'failed' => 'canceled',
            default => 'incomplete',
        };
    }

    private function formatLineItems(mixed $snapshot): array
    {
        $lineItems = [];
        foreach ($snapshot->items as $index => $item) {
            $lineItems[] = [
                'id' => 'li_' . ($index + 1),
                'product_id' => $item['articleId'] ?? $item['id'] ?? '',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'unit_price' => $this->toMinorUnits($item['grossPrice'] ?? $item['price'] ?? 0.0),
                'total' => $this->toMinorUnits(
                    ($item['grossPrice'] ?? $item['price'] ?? 0.0) * (int) ($item['quantity'] ?? 1)
                ),
            ];
        }
        return $lineItems;
    }

    private function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
```

### A12: UCP Request Validation

**File:** `payment-component/src/Mcp/Ucp/UcpRequestValidator.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

class UcpRequestValidator
{
    /**
     * @param array<string, string> $headers
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateHeaders(array $headers): array
    {
        $errors = [];

        if (empty($headers['request-id'])) {
            $errors[] = 'Missing required header: Request-Id';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Extract agent profile URL from UCP-Agent header.
     * Format: UCP-Agent: profile="https://..."
     */
    public function extractAgentProfile(array $headers): ?string
    {
        $ucpAgent = $headers['ucp-agent'] ?? '';
        if (preg_match('/profile="([^"]+)"/', $ucpAgent, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
```

### A13: Contract State Mapping — ACP vs UCP

| Contract State | ACP Status | UCP Status |
|---------------|------------|------------|
| `draft` | `not_ready_for_payment` | `incomplete` |
| `not_finished` | `not_ready_for_payment` | `incomplete` |
| `pending` | `ready_for_payment` | `incomplete` |
| `authorized` | `ready_for_payment` | `ready_for_complete` |
| `ready_to_commit` | `completed` | `completed` |
| `committed` | `completed` | `completed` |
| `fulfilled` | `completed` | `completed` |
| `cancelled` | `canceled` | `canceled` |
| `expired` | `canceled` | `canceled` |
| `failed` | `canceled` | `canceled` |

---

## Part B: stripe Module Changes

**Namespace:** `OxidEsales\Payments\Stripe\Mcp\`

### New Directory Structure

```
stripe/src/Stripe/Mcp/
├── Controller/
│   ├── McpController.php
│   ├── UcpProfileController.php
│   └── UcpCheckoutController.php
├── Event/
│   └── UcpCheckoutRequestEvent.php
├── Handler/
│   └── UcpCheckoutRequestHandler.php
├── Http/
│   └── CurlHttpClient.php
└── Service/
    ├── StripeAcpCheckoutService.php
    ├── SptPaymentServiceInterface.php
    ├── SptPaymentService.php
    └── SptPaymentResult.php
```

### B1–B5: MCP/ACP stripe components (unchanged from original Sprint 47)

All MCP controller, StripeAcpCheckoutService, SptPaymentService, CurlHttpClient, WebhookController refactoring — exactly as specified in the original Sprint 47 document. See [sprint-47-acp-mcp-support.md](../../20260209/todo/sprint-47-acp-mcp-support.md) sections B1–B5 for full code listings.

### B6: UcpProfileController

**File:** `stripe/src/Stripe/Mcp/Controller/UcpProfileController.php`

Serves `/.well-known/ucp` for auto-discovery.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfileInterface;

class UcpProfileController
{
    public function __construct(
        private readonly UcpProfileInterface $profile
    ) {}

    public function handleRequest(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=3600');
        echo json_encode($this->profile->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
```

### B7: UcpCheckoutController (Event-Only)

**File:** `stripe/src/Stripe/Mcp/Controller/UcpCheckoutController.php`

REST controller following the strict event-only pattern.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatterInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\UcpCheckoutRequestEvent;

class UcpCheckoutController
{
    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly UcpRequestValidator $requestValidator,
        private readonly UcpResponseFormatterInterface $responseFormatter,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function handleRequest(): void
    {
        // 1. Validate auth
        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            $this->jsonResponse(401, $this->responseFormatter->formatError(
                'authentication_error',
                $authResult->getErrorMessage() ?? 'Unauthorized'
            ));
            return;
        }

        // 2. Validate UCP headers
        $headers = $this->extractHeaders();
        $validation = $this->requestValidator->validateHeaders($headers);
        if (!$validation['valid']) {
            $this->jsonResponse(400, $this->responseFormatter->formatError(
                'invalid_request',
                implode(', ', $validation['errors'])
            ));
            return;
        }

        // 3. Create context — ONLY DATA, NO LOGIC
        $method = $_SERVER['REQUEST_METHOD'];
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        $segments = array_values(array_filter(explode('/', $pathInfo)));
        $rawBody = file_get_contents('php://input');

        $context = new EventContext([
            'httpMethod' => $method,
            'pathSegments' => $segments,
            'requestBody' => json_decode($rawBody ?: '{}', true) ?? [],
            'agentContext' => $authResult->getAgentContext(),
            'ucpHeaders' => $headers,
        ]);

        // 4. Dispatch event — HANDLER DOES THE WORK
        $event = new UcpCheckoutRequestEvent($context);
        $this->eventDispatcher->dispatch($event);

        // 5. Read result from context
        $statusCode = $context->get('httpStatusCode') ?? 200;
        $responseData = $context->get('responseData') ?? $this->responseFormatter->formatError(
            'internal_error',
            'No handler produced a response'
        );

        $this->jsonResponse($statusCode, $responseData);
    }

    /** @return array<string, string> */
    private function extractHeaders(): array
    {
        return [
            'request-id' => $_SERVER['HTTP_REQUEST_ID'] ?? '',
            'ucp-agent' => $_SERVER['HTTP_UCP_AGENT'] ?? '',
            'idempotency-key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
        ];
    }

    /** @param array<string, mixed> $data */
    private function jsonResponse(int $statusCode, array $data): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_THROW_ON_ERROR);
    }
}
```

### B8: UcpCheckoutRequestEvent + Handler

**File:** `stripe/src/Stripe/Mcp/Event/UcpCheckoutRequestEvent.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Event;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;

class UcpCheckoutRequestEvent
{
    public function __construct(private readonly EventContext $context) {}

    public function getContext(): EventContext
    {
        return $this->context;
    }
}
```

**File:** `stripe/src/Stripe/Mcp/Handler/UcpCheckoutRequestHandler.php`

Routes UCP REST requests to the shared `AcpCheckoutServiceInterface`.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\UcpCheckoutRequestEvent;

class UcpCheckoutRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly AcpCheckoutServiceInterface $checkoutService
    ) {}

    public static function getHandledEventClass(): string
    {
        return UcpCheckoutRequestEvent::class;
    }

    public function handle(object $event): void
    {
        /** @var UcpCheckoutRequestEvent $event */
        $context = $event->getContext();
        $method = $context->get('httpMethod');
        $segments = $context->get('pathSegments');
        $body = $context->get('requestBody');
        $agentContext = $context->get('agentContext');

        [$statusCode, $responseData] = match (true) {
            $method === 'POST' && count($segments) === 1
                => [201, $this->checkoutService->createCheckout($body, $agentContext)],
            $method === 'GET' && count($segments) === 2
                => [200, $this->checkoutService->getCheckout($segments[1])],
            $method === 'PUT' && count($segments) === 2
                => [200, $this->checkoutService->updateCheckout($segments[1], $body, $agentContext)],
            $method === 'POST' && count($segments) === 3 && $segments[2] === 'complete'
                => [200, $this->checkoutService->completeCheckout($segments[1], $body['payment_data'] ?? [], $agentContext)],
            $method === 'POST' && count($segments) === 3 && $segments[2] === 'cancel'
                => [200, $this->checkoutService->cancelCheckout($segments[1])],
            default => [404, ['error' => ['type' => 'not_found', 'message' => 'Endpoint not found']]],
        };

        $context->set('httpStatusCode', $statusCode);
        $context->set('responseData', $responseData);
    }
}
```

### B9: services.yaml (Combined ACP + UCP)

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

# === MCP Event Handler ===

OxidEsales\PaymentComponent\Mcp\Handler\McpRequestHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

# === HTTP Client ===

OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Http\CurlHttpClient

# === ACP Tools ===

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

# === Stripe SPT Service ===

OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentServiceInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentService
    arguments:
        $requestLogger: '@stripe.request_file_logger'

# === UCP Profile ===

OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfileInterface:
    class: OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfile
    arguments:
        $restEndpoint: '%stripe.ucp.rest_endpoint%'
        $capabilities:
            - !service
              class: OxidEsales\PaymentComponent\Mcp\Ucp\UcpCapability
              arguments:
                  $name: 'dev.ucp.shopping.checkout'
                  $version: '2026-01-11'
        $paymentHandlers:
            - { id: 'stripe', spec: 'https://stripe.com/ucp-handler', version: '2026-01-11' }

OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatterInterface:
    class: OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatter

OxidEsales\PaymentComponent\Mcp\Ucp\UcpCapabilityNegotiationService: ~

OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator: ~

# === UCP Event Handler ===

OxidEsales\Payments\Stripe\Mcp\Handler\UcpCheckoutRequestHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

# === Parameters ===

parameters:
    stripe.agent_api_key: ''  # Resolved at runtime from sStripeAgentApiKey
    stripe.ucp.rest_endpoint: ''  # e.g., https://shop.example.com/index.php?cl=stripeucp
```

### B10: metadata.php Additions

```php
'controllers' => [
    // ... existing controllers ...
    'stripemcp' => \OxidEsales\Payments\Stripe\Mcp\Controller\McpController::class,
    'stripeucp' => \OxidEsales\Payments\Stripe\Mcp\Controller\UcpCheckoutController::class,
    'stripeucpprofile' => \OxidEsales\Payments\Stripe\Mcp\Controller\UcpProfileController::class,
],
```

New module setting:
```php
[
    'name' => 'sStripeAgentApiKey',
    'type' => 'str',
    'value' => '',
],
```

---

## Combined File Summary

### payment-component (29 new files)

| # | File | Purpose | Est. Lines |
|---|------|---------|-----------|
| 1 | `Mcp/McpServerInterface.php` | Server contract | ~15 |
| 2 | `Mcp/McpServer.php` | JSON-RPC routing, tool registry | ~135 |
| 3 | `Mcp/McpToolInterface.php` | Tool contract | ~25 |
| 4 | `Mcp/AgentContext.php` | Agent value object | ~35 |
| 5 | `Mcp/Auth/McpAuthGuardInterface.php` | Auth contract | ~12 |
| 6 | `Mcp/Auth/McpAuthGuard.php` | Bearer token validation | ~40 |
| 7 | `Mcp/Auth/AuthResult.php` | Auth result value object | ~35 |
| 8 | `Mcp/Event/McpRequestReceivedEvent.php` | MCP request event | ~20 |
| 9 | `Mcp/Handler/McpRequestHandler.php` | MCP request → McpServer | ~30 |
| 10 | `Mcp/Http/HttpClientInterface.php` | HTTP client contract | ~25 |
| 11 | `Mcp/Http/HttpClientResponse.php` | HTTP response value object | ~35 |
| 12 | `Mcp/Acp/AcpCheckoutServiceInterface.php` | Checkout operations contract | ~35 |
| 13 | `Mcp/Acp/AbstractAcpCheckoutService.php` | Base implementation (4/5 methods) | ~100 |
| 14 | `Mcp/Acp/AcpResponseFormatterInterface.php` | Formatter contract | ~25 |
| 15 | `Mcp/Acp/AcpResponseFormatter.php` | Contract → ACP response | ~120 |
| 16 | `Mcp/Acp/AcpProductServiceInterface.php` | Product service contract | ~18 |
| 17 | `Mcp/Acp/Tool/CreateCheckoutTool.php` | ACP create checkout | ~75 |
| 18 | `Mcp/Acp/Tool/GetCheckoutTool.php` | ACP get checkout | ~35 |
| 19 | `Mcp/Acp/Tool/UpdateCheckoutTool.php` | ACP update checkout | ~45 |
| 20 | `Mcp/Acp/Tool/CompleteCheckoutTool.php` | ACP complete checkout | ~60 |
| 21 | `Mcp/Acp/Tool/CancelCheckoutTool.php` | ACP cancel checkout | ~35 |
| 22 | `Mcp/Acp/Tool/ListProductsTool.php` | Product listing | ~50 |
| 23 | `Mcp/Ucp/UcpCapability.php` | Capability value object | ~35 |
| 24 | `Mcp/Ucp/UcpProfileInterface.php` | Profile contract | ~18 |
| 25 | `Mcp/Ucp/UcpProfile.php` | /.well-known/ucp data | ~50 |
| 26 | `Mcp/Ucp/UcpCapabilityNegotiationService.php` | Capability intersection | ~30 |
| 27 | `Mcp/Ucp/UcpResponseFormatterInterface.php` | Response contract | ~18 |
| 28 | `Mcp/Ucp/UcpResponseFormatter.php` | Contract → UCP response | ~80 |
| 29 | `Mcp/Ucp/UcpRequestValidator.php` | Header validation | ~40 |
| | **Total payment-component** | | **~1,421** |

### stripe (10 new files + 3 modified)

| # | File | Purpose | Est. Lines |
|---|------|---------|-----------|
| 1 | `Mcp/Controller/McpController.php` | MCP HTTP entry point (event-only) | ~50 |
| 2 | `Mcp/Controller/UcpProfileController.php` | UCP profile endpoint | ~20 |
| 3 | `Mcp/Controller/UcpCheckoutController.php` | UCP REST checkout (event-only) | ~65 |
| 4 | `Mcp/Event/UcpCheckoutRequestEvent.php` | UCP request event | ~15 |
| 5 | `Mcp/Handler/UcpCheckoutRequestHandler.php` | UCP routing + checkout handler | ~55 |
| 6 | `Mcp/Http/CurlHttpClient.php` | HttpClientInterface implementation | ~55 |
| 7 | `Mcp/Service/StripeAcpCheckoutService.php` | Stripe checkout (SPT + event dispatch) | ~110 |
| 8 | `Mcp/Service/SptPaymentServiceInterface.php` | SPT service contract | ~18 |
| 9 | `Mcp/Service/SptPaymentService.php` | SPT → PaymentIntent confirmation | ~75 |
| 10 | `Mcp/Service/SptPaymentResult.php` | SPT result value object | ~40 |
| | **Total stripe (new)** | | **~503** |
| | **Modified: metadata.php** | 3 controllers + 1 setting | ~15 |
| | **Modified: services.yaml** | Full ACP + UCP wiring | ~85 |
| | **Modified: WebhookController.php** | Refactored to event-only | ~20 |

### Combined Totals

| Module | New Files | New Lines | Modified Files | Modified Lines |
|--------|-----------|-----------|----------------|---------------|
| payment-component | 29 | ~1,421 | 0 | 0 |
| stripe | 10 | ~503 | 3 | ~120 |
| **Total** | **39** | **~1,924** | **3** | **~120** |
| **Test files** | ~23 | **~1,150** | | |

Split: **74% payment-component / 26% stripe**

**Comparison to separate sprints:**
- Sprint 47 alone: 28 files, ~1,378 lines
- Sprint 53 alone: 11 files, ~461 lines
- Merged: 39 files, ~1,924 lines (vs 39 separate = same file count, ~85 fewer lines due to shared services.yaml)

---

## TDD Approach

### MCP/ACP Tests (Steps 1–10, from original Sprint 47)

1. McpServer Unit Tests — JSON-RPC routing
2. Auth Guard Tests — token validation
3. AuthResult + AgentContext Tests — value objects
4. AcpResponseFormatter Tests — state mapping, line items, minor units
5. AbstractAcpCheckoutService Tests — get/update/cancel, terminal state rejection
6. Individual Tool Tests — argument delegation
7. StripeAcpCheckoutService Tests — event dispatch, SPT flow
8. SptPaymentService Tests — PaymentIntent creation
9. McpController Tests — auth, body validation, delegation

### UCP Tests (Steps 10–16)

10. UcpCapability Tests — `toArray()` with/without extensions
11. UcpProfile Tests — `toArray()` matches `/.well-known/ucp` spec structure, capability list, payment handlers
12. UcpCapabilityNegotiationService Tests — intersection with matching caps, empty intersection, agent subset
13. UcpResponseFormatter Tests — all 10 contract state → UCP status mappings, line items, error formatting
14. UcpRequestValidator Tests — missing Request-Id, valid headers, agent profile extraction
15. UcpCheckoutController Tests — routing (POST/GET/PUT), auth rejection, delegation to checkout service
16. UcpCheckoutRequestHandler Tests — REST method routing, path segment parsing

### Step 17: Full Validation

```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

### payment-component — MCP/ACP

- [ ] `McpServer` routes initialize, tools/list, tools/call correctly
- [ ] `McpServer` returns JSON-RPC errors for unknown methods and malformed input
- [ ] `McpAuthGuard` rejects missing/invalid/empty tokens
- [ ] `McpAuthGuard` accepts valid tokens with constant-time comparison
- [ ] `AuthResult` enforces success/failed state correctly
- [ ] `AgentContext` is immutable and carries agent identity
- [ ] `AcpResponseFormatter` maps all 10 contract states to correct ACP statuses
- [ ] `AcpResponseFormatter` converts amounts to minor units (cents)
- [ ] `AbstractAcpCheckoutService` get/update/cancel work via contract interfaces
- [ ] `AbstractAcpCheckoutService` rejects terminal-state contracts
- [ ] All 6 tools have valid JSON Schema input definitions
- [ ] All 6 tools delegate to correct service methods

### payment-component — UCP

- [ ] `UcpProfile.toArray()` matches `/.well-known/ucp` spec structure
- [ ] `UcpCapability.toArray()` handles extensions correctly
- [ ] `UcpCapabilityNegotiationService` computes correct intersection
- [ ] `UcpResponseFormatter` maps all 10 contract states to correct UCP statuses
- [ ] `UcpRequestValidator` rejects missing `Request-Id`
- [ ] `UcpRequestValidator` extracts agent profile from `UCP-Agent` header
- [ ] PHPCS, PHPStan, PHPMD pass on payment-component

### stripe

- [ ] `McpController` follows event-only pattern
- [ ] `UcpProfileController` returns valid UCP profile JSON
- [ ] `UcpCheckoutController` follows event-only pattern
- [ ] `UcpCheckoutRequestHandler` routes POST/GET/PUT correctly
- [ ] UCP and MCP/ACP endpoints coexist without interference
- [ ] `StripeAcpCheckoutService.createCheckout()` dispatches event
- [ ] `StripeAcpCheckoutService.completePayment()` uses SPT
- [ ] `SptPaymentService` creates PaymentIntent with SPT token
- [ ] `metadata.php` registers `stripemcp`, `stripeucp`, `stripeucpprofile` controllers
- [ ] `metadata.php` adds `sStripeAgentApiKey` setting
- [ ] `services.yaml` wires all ACP + UCP interfaces and tags
- [ ] All 799+ existing tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| MCP PHP SDK is pre-v1.0 | Medium | Build our own thin JSON-RPC layer — no SDK dependency |
| SPT API is new, may have edge cases | High | Comprehensive test coverage, `SptPaymentResult` captures all states |
| UCP spec may evolve (latest: 2026-01-11) | Medium | Interface-based design — swap implementations without changing tools |
| ACP spec may evolve (latest: 2026-01-30) | Medium | Same interface isolation |
| Larger sprint scope (merged) | Medium | UCP layer is thin (~460 lines) — just protocol translation on top of existing backend |
| OXID article queries may be slow for large catalogs | Low | Pagination in `list_products`, defer optimization |
| Agent auth via simple Bearer token may be insufficient | Medium | Start simple, add OAuth in Sprint 52. Interface allows swap |
| payment-component has no services.yaml | Low | Existing pattern: stripe wires all payment-component services |

---

## Acceptance Criteria

### MCP/ACP

1. An MCP client can connect to `index.php?cl=stripemcp` and complete `initialize` handshake
2. `tools/list` returns 6 tools with valid JSON Schema input definitions
3. `create_checkout` creates a contract via existing event chain and returns ACP-formatted response
4. `get_checkout` returns current checkout state in ACP format
5. `complete_checkout` accepts an SPT token, creates PaymentIntent, and advances contract
6. `cancel_checkout` cancels the contract and returns `canceled` status
7. `list_products` returns OXID articles in ACP product format

### UCP

8. `index.php?cl=stripeucpprofile` returns valid `/.well-known/ucp` profile JSON
9. UCP checkout sessions use the same contract infrastructure as ACP
10. Contract state mapping produces correct UCP statuses
11. UCP REST endpoints (`index.php?cl=stripeucp`) follow the UCP specification structure
12. Payment completes via Stripe as UCP payment handler
13. Both ACP and UCP work simultaneously on the same shop

### Shared

14. All 799+ existing tests continue to pass
15. PHPCS, PHPStan (level max), PHPMD pass with zero new violations
16. An Unzer module could reuse all payment-component classes by only implementing `AbstractAcpCheckoutService.completePayment()` and wiring `services.yaml`
