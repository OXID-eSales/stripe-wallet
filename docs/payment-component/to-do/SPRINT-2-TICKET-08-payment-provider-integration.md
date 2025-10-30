# TICKET-08: Payment Provider Integration (Stripe SDK Adapter)

**Sprint:** 2
**Priority:** 🔴 HIGHEST (CRITICAL PATH)
**Status:** 🔴 NOT STARTED
**Estimated Effort:** 16-20 hours
**Dependencies:** TICKET-07 (Event Handlers) ✅ COMPLETE
**Blocks:** TICKET-09 (Webhooks), TICKET-12 (Checkout)

---

## 🎯 Objective

Integrate Stripe SDK into the payment component, creating a clean adapter layer that implements the payment provider interface. This enables the contract lifecycle handlers to interact with real Stripe Payment Intents.

---

## 📋 Requirements

### Functional Requirements

1. **Create PaymentIntent** when contract transitions to PENDING
2. **Authorize Payment** (two-step: auth → capture)
3. **Capture Payment** when contract is fulfilled
4. **Cancel PaymentIntent** when contract is cancelled
5. **Refund Payment** for completed orders
6. **Map Provider States** to Contract states
7. **Handle Errors** gracefully with retries
8. **Support Idempotency** to prevent duplicate operations

### Non-Functional Requirements

1. **Testability:** Mock Stripe SDK for unit tests
2. **Security:** API keys stored securely, never logged
3. **Performance:** < 500ms for API calls
4. **Reliability:** Retry failed API calls with exponential backoff
5. **Extensibility:** Easy to add other providers (PayPal, Unzer, etc.)

---

## 🏗️ Architecture

### Component Structure

```
src/Component/Service/Payment/
├── PaymentAdapterInterface.php          # Provider interface
├── PaymentProviderFactory.php           # Factory for creating adapters
└── PaymentAdapterException.php          # Custom exceptions

src/Stripe/Service/
├── StripePaymentAdapter.php             # Main adapter implementation
├── StripeClientFactory.php              # Creates configured Stripe client
├── StripePaymentIntentMapper.php        # Maps Stripe → Contract states
├── StripeIdempotencyManager.php         # Manages idempotency keys
└── StripeErrorHandler.php               # Error handling & retries

tests/Unit/Stripe/Service/
├── StripePaymentAdapterTest.php         # Mock Stripe\StripeClient
├── StripePaymentIntentMapperTest.php
├── StripeIdempotencyManagerTest.php
└── StripeErrorHandlerTest.php

tests/Integration/Stripe/
├── StripePaymentFlowTest.php            # Real API calls (sandbox)
└── StripeWebhookIntegrationTest.php
```

---

## 📝 Implementation Plan

### Phase 1: Interface & Factory (2-3 hours)

#### Task 1.1: Create PaymentAdapterInterface

**File:** `src/Component/Service/Payment/PaymentAdapterInterface.php`

**TDD Approach:**
1. Write test for interface contract
2. Create interface with methods

**Interface Methods:**
```php
interface PaymentAdapterInterface
{
    public function createPaymentIntent(
        PaymentContract $contract,
        array $options = []
    ): PaymentIntentResult;

    public function authorizePayment(
        string $providerOrderId,
        array $paymentMethodData
    ): AuthorizationResult;

    public function capturePayment(
        string $providerOrderId,
        ?float $amount = null
    ): CaptureResult;

    public function cancelPayment(
        string $providerOrderId,
        string $reason = ''
    ): CancellationResult;

    public function refundPayment(
        string $providerOrderId,
        float $amount,
        string $reason = ''
    ): RefundResult;

    public function getPaymentStatus(
        string $providerOrderId
    ): PaymentStatusResult;

    public function supports(string $provider): bool;
}
```

**Tests to Write (5 tests):**
```php
public function testCreatePaymentIntentReturnsResult(): void
public function testAuthorizePaymentWithValidData(): void
public function testCapturePaymentWithAmount(): void
public function testCancelPaymentWithReason(): void
public function testRefundPaymentReturnsResult(): void
```

---

#### Task 1.2: Create Result Value Objects

**Files:**
- `src/Component/Service/Payment/Result/PaymentIntentResult.php`
- `src/Component/Service/Payment/Result/AuthorizationResult.php`
- `src/Component/Service/Payment/Result/CaptureResult.php`
- `src/Component/Service/Payment/Result/CancellationResult.php`
- `src/Component/Service/Payment/Result/RefundResult.php`
- `src/Component/Service/Payment/Result/PaymentStatusResult.php`

