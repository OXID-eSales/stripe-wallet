# SPRINT-2 TICKET-09: Webhook Processing

**Priority:** 🔴 HIGHEST
**Estimated Effort:** 10-12 hours
**Sprint:** Sprint 2 (Core Integration)
**Depends On:** TICKET-08 (Payment Provider Integration)
**Blocks:** Production deployment, Payment fulfillment

---

## 📋 Overview

Implement secure webhook endpoint that receives and processes payment provider events from Stripe. This is critical for asynchronous payment notifications (payment succeeded, payment failed, refunds, disputes, etc.).

**Why This Matters:**
- Stripe communicates payment outcomes via webhooks (not synchronously)
- Without webhooks, we can't fulfill orders after payment authorization
- Webhooks enable real-time payment status updates
- Security is critical - must verify signatures to prevent fraud

---

## 🎯 Goals

### Primary Objectives
1. Create secure webhook HTTP endpoint
2. Verify Stripe webhook signatures
3. Process webhook events and update contract state
4. Handle idempotency (prevent duplicate processing)
5. Emit events for contract state changes
6. Log webhook activity for debugging and compliance

### Success Criteria
- ✅ Webhook endpoint receives POST requests
- ✅ Invalid signatures are rejected (401 Unauthorized)
- ✅ Valid webhooks update contract state
- ✅ Duplicate webhooks are safely ignored (idempotency)
- ✅ All webhook events are logged
- ✅ 30+ tests passing (unit + integration)

---

## 🏗️ Architecture

### Components to Implement

```
HTTP POST /webhook/stripe
        ↓
WebhookController
    • Receive raw webhook payload
    • Verify signature using Stripe SDK
    • Parse event type and data
    • Delegate to WebhookProcessor
        ↓
WebhookProcessor
    • Check idempotency (skip if already processed)
    • Find contract by provider payment ID
    • Emit WebhookReceivedEvent
    • Log webhook event
        ↓
Event Dispatcher
    • ContractFulfillmentHandler (already exists ✅)
    • Listens to WebhookReceivedEvent
    • Updates contract state
    • Updates order status
        ↓
Database
    • Save contract state
    • Save webhook log (audit trail)
```

---

## 📝 Implementation Phases

### Phase 1: WebhookSignatureVerifier (TDD)

**Goal:** Secure signature verification using Stripe SDK

**Test File:** `tests/Unit/Component/Webhook/WebhookSignatureVerifierTest.php`

**Test Specifications:**
```php
class WebhookSignatureVerifierTest extends TestCase
{
    // 1. Valid signature verification
    public function testVerifiesValidSignature(): void
    {
        // Given: Valid Stripe signature
        // When: verify() called with matching signature
        // Then: Returns true
    }

    // 2. Invalid signature rejection
    public function testRejectsInvalidSignature(): void
    {
        // Given: Invalid/tampered signature
        // When: verify() called
        // Then: Returns false
    }

    // 3. Missing signature
    public function testRejectsMissingSignature(): void
    {
        // Given: No signature header provided
        // When: verify() called
        // Then: Returns false
    }

    // 4. Expired signature
    public function testRejectsExpiredSignature(): void
    {
        // Given: Signature older than tolerance (5 minutes)
        // When: verify() called
        // Then: Returns false
    }

    // 5. Signature with wrong secret
    public function testRejectsSignatureWithWrongSecret(): void
    {
        // Given: Signature signed with different webhook secret
        // When: verify() called
        // Then: Returns false
    }
}
```

**Implementation:** `src/Component/Webhook/WebhookSignatureVerifier.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class WebhookSignatureVerifier
{
    public function __construct(
        private string $webhookSecret,
        private int $toleranceSeconds = 300
    ) {
    }

    public function verify(string $payload, string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }

        try {
            Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret,
                $this->toleranceSeconds
            );
            return true;
        } catch (SignatureVerificationException $e) {
            return false;
        }
    }

    public function parseEvent(string $payload, string $signature): array
    {
        $event = Webhook::constructEvent(
            $payload,
            $signature,
            $this->webhookSecret,
            $this->toleranceSeconds
        );

        return [
            'id' => $event->id,
            'type' => $event->type,
            'data' => $event->data->toArray(),
            'created' => $event->created,
        ];
    }
}
```

