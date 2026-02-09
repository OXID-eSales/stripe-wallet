# Sprint 53: UCP (Universal Commerce Protocol) Support

**Date:** 2026-02-09
**Status:** TODO
**Priority:** Low
**Prerequisites:** Sprint 47 (MCP/ACP), Sprint 48 (Product Feed), Sprint 52 (OAuth)
**Principle:** UCP is Google's open protocol for agentic commerce. It uses a layered architecture with capability negotiation and `/.well-known/ucp` discovery. This sprint adds UCP alongside ACP — both protocols coexist, sharing the same contract infrastructure.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | OCP: add UCP without modifying ACP code |
| DI | Protocol selection via services.yaml configuration |
| LSP | UCP checkout service substitutable for ACP checkout service |
| DRY | Reuse `AbstractAcpCheckoutService`, `AcpResponseFormatter`, contract infrastructure |
| No Overengineering | REST binding only — no gRPC, no A2A, no Embedded Protocol |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

---

## Objective

Add UCP REST binding support so that Google AI agents (Gemini, AI Mode) can:

1. **Discover** the shop via `/.well-known/ucp` profile
2. **Negotiate capabilities** (checkout, fulfillment, discounts)
3. **Create/manage checkout sessions** via UCP REST endpoints
4. **Complete payment** via Stripe as a UCP payment handler

### UCP vs ACP: Key Differences

| Aspect | ACP (Sprint 47) | UCP (This Sprint) |
|--------|-----------------|-------------------|
| Discovery | Manual agent onboarding | `/.well-known/ucp` auto-discovery |
| Negotiation | Fixed capability set | Dynamic capability intersection |
| Checkout states | `not_ready_for_payment`, `ready_for_payment`, `completed`, `canceled` | `incomplete`, `requires_escalation`, `ready_for_complete`, `completed`, `canceled` |
| Payment | SPT (Stripe-specific) | Payment handler abstraction (multi-PSP) |
| Headers | `Authorization: Bearer` | `UCP-Agent`, `Idempotency-Key`, `Request-Id`, `Request-Signature` |
| Extensibility | Spec versions | Reverse-domain namespaced extensions |

### What We Reuse

The beauty of the two-module architecture is that UCP shares the same backend:

```
UCP REST endpoint ──→ UcpCheckoutService ──→ AbstractAcpCheckoutService
                                                └─ ContractService
                                                └─ ContractRepository
                                                └─ EventDispatcher
```

Only the **protocol translation layer** is new — converting UCP request/response formats to/from the existing contract model.

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  Google AI Agent (Gemini, AI Mode)                                │
│  1. Discovers /.well-known/ucp                                   │
│  2. Negotiates capabilities                                      │
│  3. Creates checkout session                                     │
└───────────────────────────┬──────────────────────────────────────┘
                            │ REST + UCP headers
┌───────────────────────────▼──────────────────────────────────────┐
│  stripe module                                                    │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ UcpProfileController          (/.well-known/ucp)          │    │
│  │ UcpCheckoutController         (/ucp/checkout-sessions)    │    │
│  │  └─ POST / GET / PUT /:id / /:id/complete / /:id/cancel  │    │
│  └──────────────────────────────────────────────────────────┘    │
└───────────────────────────┬──────────────────────────────────────┘
                            │ delegates