**Example:**
```php
readonly class PaymentIntentResult
{
    public function __construct(
        public string $providerOrderId,
        public string $clientSecret,
        public string $status,
        public float $amount,
        public string $currency,
        public array $metadata = []
    ) {}

    public static function fromStripePaymentIntent(\Stripe\PaymentIntent $intent): self
    {
        return new self(
            providerOrderId: $intent->id,
            clientSecret: $intent->client_secret,
            status: $intent->status,
            amount: $intent->amount / 100,
            currency: strtoupper($intent->currency),
            metadata: $intent->metadata->toArray()
        );
    }
}
```

**Tests:** 1 test per result class (6 tests total)

---

#### Task 1.3: Create PaymentProviderFactory

**File:** `src/Component/Service/Payment/PaymentProviderFactory.php`

```php
class PaymentProviderFactory
{
    public function __construct(
        private iterable $adapters // Tagged DI services
    ) {}

    public function createAdapter(string $provider): PaymentAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($provider)) {
                return $adapter;
            }
        }

        throw new \InvalidArgumentException("Unsupported provider: {$provider}");
    }
}
```

**Tests (3 tests):**
```php
public function testCreatesStripeAdapter(): void
public function testThrowsExceptionForUnsupportedProvider(): void
public function testReturnsCorrectAdapterWhenMultipleRegistered(): void
```

---

### Phase 2: Stripe Adapter Implementation (6-8 hours)

#### Task 2.1: Composer Setup

```json
{
    "require": {
        "stripe/stripe-php": "^12.0"
    }
}
```

Run:
```bash
composer require stripe/stripe-php
```

---

#### Task 2.2: StripeClientFactory

**File:** `src/Stripe/Service/StripeClientFactory.php`

```php
class StripeClientFactory
{
    public function create(string $apiKey, array $options = []): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient([
            'api_key' => $apiKey,
            'stripe_version' => '2023-10-16',
            ...$options
        ]);
    }
}
```

**Tests (2 tests):**
```php
public function testCreatesClientWithApiKey(): void
public function testSetsCorrectStripeVersion(): void
```

---

#### Task 2.3: StripePaymentAdapter Core Methods

**File:** `src/Stripe/Service/StripePaymentAdapter.php`

**TDD Approach:**
1. Write test mocking \Stripe\StripeClient
2. Implement method
3. Verify test passes

**Example Implementation:**
```php
class StripePaymentAdapter implements PaymentAdapterInterface
{
    public function __construct(
        private \Stripe\StripeClient $stripeClient,
        private StripePaymentIntentMapper $mapper,
        private StripeIdempotencyManager $idempotency,
        private StripeErrorHandler $errorHandler
    ) {}

    public function createPaymentIntent(
        PaymentContract $contract,
        array $options = []
    ): PaymentIntentResult {
        try {
            $basket = $contract->getBasketSnapshot();

            $intent = $this->stripeClient->paymentIntents->create([
                'amount' => (int) ($basket->getTotalGross() * 100),
                'currency' => strtolower($basket->getCurrency()),
                'metadata' => [
                    'contract_id' => $contract->getId(),
                    'user_id' => $contract->getUserId(),
                ],
                'capture_method' => 'manual', // Two-step: auth → capture
            ], [
                'idempotency_key' => $this->idempotency->generate($contract->getId())
            ]);

            return PaymentIntentResult::fromStripePaymentIntent($intent);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw $this->errorHandler->handle($e);
        }
    }

    public function capturePayment(
        string $providerOrderId,
        ?float $amount = null
    ): CaptureResult {
        try {
            $params = [];
            if ($amount !== null) {
                $params['amount_to_capture'] = (int) ($amount * 100);
            }

            $intent = $this->stripeClient->paymentIntents->capture(
                $providerOrderId,
                $params
            );

            return CaptureResult::fromStripePaymentIntent($intent);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw $this->errorHandler->handle($e);
        }
    }

    public function supports(string $provider): bool
    {
        return $provider === 'stripe';
    }
}
```

**Tests (8 tests):**
```php
public function testCreatePaymentIntentWithContract(): void
public function testCreatePaymentIntentConvertsAmountToCents(): void
public function testCreatePaymentIntentUsesManualCaptureMethod(): void
public function testCreatePaymentIntentIncludesMetadata(): void
public function testCapturePaymentWithFullAmount(): void
public function testCapturePaymentWithPartialAmount(): void
public function testThrowsExceptionOnStripeApiError(): void
public function testSupportsStripeProvider(): void
```