---

### Phase 2: WebhookIdempotencyChecker (TDD)

**Goal:** Prevent duplicate webhook processing

**Test File:** `tests/Unit/Component/Webhook/WebhookIdempotencyCheckerTest.php`

**Test Specifications:**
```php
class WebhookIdempotencyCheckerTest extends TestCase
{
    // 1. First webhook processing
    public function testAllowsFirstProcessing(): void
    {
        // Given: Webhook event never seen before
        // When: isProcessed() called
        // Then: Returns false (not processed)
    }

    // 2. Duplicate webhook detection
    public function testDetectsDuplicateWebhook(): void
    {
        // Given: Webhook event already processed
        // When: isProcessed() called again
        // Then: Returns true (already processed)
    }

    // 3. Marking webhook as processed
    public function testMarksWebhookAsProcessed(): void
    {
        // Given: New webhook event
        // When: markAsProcessed() called
        // Then: Event ID stored, isProcessed() returns true
    }

    // 4. Different webhooks are independent
    public function testDifferentWebhooksAreIndependent(): void
    {
        // Given: Two different webhook event IDs
        // When: Process first webhook
        // Then: Second webhook not marked as processed
    }
}
```

**Implementation:** `src/Component/Webhook/WebhookIdempotencyChecker.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

class WebhookIdempotencyChecker
{
    private array $processedEvents = [];

    public function __construct(
        private WebhookLogRepository $logRepository
    ) {
    }

    public function isProcessed(string $eventId): bool
    {
        if (isset($this->processedEvents[$eventId])) {
            return true;
        }

        return $this->logRepository->existsByEventId($eventId);
    }

    public function markAsProcessed(string $eventId): void
    {
        $this->processedEvents[$eventId] = true;

        $log = new WebhookLog(
            $eventId,
            new \DateTimeImmutable(),
            'processed'
        );

        $this->logRepository->save($log);
    }
}
```

---

### Phase 3: WebhookProcessor (TDD)

**Goal:** Process webhooks and update contract state

**Test File:** `tests/Unit/Component/Webhook/WebhookProcessorTest.php`

**Test Specifications:**
```php
class WebhookProcessorTest extends TestCase
{
    // 1. Process payment_intent.succeeded webhook
    public function testProcessesPaymentSucceededWebhook(): void
    {
        // Given: payment_intent.succeeded webhook
        // When: process() called
        // Then: Finds contract, emits WebhookReceivedEvent
    }

    // 2. Find contract by provider payment ID
    public function testFindsContractByProviderPaymentId(): void
    {
        // Given: Webhook with payment_intent ID
        // When: process() called
        // Then: Finds contract with matching providerOrderId
    }

    // 3. Skip duplicate webhooks
    public function testSkipsDuplicateWebhooks(): void
    {
        // Given: Webhook already processed (idempotency check)
        // When: process() called again
        // Then: Returns early, no event emitted
    }

    // 4. Handle unknown contract
    public function testHandlesUnknownContract(): void
    {
        // Given: Webhook for payment ID not in system
        // When: process() called
        // Then: Logs warning, does not throw exception
    }

    // 5. Process payment_intent.payment_failed webhook
    public function testProcessesPaymentFailedWebhook(): void
    {
        // Given: payment_intent.payment_failed webhook
        // When: process() called
        // Then: Emits WebhookReceivedEvent with failure data
    }

    // 6. Process charge.refunded webhook
    public function testProcessesRefundedWebhook(): void
    {
        // Given: charge.refunded webhook
        // When: process() called
        // Then: Emits WebhookReceivedEvent for refund
    }

    // 7. Log all webhook events
    public function testLogsAllWebhookEvents(): void
    {
        // Given: Any webhook event
        // When: process() called
        // Then: WebhookLog saved to repository
    }
}
```

