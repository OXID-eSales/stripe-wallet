# Sprint 3: PaymentCaptureService & PaymentRefundService Investigation

**Date:** 2026-01-20
**Priority:** Medium
**Estimated Effort:** 4-6 hours
**Type:** Architectural Investigation & Refactoring

---

## Core Development Principles

All code in this sprint MUST follow:

| Principle | Requirement |
|-----------|-------------|
| **TDD-First** | Write failing tests BEFORE implementation. Red → Green → Refactor |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **Liskov Substitution** | Subtypes must be substitutable for their base types |
| **Dependency Injection** | Depend on abstractions, not concretions. Inject dependencies via constructor |
| **DRY** | Don't Repeat Yourself. Extract common logic to shared methods/classes |
| **Clean Code** | Meaningful names, small functions (15-25 lines), early returns (no else), single responsibility per method |
| **No Over-Engineering** | Only add what's needed now. No speculative features or premature abstractions |

### Testing Commands

Run from `payment-component/` or `stripe/` directory:

```bash
# Quick check (unit tests + style checks)
./bin/pre-commit-check.sh

# Full check (unit tests + integration tests + style checks)
./bin/pre-commit-check.sh --full
```

---

## Executive Summary

The component provides `PaymentCaptureService` and `PaymentRefundService` for capture/refund operations. However, Stripe has its own implementations (`StripeCaptureRequestHandler`, `StripeRefundRequestHandler` with `RefundService`).

**This is an architectural violation** - similar to the `ContractCreationHandler` issue identified in Sprint 1. The component services should provide reusable logic that provider modules extend, not duplicate.

---

## Q&A Decisions (2026-01-20)

| Question | Decision |
|----------|----------|
| Q1: Unused services | **B) Refactor to Abstract** - Template Method pattern |
| Q2: Scope | **A) Both Capture & Refund** - Full consistency |
| Q3: State validation | **B) Hook method with default** - base checks COMMITTED, Stripe overrides for AUTHORIZED |
| Q4: Adapter handling | **B) Constructor injection** - Liskov Substitution Principle |
| Q5: After capture | **A) Hook method `afterCapture()`** - default does nothing/fulfill, Stripe overrides |
| Q6: Refund identifier | **A) Use contractId** - contract-centric architecture |
| Q7: Partial refunds | **A) Yes, in base class** - `refund(contractId, ?amount)` |
| Q8: Error handling | **A) Throw exceptions** - `CaptureFailedException`, `RefundFailedException` |
| Q9: Stripe integration | **A) Create Stripe services** - handlers delegate to services |

---

## Current State Analysis

### Component's PaymentCaptureService

**Location:** `payment-component/src/Service/PaymentCaptureService.php`

```php
class PaymentCaptureService
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly PaymentAdapterInterface $paymentAdapter,  // <-- Uses component's adapter
        private readonly LoggerInterface $logger
    ) {}

    public function capturePayment(string $contractId, ?float $amount = null): array
    {
        // 1. Load contract
        // 2. Validate state (must be COMMITTED)
        // 3. Get capture amount
        // 4. Call $this->paymentAdapter->capturePayment($request)
        // 5. Fulfill contract
        // 6. Return result
    }
}
```

### Stripe's StripeCaptureRequestHandler

**Location:** `stripe/src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php`

```php
class StripeCaptureRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly StripeAdapterInterface $stripeAdapter,  // <-- Uses Stripe-specific adapter
        private readonly ContractRepositoryInterface $contractRepository,
        // ...
    ) {}

    public function handle(object $event): void
    {
        // 1. Extract from event
        // 2. Support TWO modes: direct capture (admin) AND contract-based capture
        // 3. Validate state (must be AUTHORIZED, not COMMITTED!)
        // 4. Call $this->stripeAdapter->capturePayment($request)
        // 5. Transition AUTHORIZED -> READY_TO_COMMIT (not fulfill!)
        // 6. Log to RequestLog (Stripe-specific)
        // 7. Set results in context
    }
}
```

### Key Differences Found

| Aspect | Component Service | Stripe Handler |
|--------|-------------------|----------------|
| **Adapter** | `PaymentAdapterInterface` | `StripeAdapterInterface` |
| **State validation** | Must be COMMITTED | Must be AUTHORIZED |
| **After capture** | Calls `fulfill()` | Calls `captureAuthorization()` |
| **Direct capture mode** | Not supported | Supports admin panel captures |
| **Request logging** | Not implemented | Logs to `RequestLog` |
| **Invocation** | Direct method call | Event-driven |