---

#### Task 2.4: StripePaymentIntentMapper (State Mapping)

**File:** `src/Stripe/Service/StripePaymentIntentMapper.php`

```php
class StripePaymentIntentMapper
{
    public function mapStripeStatusToContractState(string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'requires_payment_method' => 'draft',
            'requires_confirmation' => 'draft',
            'requires_action' => 'pending',
            'processing' => 'pending',
            'requires_capture' => 'ready_to_commit',
            'canceled' => 'cancelled',
            'succeeded' => 'fulfilled',
            default => throw new \InvalidArgumentException("Unknown Stripe status: {$stripeStatus}")
        };
    }

    public function mapContractStateToStripeStatus(string $contractState): ?string
    {
        return match ($contractState) {
            'draft' => 'requires_payment_method',
            'pending' => 'processing',
            'ready_to_commit' => 'requires_capture',
            'committed' => 'requires_capture',
            'fulfilled' => 'succeeded',
            'cancelled' => 'canceled',
            default => null
        };
    }
}
```

**Tests (6 tests):**
```php
public function testMapsRequiresCaptureTo ReadyToCommit(): void
public function testMapsSucceededToFulfilled(): void
public function testMapsCanceledToCancelled(): void
public function testThrowsExceptionForUnknownStatus(): void
public function testMapsDraftStateToRequiresPaymentMethod(): void
public function testMapsCommittedStateToRequiresCapture(): void
```

---

#### Task 2.5: StripeIdempotencyManager

**File:** `src/Stripe/Service/StripeIdempotencyManager.php`

```php
class StripeIdempotencyManager
{
    public function generate(string $contractId, string $operation = ''): string
    {
        $data = $contractId . $operation . date('Y-m-d');
        return hash('sha256', $data);
    }

    public function generateForContract(
        PaymentContract $contract,
        string $operation
    ): string {
        return $this->generate($contract->getId(), $operation);
    }
}
```

**Tests (3 tests):**
```php
public function testGeneratesConsistentKeyForSameInput(): void
public function testGeneratesDifferentKeyForDifferentOperation(): void
public function testGeneratesKeyIncludesDate(): void
```

---

#### Task 2.6: StripeErrorHandler

**File:** `src/Stripe/Service/StripeErrorHandler.php`

```php
class StripeErrorHandler
{
    public function handle(\Stripe\Exception\ApiErrorException $e): PaymentAdapterException
    {
        $message = $e->getMessage();
        $code = $e->getStripeCode() ?? 'unknown';
        $httpStatus = $e->getHttpStatus();

        return match (true) {
            $e instanceof \Stripe\Exception\CardException =>
                new PaymentDeclinedException($message, $code, $e),
            $e instanceof \Stripe\Exception\RateLimitException =>
                new PaymentRateLimitException($message, $code, $e),
            $e instanceof \Stripe\Exception\InvalidRequestException =>
                new PaymentInvalidRequestException($message, $code, $e),
            $e instanceof \Stripe\Exception\AuthenticationException =>
                new PaymentAuthenticationException($message, $code, $e),
            default =>
                new PaymentAdapterException($message, $code, $e)
        };
    }

    public function isRetryable(\Stripe\Exception\ApiErrorException $e): bool
    {
        return $e instanceof \Stripe\Exception\RateLimitException
            || ($e->getHttpStatus() >= 500);
    }
}
```

**Tests (5 tests):**
```php
public function testHandlesCardException(): void
public function testHandlesRateLimitException(): void
public function testHandlesInvalidRequestException(): void
public function testHandlesAuthenticationException(): void
public function testIdentifiesRetryableErrors(): void
```

---

### Phase 3: Integration with Event Handlers (4-5 hours)

#### Task 3.1: Update PaymentAuthorizationHandler

**Current:** Fulfills condition with dummy data
**New:** Call StripeAdapter to create PaymentIntent

**Changes to:** `src/Component/EventSystem/Handler/PaymentAuthorizationHandler.php`