**Implementation:** `src/Component/Webhook/WebhookProcessor.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepository;
use Psr\Log\LoggerInterface;

class WebhookProcessor
{
    public function __construct(
        private ContractRepository $contractRepository,
        private EventDispatcher $eventDispatcher,
        private WebhookIdempotencyChecker $idempotencyChecker,
        private WebhookLogRepository $logRepository,
        private LoggerInterface $logger
    ) {
    }

    public function process(array $webhookData): void
    {
        $eventId = $webhookData['id'];
        $eventType = $webhookData['type'];
        $eventData = $webhookData['data'];

        if ($this->idempotencyChecker->isProcessed($eventId)) {
            $this->logger->info("Webhook already processed, skipping", ['eventId' => $eventId]);
            return;
        }

        $paymentIntentId = $this->extractPaymentIntentId($eventData);
        if (!$paymentIntentId) {
            $this->logger->warning("Cannot extract payment intent ID from webhook", ['eventType' => $eventType]);
            return;
        }

        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);
        if (!$contract) {
            $this->logger->warning("Contract not found for payment intent", ['paymentIntentId' => $paymentIntentId]);
            return;
        }

        $context = new EventContext([
            'contractId' => $contract->getId(),
            'webhookEventId' => $eventId,
        ]);

        $event = new WebhookReceivedEvent(
            $context,
            'stripe',
            $eventType,
            $eventData,
            $eventId
        );

        $this->eventDispatcher->dispatch($event);

        $this->idempotencyChecker->markAsProcessed($eventId);

        $this->logWebhookEvent($eventId, $eventType, $contract->getId());
    }

    private function extractPaymentIntentId(array $eventData): ?string
    {
        return $eventData['object']['id'] ?? null;
    }

    private function logWebhookEvent(string $eventId, string $eventType, string $contractId): void
    {
        $log = new WebhookLog($eventId, new \DateTimeImmutable(), 'processed');
        $log->setEventType($eventType);
        $log->setContractId($contractId);
        $this->logRepository->save($log);
    }
}
```

---

### Phase 4: WebhookController (HTTP Endpoint)

**Goal:** HTTP controller to receive webhook POST requests

**Test File:** `tests/Unit/Controller/WebhookControllerTest.php`

**Test Specifications:**
```php
class WebhookControllerTest extends TestCase
{
    // 1. Valid webhook request
    public function testHandlesValidWebhookRequest(): void
    {
        // Given: Valid Stripe webhook POST request
        // When: handleRequest() called
        // Then: Returns 200 OK, processes webhook
    }

    // 2. Invalid signature rejection
    public function testRejectsInvalidSignature(): void
    {
        // Given: Webhook with invalid signature
        // When: handleRequest() called
        // Then: Returns 401 Unauthorized
    }

    // 3. Missing signature header
    public function testRejectsMissingSignatureHeader(): void
    {
        // Given: Request without Stripe-Signature header
        // When: handleRequest() called
        // Then: Returns 400 Bad Request
    }

    // 4. Invalid JSON payload
    public function testRejectsInvalidJsonPayload(): void
    {
        // Given: Request with malformed JSON
        // When: handleRequest() called
        // Then: Returns 400 Bad Request
    }

    // 5. Successful webhook processing
    public function testReturnsSuccessAfterProcessing(): void
    {
        // Given: Valid webhook processed successfully
        // When: handleRequest() called
        // Then: Returns 200 OK with success message
    }

    // 6. Exception handling
    public function testHandlesProcessingException(): void
    {
        // Given: Webhook processing throws exception
        // When: handleRequest() called
        // Then: Returns 500 Internal Server Error
    }
}
```

**Implementation:** `src/Controller/WebhookController.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Controller;

use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookSignatureVerifier;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookProcessor;
use Psr\Log\LoggerInterface;

class WebhookController
{
    public function __construct(
        private WebhookSignatureVerifier $signatureVerifier,
        private WebhookProcessor $processor,
        private LoggerInterface $logger
    ) {
    }

    public function handleStripeWebhook(): array
    {
        try {
            $payload = file_get_contents('php://input');
            $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

            if (empty($signature)) {
                http_response_code(400);
                return ['error' => 'Missing signature header'];
            }

            if (!$this->signatureVerifier->verify($payload, $signature)) {
                http_response_code(401);
                return ['error' => 'Invalid signature'];
            }

            $webhookData = $this->signatureVerifier->parseEvent($payload, $signature);

            $this->processor->process($webhookData);

            return ['status' => 'success', 'received' => true];

        } catch (\Exception $e) {
            $this->logger->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            http_response_code(500);
            return ['error' => 'Internal server error'];
        }
    }
}
```