### Root Cause

The component's `PaymentCaptureService` was designed with **different assumptions**:
1. Assumes `COMMITTED` state before capture (immediate capture flow)
2. Stripe uses `AUTHORIZED` state before capture (delayed capture / manual capture flow)
3. Component calls `fulfill()` directly, Stripe needs `captureAuthorization()` first

---

## Same Pattern for Refund

### Component's PaymentRefundService

```php
class PaymentRefundService
{
    public function refundPayment(string $contractId, ?float $amount = null, string $reason = ''): array
    {
        // Uses PaymentAdapterInterface
        // Validates FULFILLED state
        // Tracks refunds via TransactionRepository
    }
}
```

### Stripe's RefundService

```php
interface RefundServiceInterface
{
    public function processFullRefund(
        string $orderId,  // <-- Uses orderId, not contractId!
        ?string $paymentIntentId = null,
        ?string $reason = null,
        ?string $description = null,
        string $initiator = 'admin'
    ): RefundResult;

    public function processPartialRefund(...): RefundResult;
}
```

### Key Differences

| Aspect | Component Service | Stripe Service |
|--------|-------------------|----------------|
| **Input** | `contractId` | `orderId` + optional `paymentIntentId` |
| **Tracking** | `TransactionRepository` | Stripe-specific logging |
| **Reason handling** | Simple string | Stripe-specific reasons + description + initiator |

---

## Architectural Options

### Option A: Make Component Services Abstract (Recommended)

Refactor component services to be extensible:

```php
// Component's abstract capture service
abstract class AbstractPaymentCaptureService implements PaymentCaptureServiceInterface
{
    public function __construct(
        protected readonly ContractRepositoryInterface $contractRepository,
        protected readonly LoggerInterface $logger
    ) {}

    final public function capturePayment(string $contractId, ?float $amount = null): CaptureResult
    {
        $contract = $this->loadAndValidateContract($contractId);
        $captureAmount = $this->determineCaptureAmount($contract, $amount);

        // Hook for provider-specific validation
        $this->validateBeforeCapture($contract);

        // Provider implements this
        $response = $this->executeCapture($contract, $captureAmount);

        // Hook for provider-specific post-processing
        $this->afterCapture($contract, $response);

        return $response;
    }

    protected function loadAndValidateContract(string $contractId): PaymentContractInterface
    {
        // Common validation logic
    }

    // Provider must implement
    abstract protected function executeCapture(
        PaymentContractInterface $contract,
        float $amount
    ): CaptureResponse;

    // Provider can override
    protected function validateBeforeCapture(PaymentContractInterface $contract): void
    {
        // Default: check COMMITTED state
    }

    protected function afterCapture(PaymentContractInterface $contract, CaptureResponse $response): void
    {
        // Default: fulfill contract
    }
}

// Stripe extends it
class StripeCaptureService extends AbstractPaymentCaptureService
{
    public function __construct(
        ContractRepositoryInterface $contractRepository,
        LoggerInterface $logger,
        private readonly StripeAdapterInterface $stripeAdapter
    ) {
        parent::__construct($contractRepository, $logger);
    }

    protected function validateBeforeCapture(PaymentContractInterface $contract): void
    {
        // Stripe: check AUTHORIZED state (for delayed capture)
        if (!$contract->getState()->isAuthorized()) {
            throw new InvalidContractStateException('Must be AUTHORIZED for Stripe delayed capture');
        }
    }

    protected function executeCapture(PaymentContractInterface $contract, float $amount): CaptureResponse
    {
        return $this->stripeAdapter->capturePayment(new CapturePaymentRequest(
            providerPaymentId: $contract->getProviderOrderId(),
            amount: $amount
        ));
    }

    protected function afterCapture(PaymentContractInterface $contract, CaptureResponse $response): void
    {
        // Stripe: transition AUTHORIZED -> READY_TO_COMMIT
        $contract->captureAuthorization();
        $this->contractRepository->save($contract);
    }
}
```

### Option B: Composition Pattern

Create a shared service that handlers use:

```php
// Component provides core capture logic
class CaptureOperationService
{
    public function validateContractForCapture(PaymentContractInterface $contract, array $allowedStates): void
    {
        // Shared validation
    }

    public function calculateCaptureAmount(PaymentContractInterface $contract, ?float $requestedAmount): float
    {
        // Shared calculation
    }

    public function recordCapture(PaymentContractInterface $contract, CaptureResponse $response): void
    {
        // Shared recording
    }
}

// Stripe handler uses it
class StripeCaptureRequestHandler
{
    public function __construct(
        private readonly CaptureOperationService $captureOps,  // Component service
        private readonly StripeAdapterInterface $stripeAdapter,
        // ...
    ) {}

    public function handle(object $event): void
    {
        $contract = $this->getContract($event);

        // Use component service for validation
        $this->captureOps->validateContractForCapture($contract, ['authorized']);

        // Use component service for amount calculation
        $amount = $this->captureOps->calculateCaptureAmount($contract, $event->getAmount());

        // Execute via Stripe adapter
        $response = $this->stripeAdapter->capturePayment(...);

        // Use component service for recording
        $this->captureOps->recordCapture($contract, $response);

        // Stripe-specific: state transition
        $contract->captureAuthorization();
    }
}
```

### Option C: Keep Separate (Document Decision)

If the differences are intentional:
1. Remove unused component services
2. Document that capture/refund is provider-specific
3. Update architecture docs

---

## Investigation Tasks

### Task 1: Verify State Machine Assumptions

```bash
# Check what states are valid for capture in component
grep -r "isCommitted\|isAuthorized" payment-component/src/Service/

# Check what Stripe expects
grep -r "isAuthorized\|captureAuthorization" stripe/src/
```

**Questions to answer:**
1. Is `PaymentCaptureService` designed for immediate capture (no authorization hold)?
2. Should component support both immediate and delayed capture?

### Task 2: Check Adapter Compatibility

```bash
# Compare adapter interfaces
diff -y payment-component/src/Adapter/PaymentAdapterInterface.php \
       stripe/src/Stripe/Adapter/StripeAdapterInterface.php
```

**Questions to answer:**
1. Can `StripeAdapter` implement `PaymentAdapterInterface`?
2. What methods are missing/different?

### Task 3: Analyze Refund Flow

```bash
# Check if component's refund service is compatible with Stripe's needs
grep -r "refund" stripe/src/Stripe/Service/
```

### Task 4: Review Architecture Docs

Check if the component docs specify how providers should implement capture/refund:
- `architecture/01-architecture-layers.md`
- `architecture/04-sdk-adapter-layer.md`

---

## Recommended Action

**Based on initial analysis, recommend Option A (Template Method Pattern):**

1. Refactor `PaymentCaptureService` to `AbstractPaymentCaptureService`
2. Create `StripeCaptureService extends AbstractPaymentCaptureService`
3. Update `StripeCaptureRequestHandler` to use `StripeCaptureService`
4. Apply same pattern to refund services

**Benefits:**
- Reuses common validation logic
- Allows provider-specific state machine handling
- Follows same pattern as recommended for `ContractCreationHandler` (Sprint 1)

---

## Implementation Phases

### Phase 1: Investigation (2 hours)
1. Complete all investigation tasks above
2. Document findings
3. Confirm architectural approach

### Phase 2: Refactor Capture Service (2 hours)
1. Create `AbstractPaymentCaptureService` with template method
2. Create `StripeCaptureService` extending it
3. Update handler to use service
4. Write tests

### Phase 3: Refactor Refund Service (2 hours)
1. Apply same pattern to refund services
2. Update handler
3. Write tests

---

## Definition of Done

- [ ] Investigation tasks completed and documented
- [ ] Architectural decision made and documented
- [ ] If Option A/B: Services refactored following TDD
- [ ] If Option C: Services removed, docs updated
- [ ] All tests pass
- [ ] PHPStan level 6 passes

---

## Files Involved

### DO NOT REMOVE YET
```
# Component services - need refactoring
payment-component/src/Service/PaymentCaptureService.php
payment-component/src/Service/PaymentRefundService.php
```

### Related Stripe Files
```
# Stripe implementations to potentially refactor
stripe/src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php
stripe/src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php
stripe/src/Stripe/Service/RefundService.php
stripe/src/Stripe/Service/RefundServiceInterface.php
```

---

## References

- Sprint 1: ContractCreationHandler architectural violation
- Architecture: `architecture/04-sdk-adapter-layer.md`
- Stripe capture handler: `StripeCaptureRequestHandler.php`