```php
public function __construct(
    private ContractRepository $contractRepository,
    private EventDispatcher $eventDispatcher,
    private PaymentProviderFactory $paymentProviderFactory // NEW
) {}

public function handle(ContractTransitionedToPendingEvent $event): void
{
    $contract = $event->getContract();
    $context = $event->getContext();

    // NEW: Create PaymentIntent via adapter
    $adapter = $this->paymentProviderFactory->createAdapter('stripe');
    $result = $adapter->createPaymentIntent($contract);

    // Fulfill condition with real provider data
    $contract->fulfillCondition(
        ContractCondition::TYPE_PAYMENT_AUTHORIZED,
        [
            'authorizationId' => $result->providerOrderId,
            'providerOrderId' => $result->providerOrderId,
            'clientSecret' => $result->clientSecret,
            'status' => $result->status,
        ]
    );

    $contract->setProvider('stripe', $result->providerOrderId);
    $this->contractRepository->save($contract);

    if ($contract->areAllConditionsFulfilled()) {
        $readyEvent = new ContractReadyToCommitEvent($contract, $context, []);
        $this->eventDispatcher->dispatch($readyEvent);
    }
}
```

**New Tests (4 tests):**
```php
public function testCreatesPaymentIntentViaAdapter(): void
public function testStoresProviderOrderIdInContract(): void
public function testStoresClientSecretInConditionData(): void
public function testHandlesAdapterException(): void
```

---

#### Task 3.2: Update ContractFulfillmentHandler for Capture

**Current:** Just marks contract as fulfilled
**New:** Call StripeAdapter to capture payment

**Changes to:** `src/Component/EventSystem/Handler/ContractFulfillmentHandler.php`

```php
public function __construct(
    private ContractRepository $contractRepository,
    private InMemoryOrderRepository $orderRepository,
    private EventDispatcher $eventDispatcher,
    private PaymentProviderFactory $paymentProviderFactory // NEW
) {}

public function handle(WebhookReceivedEvent $event): void
{
    if (!$this->isFulfillmentEvent($event)) {
        return;
    }

    $contractId = $event->getContext()->get('contractId');
    $contract = $this->contractRepository->findById($contractId);

    if (!$contract->getState()->isCommitted()) {
        throw new \DomainException('Contract must be COMMITTED before fulfillment');
    }

    // NEW: Capture payment via adapter
    $adapter = $this->paymentProviderFactory->createAdapter('stripe');
    $result = $adapter->capturePayment($contract->getProviderOrderId());

    // Mark contract as fulfilled
    $contract->fulfill();
    $this->contractRepository->save($contract);

    // Update order status
    if ($orderId = $contract->getOrderId()) {
        $order = $this->orderRepository->findById((int) $orderId);
        if ($order) {
            $order->setStatus('completed');
            $this->orderRepository->save($order);
        }
    }

    $fulfilledEvent = new ContractFulfilledEvent($contract, $event->getContext(), $orderId ?? '');
    $this->eventDispatcher->dispatch($fulfilledEvent);
}
```

**New Tests (3 tests):**
```php
public function testCapturesPaymentViaAdapter(): void
public function testStoresCaptureResultInContract(): void
public function testHandlesCaptureException(): void
```

---

### Phase 4: Integration Tests with Stripe Sandbox (3-4 hours)

#### Task 4.1: Set up Stripe Test Environment

**File:** `tests/Integration/Stripe/.env.test`

```env
STRIPE_TEST_API_KEY=sk_test_...
STRIPE_TEST_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_test_...
```

**File:** `tests/Integration/Stripe/StripeTestCase.php`

```php
abstract class StripeTestCase extends TestCase
{
    protected \Stripe\StripeClient $stripeClient;
    protected StripePaymentAdapter $adapter;

    protected function setUp(): void
    {
        $apiKey = getenv('STRIPE_TEST_API_KEY');
        if (!$apiKey) {
            $this->markTestSkipped('Stripe API key not configured');
        }

        $factory = new StripeClientFactory();
        $this->stripeClient = $factory->create($apiKey);

        $this->adapter = new StripePaymentAdapter(
            $this->stripeClient,
            new StripePaymentIntentMapper(),
            new StripeIdempotencyManager(),
            new StripeErrorHandler()
        );
    }

    protected function createTestContract(): PaymentContract
    {
        // Helper method
    }
}
```

---

#### Task 4.2: Integration Tests

**File:** `tests/Integration/Stripe/StripePaymentFlowTest.php`

