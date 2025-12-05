# Sprint 13: Real Stripe Webhook Testing Infrastructure

**Date:** 2025-12-05
**Status:** PLANNED
**Branch:** b-7.4.x-auth-STRP-70

---

### Core Principles
Principle	Description
TDD-FIRST	Write failing tests BEFORE implementation (RED → GREEN → REFACTOR)
SOLID	Single Responsibility, Open/Closed, Liskov, Interface Segregation, DI
Dependency Injection	All dependencies injected via constructor
Liskov Substitution	Use interfaces as types instead of classes. Subclasses must be substitutable for base classes
Clean Code	Human readable, maintainable, self-documenting
No Over-Engineering	Minimal changes to achieve the goal
No Duplicate Code	Reuse existing services and methods
No Reinventing	Check if solution already exists before creating


## Objective

Create a TDD-first, SOLID-compliant testing infrastructure for real Stripe webhooks using Stripe SDK v18 and Stripe CLI.

---

## Research Summary

### Stripe SDK v18 Webhook Handling

**Source:** [Stripe Webhook Documentation](https://docs.stripe.com/webhooks)

```php
// Core verification method
\Stripe\Webhook::constructEvent(
    $payload,      // Raw request body (string)
    $signature,    // Stripe-Signature header
    $secret,       // Endpoint secret (whsec_xxx)
    $tolerance     // Optional: seconds (default 300)
);
```

**Exceptions:**
- `\Stripe\Exception\SignatureVerificationException` - Invalid signature
- `\UnexpectedValueException` - Invalid payload

### Stripe CLI Testing

**Source:** [Stripe CLI Triggers](https://docs.stripe.com/stripe-cli/triggers)

```bash
# Start local listener
stripe listen --forward-to localhost:4242/webhook

# Trigger test events
stripe trigger payment_intent.succeeded
stripe trigger checkout.session.completed
stripe trigger charge.refunded

# Forward specific events only
stripe listen --events payment_intent.succeeded,charge.refunded --forward-to localhost:4242/webhook
```

### Key Events for Testing

| Event | Trigger | Expected Action |
|-------|---------|-----------------|
| `payment_intent.succeeded` | Payment captured | Update OXPAID, fulfill contract |
| `payment_intent.payment_failed` | Payment failed | Log failure, notify |
| `checkout.session.completed` | Checkout done | Create order, commit contract |
| `charge.refunded` | Refund processed | Update refund status |
| `charge.dispute.created` | Dispute opened | Flag order |

---

## Current State Analysis

### Existing Implementation

| File | Status | Issues |
|------|--------|--------|
| `WebhookSignatureVerifierInterface` | ✅ Good | Clean interface |
| `WebhookSignatureVerifier` | ✅ Good | Uses Stripe SDK correctly |
| `WebhookController` | ⚠️ Mixed | Tightly coupled, hard to test |
| `WebhookSignatureVerifierTest` | ⚠️ Limited | Self-generated signatures only |

### Testing Gaps

1. **No Integration Tests** - No tests with real Stripe SDK verification
2. **No E2E Tests** - No tests for full webhook flow
3. **Controller Coupling** - `WebhookController` directly uses `file_get_contents('php://input')`
4. **No Event Handler Tests** - Individual handlers not unit tested

---

## SOLID Design

### Single Responsibility Principle

```
┌─────────────────────────┐
│   WebhookController     │ ← HTTP handling only
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│ WebhookRequestParser    │ ← Parse raw request
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│ SignatureVerifier       │ ← Verify signature
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│ WebhookEventDispatcher  │ ← Route to handlers
└────────────┬────────────┘
             │
             ▼
┌─────────────────────────┐
│ Event Handlers          │ ← Business logic
└─────────────────────────┘
```

### Interface Segregation

```php
interface WebhookRequestParserInterface
{
    public function parse(string $rawBody): WebhookRequest;
}

interface WebhookSignatureVerifierInterface
{
    public function verify(string $payload, string $signature): bool;
    public function parseEvent(string $payload, string $signature): array;
}

interface WebhookEventHandlerInterface
{
    public function supports(string $eventType): bool;
    public function handle(WebhookEvent $event): WebhookResult;
}

interface WebhookEventDispatcherInterface
{
    public function dispatch(WebhookEvent $event): WebhookResult;
    public function registerHandler(WebhookEventHandlerInterface $handler): void;
}
```

### Dependency Injection

```php
class WebhookController
{
    public function __construct(
        private readonly WebhookRequestParserInterface $parser,
        private readonly WebhookSignatureVerifierInterface $verifier,
        private readonly WebhookEventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger
    ) {}
}
```

---

## Implementation Plan (TDD-First)

### Phase 1: Value Objects & DTOs

**Tests First:**
```php
// tests/Unit/Component/Webhook/WebhookRequestTest.php
public function testCanCreateFromRawData(): void
public function testGetPayloadReturnsRawString(): void
public function testGetSignatureReturnsHeader(): void

// tests/Unit/Component/Webhook/WebhookEventTest.php
public function testCanCreateFromStripeEvent(): void
public function testGetTypeReturnsEventType(): void
public function testGetDataReturnsPayload(): void

// tests/Unit/Component/Webhook/WebhookResultTest.php
public function testSuccessResultHasCorrectStatus(): void
public function testFailureResultContainsError(): void
```

**Implementation:**
```php
// src/Component/Webhook/WebhookRequest.php
final readonly class WebhookRequest
{
    public function __construct(
        public string $payload,
        public string $signature,
        public string $remoteIp,
        public \DateTimeImmutable $receivedAt
    ) {}
}

// src/Component/Webhook/WebhookEvent.php
final readonly class WebhookEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
        public int $created
    ) {}
}

// src/Component/Webhook/WebhookResult.php
final readonly class WebhookResult
{
    public function __construct(
        public bool $success,
        public string $action,
        public ?string $error = null
    ) {}
}
```

### Phase 2: Request Parser

**Tests First:**
```php
// tests/Unit/Component/Webhook/WebhookRequestParserTest.php
public function testParsesValidJsonPayload(): void
public function testThrowsOnEmptyPayload(): void
public function testThrowsOnInvalidJson(): void
public function testExtractsSignatureFromHeaders(): void
```

**Implementation:**
```php
// src/Component/Webhook/WebhookRequestParser.php
final class WebhookRequestParser implements WebhookRequestParserInterface
{
    public function parse(string $rawBody, array $headers): WebhookRequest
    {
        // Implementation
    }
}
```

### Phase 3: Signature Verification (Enhanced)

**Tests First:**
```php
// tests/Unit/Stripe/WebhookSignatureVerifierTest.php (ENHANCED)
public function testVerifiesRealStripeSignatureFormat(): void
public function testRejectsModifiedPayload(): void
public function testRespectsToleranceParameter(): void
public function testHandlesMultipleSignatureVersions(): void

// tests/Integration/Stripe/WebhookSignatureVerifierIntegrationTest.php
/**
 * @group integration
 * @group stripe-cli
 */
public function testVerifiesSignatureFromStripeCli(): void
```

### Phase 4: Event Handlers

**Tests First:**
```php
// tests/Unit/Stripe/Webhook/Handler/PaymentIntentSucceededHandlerTest.php
public function testSupportsPaymentIntentSucceededEvent(): void
public function testDoesNotSupportOtherEvents(): void
public function testUpdatesOxpaidTimestamp(): void
public function testFulfillsContractWhenCommitted(): void
public function testLogsSuccessfulHandling(): void

// tests/Unit/Stripe/Webhook/Handler/CheckoutSessionCompletedHandlerTest.php
public function testSupportsCheckoutSessionCompletedEvent(): void
public function testCommitsContractOnSuccess(): void
public function testCreatesOrderFromSession(): void
```

**Implementation:**
```php
// src/Stripe/Webhook/Handler/PaymentIntentSucceededHandler.php
final class PaymentIntentSucceededHandler implements WebhookEventHandlerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function supports(string $eventType): bool
    {
        return $eventType === 'payment_intent.succeeded';
    }

    public function handle(WebhookEvent $event): WebhookResult
    {
        // Implementation
    }
}
```

### Phase 5: Event Dispatcher

**Tests First:**
```php
// tests/Unit/Component/Webhook/WebhookEventDispatcherTest.php
public function testDispatchesToCorrectHandler(): void
public function testReturnsSuccessWhenHandlerSucceeds(): void
public function testReturnsFailureWhenNoHandlerFound(): void
public function testLogsAllDispatchedEvents(): void
public function testHandlesMultipleHandlersForSameEvent(): void
```

**Implementation:**
```php
// src/Component/Webhook/WebhookEventDispatcher.php
final class WebhookEventDispatcher implements WebhookEventDispatcherInterface
{
    /** @var array<WebhookEventHandlerInterface> */
    private array $handlers = [];

    public function registerHandler(WebhookEventHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function dispatch(WebhookEvent $event): WebhookResult
    {
        // Implementation
    }
}
```

### Phase 6: Integration Tests with Stripe CLI

**Test Setup:**
```php
// tests/Integration/Stripe/Webhook/WebhookIntegrationTest.php
/**
 * @group integration
 * @group stripe-cli
 * @requires extension curl
 */
final class WebhookIntegrationTest extends TestCase
{
    private const STRIPE_CLI_SECRET = 'whsec_test_from_cli';

    /**
     * @test
     * Run with: stripe trigger payment_intent.succeeded
     */
    public function testProcessesRealPaymentIntentSucceededEvent(): void
    {
        // 1. Create test order with known payment intent
        // 2. Trigger event via Stripe CLI
        // 3. Verify OXPAID updated
        // 4. Verify contract fulfilled
    }
}
```

**Stripe CLI Test Helper:**
```php
// tests/Helper/StripeCli.php
final class StripeCli
{
    public static function trigger(string $event): void
    {
        exec("stripe trigger $event 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException("Stripe CLI failed: " . implode("\n", $output));
        }
    }

    public static function generateSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signedPayload = "$timestamp.$payload";
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        return "t=$timestamp,v1=$signature";
    }
}
```

---

## File Structure

```
src/
├── Component/
│   └── Webhook/
│       ├── WebhookRequest.php              # NEW: Request DTO
│       ├── WebhookEvent.php                # NEW: Event DTO
│       ├── WebhookResult.php               # NEW: Result DTO
│       ├── WebhookRequestParserInterface.php    # NEW
│       ├── WebhookRequestParser.php        # NEW
│       ├── WebhookEventDispatcherInterface.php  # NEW
│       ├── WebhookEventDispatcher.php      # NEW
│       ├── WebhookEventHandlerInterface.php     # NEW
│       └── WebhookSignatureVerifierInterface.php # EXISTS
│
└── Stripe/
    ├── WebhookSignatureVerifier.php        # EXISTS (enhanced)
    └── Webhook/
        └── Handler/
            ├── PaymentIntentSucceededHandler.php  # NEW
            ├── PaymentIntentFailedHandler.php     # NEW
            ├── CheckoutSessionCompletedHandler.php # NEW
            ├── ChargeRefundedHandler.php          # NEW
            └── ChargeDisputeCreatedHandler.php    # NEW

tests/
├── Unit/
│   ├── Component/
│   │   └── Webhook/
│   │       ├── WebhookRequestTest.php           # NEW
│   │       ├── WebhookEventTest.php             # NEW
│   │       ├── WebhookResultTest.php            # NEW
│   │       ├── WebhookRequestParserTest.php     # NEW
│   │       └── WebhookEventDispatcherTest.php   # NEW
│   │
│   └── Stripe/
│       ├── WebhookSignatureVerifierTest.php     # ENHANCED
│       └── Webhook/
│           └── Handler/
│               ├── PaymentIntentSucceededHandlerTest.php  # NEW
│               ├── PaymentIntentFailedHandlerTest.php     # NEW
│               ├── CheckoutSessionCompletedHandlerTest.php # NEW
│               ├── ChargeRefundedHandlerTest.php          # NEW
│               └── ChargeDisputeCreatedHandlerTest.php    # NEW
│
├── Integration/
│   └── Stripe/
│       └── Webhook/
│           ├── WebhookSignatureVerifierIntegrationTest.php # NEW
│           └── WebhookIntegrationTest.php                  # NEW
│
└── Helper/
    └── StripeCli.php                        # NEW: CLI test helper
```

---

## Testing Commands

### Unit Tests
```bash
# Run all webhook unit tests
./bin/pre-commit-check.sh

# Run specific handler tests
docker compose exec php php vendor/bin/phpunit \
  --filter="Handler" tests/Unit/Stripe/Webhook/
```

### Integration Tests with Stripe CLI
```bash
# Terminal 1: Start Stripe CLI listener
stripe listen --forward-to http://localhost/index.php?cl=stripe_webhook

# Terminal 2: Run integration tests
docker compose exec php php vendor/bin/phpunit \
  --group=stripe-cli tests/Integration/
```

### Manual Testing
```bash
# Trigger specific events
stripe trigger payment_intent.succeeded
stripe trigger payment_intent.payment_failed
stripe trigger checkout.session.completed
stripe trigger charge.refunded
```

---

## Acceptance Criteria

### Must Have
- [ ] All DTOs have 100% unit test coverage
- [ ] All handlers have unit tests with mocked dependencies
- [ ] Signature verification works with Stripe CLI signatures
- [ ] Integration tests can be run with Stripe CLI

### Should Have
- [ ] Event dispatcher supports handler priority
- [ ] Handlers are tagged for auto-registration in DI container
- [ ] Webhook logs include correlation IDs

### Could Have
- [ ] Webhook replay functionality for testing
- [ ] Dead letter queue for failed webhooks
- [ ] Dashboard for webhook monitoring

---

## Dependencies

### Required
- Stripe PHP SDK v18.x
- Stripe CLI (for integration tests)

### Installation
```bash
# Install Stripe CLI
curl -s https://packages.stripe.dev/api/security/keypair/stripe-cli-gpg/public | gpg --dearmor | sudo tee /usr/share/keyrings/stripe.gpg
echo "deb [signed-by=/usr/share/keyrings/stripe.gpg] https://packages.stripe.dev/stripe-cli-debian-local stable main" | sudo tee -a /etc/apt/sources.list.d/stripe.list
sudo apt update && sudo apt install stripe

# Login to Stripe
stripe login
```

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Stripe CLI not available in CI | Medium | Use mock signatures for CI, real CLI for local |
| Event format changes in SDK v18 | Low | Pin SDK version, test with real events |
| Webhook timeout during tests | Medium | Use async processing, quick acknowledgment |

---

## Sources

- [Stripe Webhook Documentation](https://docs.stripe.com/webhooks)
- [Stripe Signature Verification](https://docs.stripe.com/webhooks/signature)
- [Stripe CLI Triggers](https://docs.stripe.com/stripe-cli/triggers)
- [Stripe PHP SDK Releases](https://github.com/stripe/stripe-php/releases)
- [Stripe CLI Documentation](https://docs.stripe.com/stripe-cli/overview)