---

## 🗂️ Supporting Classes

### WebhookLog Entity

**File:** `src/Component/Webhook/WebhookLog.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Webhook;

class WebhookLog
{
    private string $id;
    private string $eventId;
    private \DateTimeImmutable $receivedAt;
    private string $status;
    private ?string $eventType = null;
    private ?string $contractId = null;

    public function __construct(
        string $eventId,
        \DateTimeImmutable $receivedAt,
        string $status
    ) {
        $this->id = uniqid('webhook_log_', true);
        $this->eventId = $eventId;
        $this->receivedAt = $receivedAt;
        $this->status = $status;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function setEventType(string $eventType): void
    {
        $this->eventType = $eventType;
    }

    public function setContractId(string $contractId): void
    {
        $this->contractId = $contractId;
    }
}
```

### WebhookLogRepository Interface

**File:** `src/Component/Repository/WebhookLogRepositoryInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookLog;

interface WebhookLogRepositoryInterface
{
    public function save(WebhookLog $log): void;
    public function existsByEventId(string $eventId): bool;
    public function findByEventId(string $eventId): ?WebhookLog;
}
```

### In-Memory Implementation (for tests)

**File:** `src/Component/Repository/WebhookLogRepository.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookLog;

class WebhookLogRepository implements WebhookLogRepositoryInterface
{
    private array $logs = [];
    private array $eventIndex = [];

    public function save(WebhookLog $log): void
    {
        $this->logs[$log->getId()] = $log;
        $this->eventIndex[$log->getEventId()] = $log->getId();
    }

    public function existsByEventId(string $eventId): bool
    {
        return isset($this->eventIndex[$eventId]);
    }

    public function findByEventId(string $eventId): ?WebhookLog
    {
        $logId = $this->eventIndex[$eventId] ?? null;
        return $logId ? $this->logs[$logId] : null;
    }
}
```

---

## 🔌 Integration with Existing Code

### ContractRepository Enhancement

Add method to find contract by provider order ID:

**File:** `src/Component/Repository/ContractRepository.php`

```php
public function findByProviderOrderId(string $providerOrderId): ?PaymentContract
{
    foreach ($this->contracts as $contract) {
        if ($contract->getProviderOrderId() === $providerOrderId) {
            return $contract;
        }
    }
    return null;
}
```

### Route Configuration

**File:** `source/extensions/stripe/metadata.php` (add webhook route)

```php
'controllers' => [
    'stripe_webhook' => OxidSolutionCatalysts\Payments\Controller\WebhookController::class,
],
```

---

## 📊 Test Summary

### Unit Tests (24 tests)
1. WebhookSignatureVerifier: 5 tests
2. WebhookIdempotencyChecker: 4 tests
3. WebhookProcessor: 7 tests
4. WebhookController: 6 tests
5. WebhookLog: 2 tests (entity)

### Integration Tests (4 tests)
1. End-to-end webhook processing (valid signature → contract update)
2. Idempotency test (duplicate webhook ignored)
3. Invalid signature rejection
4. Unknown contract handling

**Total: 28+ tests**

---

## ✅ Acceptance Criteria

### Functional Requirements
- [ ] Webhook endpoint accessible at `/webhook/stripe`
- [ ] Valid Stripe signatures accepted
- [ ] Invalid signatures rejected (401)
- [ ] Contracts updated on payment success
- [ ] Duplicate webhooks safely ignored
- [ ] All webhooks logged for audit

### Non-Functional Requirements
- [ ] Response time < 200ms (webhook timeouts are ~30s)
- [ ] All tests pass (28+ tests)
- [ ] No database deadlocks (idempotency check before processing)
- [ ] PSR-3 logging implemented
- [ ] Exception handling prevents crashes