```php
class StripePaymentFlowTest extends StripeTestCase
{
    public function testCompletePaymentFlow(): void
    {
        // Create contract
        $contract = $this->createTestContract();

        // 1. Create PaymentIntent
        $result = $this->adapter->createPaymentIntent($contract);
        $this->assertNotEmpty($result->providerOrderId);
        $this->assertNotEmpty($result->clientSecret);
        $this->assertEquals('requires_payment_method', $result->status);

        // 2. Simulate payment method attachment (would be done by frontend/webhook)
        // For test, we'll directly update the PaymentIntent status

        // 3. Capture payment
        $captureResult = $this->adapter->capturePayment($result->providerOrderId);
        $this->assertEquals('succeeded', $captureResult->status);

        // 4. Verify final status
        $statusResult = $this->adapter->getPaymentStatus($result->providerOrderId);
        $this->assertEquals('succeeded', $statusResult->status);
    }

    public function testRefundFlow(): void
    {
        // Create and capture payment
        $contract = $this->createTestContract();
        $intentResult = $this->adapter->createPaymentIntent($contract);

        // Simulate successful payment...

        $captureResult = $this->adapter->capturePayment($intentResult->providerOrderId);

        // Refund
        $refundResult = $this->adapter->refundPayment(
            $intentResult->providerOrderId,
            $captureResult->amount
        );

        $this->assertEquals('succeeded', $refundResult->status);
    }

    public function testCancellationFlow(): void
    {
        $contract = $this->createTestContract();
        $intentResult = $this->adapter->createPaymentIntent($contract);

        // Cancel before capture
        $cancellationResult = $this->adapter->cancelPayment(
            $intentResult->providerOrderId,
            'User requested cancellation'
        );

        $this->assertEquals('canceled', $cancellationResult->status);
    }
}
```

**Tests:** 3 integration tests

---

## ✅ Acceptance Criteria

### Definition of Done

- [ ] PaymentAdapterInterface defined with all methods
- [ ] 6 Result value objects implemented
- [ ] PaymentProviderFactory implemented
- [ ] StripePaymentAdapter fully implemented
- [ ] StripeClientFactory configured
- [ ] StripePaymentIntentMapper with state mapping
- [ ] StripeIdempotencyManager implemented
- [ ] StripeErrorHandler with retry logic
- [ ] PaymentAuthorizationHandler integrated
- [ ] ContractFulfillmentHandler integrated
- [ ] All unit tests passing (30+ tests)
- [ ] Integration tests with Stripe sandbox (3 tests)
- [ ] Code coverage > 90%
- [ ] No hardcoded API keys
- [ ] All exceptions properly handled
- [ ] Documentation updated

### Test Coverage Requirements

| Component | Min Tests | Min Coverage |
|-----------|-----------|--------------|
| PaymentAdapterInterface | 5 | 100% |
| Result Value Objects | 6 | 100% |
| PaymentProviderFactory | 3 | 100% |
| StripeClientFactory | 2 | 100% |
| StripePaymentAdapter | 8 | 95% |
| StripePaymentIntentMapper | 6 | 100% |
| StripeIdempotencyManager | 3 | 100% |
| StripeErrorHandler | 5 | 95% |
| Handler Integrations | 7 | 90% |
| Integration Tests | 3 | - |
| **TOTAL** | **48+** | **95%** |

---

## 📊 Estimated Timeline

| Phase | Tasks | Effort | Duration |
|-------|-------|--------|----------|
| Phase 1 | Interface & Factory | 2-3h | 0.5 day |
| Phase 2 | Stripe Adapter | 6-8h | 1 day |
| Phase 3 | Handler Integration | 4-5h | 0.5 day |
| Phase 4 | Integration Tests | 3-4h | 0.5 day |
| **TOTAL** | **All Phases** | **15-20h** | **2.5 days** |

---

## 🔗 Dependencies

### Required (COMPLETE)
- ✅ TICKET-07: Event Handlers & Dispatcher
- ✅ TICKET-06: Contract Domain Layer
- ✅ Event System

### Blocks
- 🔴 TICKET-09: Webhook Processing
- 🔴 TICKET-12: One-Page Checkout
- 🔴 TICKET-13: Capture & Refund Operations

---

## 📚 Resources

**Stripe Documentation:**
- Payment Intents API: https://stripe.com/docs/api/payment_intents
- PHP SDK: https://github.com/stripe/stripe-php
- Testing: https://stripe.com/docs/testing

**Architecture Docs:**
- `04-sdk-adapter-layer.md` - Adapter pattern architecture
- `01-architecture-layers.md` - Event-driven architecture

**Related Code:**
- `/src/Component/EventSystem/Handler/` - Event handlers
- `/src/Component/Contract/` - Contract domain

---

**Priority:** 🔴 START IMMEDIATELY
**Status:** 🔴 READY TO START
**Next Steps:** Install Stripe SDK, create interface, write first test

*Last Updated: 2025-10-30*