┌───────────────────────────▼──────────────────────────────────────┐
│  payment-component                                                │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ UcpProfileInterface / UcpProfile                          │    │
│  │ UcpCapabilityNegotiationService                           │    │
│  │ UcpRequestValidatorInterface                              │    │
│  │ UcpResponseFormatterInterface / UcpResponseFormatter       │    │
│  │  └─ contract state → UCP checkout status                  │    │
│  └──────────────────────────────────────────────────────────┘    │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ Existing (from Sprint 47):                                │    │
│  │ AbstractAcpCheckoutService, ContractService, Repository   │    │
│  └──────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────┘
```

---

## Boundary Rule Applied

| Component | Provider-Agnostic? | Module |
|-----------|-------------------|--------|
| `UcpProfileInterface` / `UcpProfile` | Yes | payment-component |
| `UcpCapabilityNegotiationService` | Yes | payment-component |
| `UcpResponseFormatterInterface` / `UcpResponseFormatter` | Yes | payment-component |
| `UcpRequestValidatorInterface` / `UcpRequestValidator` | Yes | payment-component |
| `UcpProfileController` | **No** | stripe |
| `UcpCheckoutController` | **No** | stripe |
| `StripeUcpPaymentHandler` config | **No** | stripe |

---

## Part A: payment-component Changes

### New Files

```
payment-component/src/Mcp/Ucp/
├── UcpProfileInterface.php
├── UcpProfile.php
├── UcpCapabilityNegotiationService.php
├── UcpResponseFormatterInterface.php
├── UcpResponseFormatter.php
├── UcpRequestValidator.php
└── UcpCapability.php
```

### A1. UcpCapability (Value Object)

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

### A2. UcpProfileInterface

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

interface UcpProfileInterface
{
    /**
     * Get the UCP profile as a JSON-serializable array.
     * Follows the /.well-known/ucp specification.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Get supported capabilities.
     *
     * @return array<UcpCapability>
     */
    public function getCapabilities(): array;
}
```

### A3. UcpProfile

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
     * @param array<array{id: string, spec: string, version: string, config?: array}> $paymentHandlers
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

### A4. UcpCapabilityNegotiationService

Implements the UCP intersection algorithm.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

class UcpCapabilityNegotiationService
{
    /**
     * Compute the intersection of business and agent capabilities.
     *
     * @param array<UcpCapability> $businessCapabilities
     * @param array<array{name: string, version: string}> $agentCapabilities Raw from UCP-Agent header
     * @return array<UcpCapability> Negotiated capabilities
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

### A5. UcpResponseFormatterInterface

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

interface UcpResponseFormatterInterface
{
    /**
     * Format a contract as a UCP checkout session response.
     *
     * @return array<string, mixed>
     */
    public function formatCheckoutSession(PaymentContractInterface $contract): array;

    /**
     * Format a UCP error response.
     *
     * @return array<string, mixed>
     */
    public function formatError(string $type, string $message, ?string $param = null): array;
}
```

### A6. UcpResponseFormatter

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
     *
     * UCP defines: incomplete, requires_escalation, ready_for_complete, completed, canceled
     */
    private function mapContractStateToUcpStatus(string $contractState): string
    {
        return match ($contractState) {
            'draft', 'not_finished' => 'incomplete',
            'pending' => 'incomplete',
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

### A7. UcpRequestValidator

Validates required UCP headers.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Ucp;

class UcpRequestValidator
{
    /**
     * Validate UCP-required headers on a request.
     *
     * @param array<string, string> $headers Request headers
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateHeaders(array $headers): array
    {
        $errors = [];

        if (empty($headers['request-id'])) {
            $errors[] = 'Missing required header: Request-Id';
        }

        // Idempotency-Key required for state-modifying operations
        // (caller should pass whether this is a mutating request)

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Extract agent profile URL from UCP-Agent header.
     *
     * Format: UCP-Agent: profile="https://..."
     *
     * @return string|null Agent profile URL or null if not present
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

---

## Part B: stripe Module Changes

### New Files

```
stripe/src/Stripe/Mcp/
├── Controller/
│   ├── UcpProfileController.php
│   └── UcpCheckoutController.php
├── Event/
│   └── UcpCheckoutRequestEvent.php
└── Handler/
    └── UcpCheckoutRequestHandler.php
```

### B1. UcpProfileController

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

### B2. UcpCheckoutController (Event-Only)

REST controller — follows the strict event-only pattern. Validates auth + UCP headers, creates EventContext with all REST details, dispatches event, reads response from context.

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

#### UcpCheckoutRequestEvent

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

#### UcpCheckoutRequestHandler

**File:** `stripe/src/Stripe/Mcp/Handler/UcpCheckoutRequestHandler.php`

Routes UCP REST requests to the shared `AcpCheckoutServiceInterface`. Contains the routing logic that was previously in the controller.

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

### B3. services.yaml Additions

```yaml
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