### Security Requirements
- [ ] Signature verification mandatory
- [ ] No webhook processing without valid signature
- [ ] Webhook secret stored in configuration (not hardcoded)
- [ ] Payload size limits enforced (< 100KB)

---

## 📁 Files to Create

### Source Files (7)
```
src/Component/Webhook/
├── WebhookSignatureVerifier.php           (60 lines)
├── WebhookIdempotencyChecker.php          (40 lines)
├── WebhookProcessor.php                   (100 lines)
└── WebhookLog.php                         (50 lines)

src/Component/Repository/
├── WebhookLogRepositoryInterface.php      (15 lines)
└── WebhookLogRepository.php               (35 lines)

src/Controller/
└── WebhookController.php                  (70 lines)
```

### Test Files (6)
```
tests/Unit/Component/Webhook/
├── WebhookSignatureVerifierTest.php       (120 lines)
├── WebhookIdempotencyCheckerTest.php      (90 lines)
├── WebhookProcessorTest.php               (180 lines)
└── WebhookLogTest.php                     (40 lines)

tests/Unit/Controller/
└── WebhookControllerTest.php              (150 lines)

tests/Integration/Component/Webhook/
└── WebhookProcessingIntegrationTest.php   (200 lines)
```

**Total Lines:** ~1,150 (source: ~370, tests: ~780)

---

## 📚 References

**Stripe Documentation:**
- [Webhooks](https://stripe.com/docs/webhooks)
- [Signature Verification](https://stripe.com/docs/webhooks/signatures)
- [Event Types](https://stripe.com/docs/api/events/types)

**Existing Code:**
- `ContractFulfillmentHandler.php` (already handles WebhookReceivedEvent ✅)
- `WebhookReceivedEvent.php` (event class already exists ✅)

---

## 🚀 Implementation Order

### Day 1 (4 hours)
1. Phase 1: WebhookSignatureVerifier (1 hour)
2. Phase 2: WebhookIdempotencyChecker (1 hour)
3. WebhookLog entity + repository (1 hour)
4. ContractRepository enhancement (30 min)
5. Run tests, verify pass (30 min)

### Day 2 (4 hours)
1. Phase 3: WebhookProcessor (2 hours)
2. Phase 4: WebhookController (1.5 hours)
3. Run all tests (30 min)

### Day 3 (2-4 hours)
1. Integration tests (2 hours)
2. Manual testing with Stripe CLI (1 hour)
3. Documentation updates (1 hour)

---

## 🔍 Testing Strategy

### Unit Testing
- All components tested in isolation
- Mock Stripe SDK responses
- Mock repositories and event dispatcher
- Fast tests (< 0.1s total)

### Integration Testing
- Real WebhookController → WebhookProcessor flow
- Real event dispatcher and handlers
- In-memory repositories (no real database)
- Verify contract state transitions

### Manual Testing (Stripe CLI)
```bash
# Install Stripe CLI
brew install stripe/stripe-cli/stripe

# Forward webhooks to local dev server
stripe listen --forward-to http://localhost:8000/webhook/stripe

# Trigger test webhook
stripe trigger payment_intent.succeeded
```

---

## 📋 Definition of Done

- [x] All 28+ tests written (TDD-first)
- [x] All tests passing
- [x] Signature verification implemented
- [x] Idempotency check implemented
- [x] WebhookLog entity and repository
- [x] WebhookController handles HTTP requests
- [x] Integration with ContractFulfillmentHandler verified
- [x] Error handling and logging implemented
- [x] Code review completed
- [x] Manual testing with Stripe CLI
- [x] Documentation updated

---

## 🎯 Success Metrics

**Code Quality:**
- 28+ tests passing
- 100% code coverage for webhook components
- < 0.2s test execution time

**Security:**
- 100% signature verification rate
- 0 webhooks processed without valid signature

**Reliability:**
- 100% idempotency (duplicate webhooks safely ignored)
- 0 database deadlocks
- 100% webhook logging (audit trail)

---

**Estimated Completion:** 10-12 hours (1.5-2 days)
**Priority:** 🔴 CRITICAL PATH
**Next Ticket:** TICKET-10 (Database Layer)

*Created: 2025-10-30*
*Version: 1.0*