# === UCP Event Handler (event-only controller pattern) ===

OxidEsales\Payments\Stripe\Mcp\Handler\UcpCheckoutRequestHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

# === Parameters ===

parameters:
    stripe.ucp.rest_endpoint: ''  # e.g., https://shop.example.com/index.php?cl=stripeucp
```

### B4. metadata.php Additions

```php
'controllers' => [
    // ... existing ...
    'stripeucp' => \OxidEsales\Payments\Stripe\Mcp\Controller\UcpCheckoutController::class,
    'stripeucpprofile' => \OxidEsales\Payments\Stripe\Mcp\Controller\UcpProfileController::class,
],
```

---

## File Summary

| # | Module | File | Purpose | Est. Lines |
|---|--------|------|---------|-----------|
| 1 | payment-component | `Mcp/Ucp/UcpCapability.php` | Capability value object | ~35 |
| 2 | payment-component | `Mcp/Ucp/UcpProfileInterface.php` | Profile contract | ~18 |
| 3 | payment-component | `Mcp/Ucp/UcpProfile.php` | /.well-known/ucp data | ~50 |
| 4 | payment-component | `Mcp/Ucp/UcpCapabilityNegotiationService.php` | Capability intersection | ~30 |
| 5 | payment-component | `Mcp/Ucp/UcpResponseFormatterInterface.php` | Response contract | ~18 |
| 6 | payment-component | `Mcp/Ucp/UcpResponseFormatter.php` | Contract → UCP response | ~80 |
| 7 | payment-component | `Mcp/Ucp/UcpRequestValidator.php` | Header validation | ~40 |
| 8 | stripe | `Mcp/Controller/UcpProfileController.php` | Profile endpoint | ~20 |
| 9 | stripe | `Mcp/Controller/UcpCheckoutController.php` | REST checkout (event-only) | ~65 |
| 10 | stripe | `Mcp/Event/UcpCheckoutRequestEvent.php` | UCP request event | ~15 |
| 11 | stripe | `Mcp/Handler/UcpCheckoutRequestHandler.php` | UCP routing + checkout handler | ~55 |
| | | **Total** | | **~461** |

---

## TDD Approach

### Step 1: UcpCapability Tests
Test `toArray()` with/without extensions.

### Step 2: UcpProfile Tests
Test `toArray()` matches `/.well-known/ucp` spec structure. Test capability list. Test payment handlers.

### Step 3: UcpCapabilityNegotiationService Tests
Test intersection with matching capabilities. Test empty intersection. Test agent with subset.

### Step 4: UcpResponseFormatter Tests
Test all 10 contract state → UCP status mappings. Test line item formatting. Test error formatting.

### Step 5: UcpRequestValidator Tests
Test missing Request-Id. Test valid headers. Test agent profile extraction from UCP-Agent header.

### Step 6: UcpCheckoutController Tests
Test routing (POST/GET/PUT). Test auth rejection. Test delegation to checkout service.

### Step 7: Full Validation
```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] `/.well-known/ucp` returns valid UCP profile JSON
- [ ] Profile includes `ucp_version`, `services`, `capabilities`, `payment`
- [ ] Capability negotiation correctly computes intersection
- [ ] UCP checkout states map correctly from contract states
- [ ] `POST /checkout-sessions` creates a checkout session
- [ ] `GET /checkout-sessions/:id` returns UCP-formatted session
- [ ] `POST /checkout-sessions/:id/complete` completes payment
- [ ] `POST /checkout-sessions/:id/cancel` cancels session
- [ ] UCP and MCP/ACP endpoints coexist without interference
- [ ] Stripe payment handler advertised in UCP profile
- [ ] All 799+ existing tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Acceptance Criteria

1. Google AI agents can discover the shop via `/.well-known/ucp`
2. UCP checkout sessions use the same contract infrastructure as ACP
3. Contract state mapping produces correct UCP statuses
4. UCP REST endpoints follow the UCP specification structure
5. Payment completes via Stripe as UCP payment handler
6. Both ACP and UCP work simultaneously on the same shop
7. An Unzer module can reuse UCP profile/formatter by wiring its own payment handler
