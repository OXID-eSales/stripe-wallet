# Sprint 31: DTO Consolidation - Use Response Classes Only

**Created:** 2026-01-30
**Status:** TODO

---

## Core Requirements

All code must follow these principles:

| Requirement | Description |
|-------------|-------------|
| **TDD-First** | Write failing tests first, then implementation |
| **SOLID** | Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion |
| **DI** | Depend on abstractions (interfaces), not concretions |
| **Liskov Interfaces** | Stripe DTOs implement component interfaces where applicable |
| **DRY** | Don't Repeat Yourself - single DTO layer, no duplication |
| **Clean Code** | Meaningful names, small functions, no else expressions, readonly classes |

---

## Testing Approach

### Pre-commit Validation

```bash
# Run all checks before committing
./bin/pre-commit-check.sh

# Checks performed:
# 1. PHP Code Sniffer (PSR-12)
# 2. PHPStan (level 6)
# 3. PHPMD
# 4. Unit tests
```

### TDD Workflow

1. **Write failing test** for new Response factory methods
2. **Implement** the factory method
3. **Refactor** if needed
4. **Run pre-commit-check.sh** to verify all passes

### Test Commands

```bash
# Payment-component unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/payment-component/tests/phpunit.xml --testsuite Unit

# Stripe unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Single test file
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Adapter/Response/ReconciliationResponseTest.php
```

---

## Problem Statement

The payment-component has **parallel DTO hierarchies** causing duplication and confusion:

- `Adapter/Response/` - DTOs returned by adapters
- `Service/Result/` - DTOs returned by services (duplicates Response with minor additions)

**Direction:** Consolidate to a single DTO layer in `Adapter/Response/`. Delete `Service/Result/` entirely.

---

## Current State

### Adapter/Response/ (to keep and enhance)

| File | Fields |
|------|--------|
| `CaptureResponse.php` | providerPaymentId, captureId, amountCaptured, currency, status, capturedAt, providerData, metadata |
| `RefundResponse.php` | providerPaymentId, refundId, amountRefunded, currency, status, refundedAt, reason, providerData, metadata |
| `VoidResponse.php` | providerPaymentId, status, voidedAt, providerData, metadata |

### Service/Result/ (to delete)

| File | Fields | Notes |
|------|--------|-------|
| `CaptureResult.php` | successful, captureId, amountCaptured, currency, capturedAt, errorMessage, errorCode, providerData | Duplicates CaptureResponse |
| `RefundResult.php` | successful, refundId, amountRefunded, currency, totalRefunded, availableForRefund, refundedAt, status, errorMessage, errorCode, providerData | Duplicates RefundResponse + business fields |
| `CancellationResult.php` | successful, authorizationId, status, errorMessage, errorCode | Duplicates VoidResponse |
| `FraudCheckResult.php` | passed, score, reason | No matching Response (needs creation) |

---

## Decisions Made

| Decision | Choice |
|----------|--------|
| Which layer to keep | **Adapter/Response/** |
| Error handling | **Add error fields to Response classes** (successful, errorMessage, errorCode) |
| Business fields (totalRefunded, etc.) | **Keep in service layer** - not in Response |
| FraudCheckResult | **Create FraudCheckResponse** in Adapter/Response/ |
| VoidResponse naming | **Rename to CancellationResponse** |
| Service/Result/ folder | **Delete entirely** |

---

## Target State

### CaptureResponse.php (enhanced)

```php
final readonly class CaptureResponse
{
    public function __construct(
        public bool $successful,  // NEW
        public ?string $providerPaymentId,
        public ?string $captureId,
        public ?float $amountCaptured,
        public ?string $currency,
        public ?string $status,
        public ?\DateTimeInterface $capturedAt,
        public ?string $errorMessage = null,  // NEW
        public ?string $errorCode = null,  // NEW
        public array $providerData = [],
        public array $metadata = [],
    ) {}

    public static function success(
        string $providerPaymentId,
        string $captureId,
        float $amountCaptured,
        string $currency,
        string $status,
        \DateTimeInterface $capturedAt,
        array $providerData = [],
        array $metadata = []
    ): self

    public static function failure(string $errorMessage, ?string $errorCode = null): self

    public function isSuccessful(): bool
}
```

### RefundResponse.php (enhanced)

```php
final readonly class RefundResponse
{
    public function __construct(
        public bool $successful,  // NEW
        public ?string $providerPaymentId,
        public ?string $refundId,
        public ?float $amountRefunded,
        public ?string $currency,
        public ?string $status,
        public ?\DateTimeInterface $refundedAt,
        public ?string $reason = null,
        public ?string $errorMessage = null,  // NEW
        public ?string $errorCode = null,  // NEW
        public array $providerData = [],
        public array $metadata = [],
    ) {}

    public static function success(
        string $providerPaymentId,
        string $refundId,
        float $amountRefunded,
        string $currency,
        string $status,
        \DateTimeInterface $refundedAt,
        ?string $reason = null,
        array $providerData = [],
        array $metadata = []
    ): self

    public static function failure(string $errorMessage, ?string $errorCode = null): self

    public function isSuccessful(): bool
}
```

### CancellationResponse.php (renamed from VoidResponse, enhanced)

```php
final readonly class CancellationResponse
{
    public function __construct(
        public bool $successful,  // NEW
        public ?string $providerPaymentId,
        public ?string $authorizationId,  // NEW (was implicit)
        public ?string $status,
        public ?\DateTimeInterface $cancelledAt,  // RENAMED from voidedAt
        public ?string $errorMessage = null,  // NEW
        public ?string $errorCode = null,  // NEW
        public array $providerData = [],
        public array $metadata = [],
    ) {}

    public static function success(
        string $providerPaymentId,
        string $authorizationId,
        string $status,
        \DateTimeInterface $cancelledAt,
        array $providerData = [],
        array $metadata = []
    ): self

    public static function failure(string $errorMessage, ?string $errorCode = null): self

    public function isSuccessful(): bool
}
```

### FraudCheckResponse.php (new)

```php
final readonly class FraudCheckResponse
{
    public function __construct(
        public bool $successful,
        public ?float $score,
        public ?string $reason = null,
        public ?string $errorMessage = null,
        public ?string $errorCode = null,
    ) {}

    public static function success(float $score): self
    public static function failure(float $score, string $reason): self

    public function isSuccessful(): bool
    public function getScore(): ?float
    public function getReason(): ?string
}
```

---

## Service Layer Changes

Services that need business fields (totalRefunded, availableForRefund) will compute and return them separately:

### Option A: Return tuple/array

```php
// AbstractPaymentRefundService
public function refund(...): array {
    $response = $this->adapter->refundPayment($request);
    return [
        'response' => $response,
        'totalRefunded' => $newTotalRefunded,
        'availableForRefund' => $newAvailableForRefund,
    ];
}
```

### Option B: Service-specific wrapper (if needed later)

```php
// Only create if truly needed
final readonly class RefundServiceResult {
    public function __construct(
        public RefundResponse $response,
        public float $totalRefunded,
        public float $availableForRefund,
    ) {}
}
```

For now, use Option A (return array) to avoid creating new DTOs.

---

## Files to Modify

### Phase 1: Enhance Response Classes (payment-component)

| File | Changes |
|------|---------|
| `src/Adapter/Response/CaptureResponse.php` | Add successful, errorMessage, errorCode; add factory methods |
| `src/Adapter/Response/RefundResponse.php` | Add successful, errorMessage, errorCode; add factory methods |
| `src/Adapter/Response/VoidResponse.php` | RENAME to CancellationResponse; add fields |

### Phase 2: Create New Response (payment-component)

| File | Action |
|------|--------|
| `src/Adapter/Response/FraudCheckResponse.php` | CREATE new file |

### Phase 3: Update PaymentAdapterInterface (payment-component)

| File | Changes |
|------|---------|
| `src/Adapter/PaymentAdapterInterface.php` | voidPayment returns CancellationResponse |

### Phase 4: Update Adapters (stripe)

| File | Changes |
|------|---------|
| `src/Stripe/Adapter/StripeAdapter.php` | Use factory methods; return CancellationResponse |
| `src/Stripe/Adapter/LazyStripeAdapter.php` | Update delegated types |

### Phase 5: Update Abstract Services (payment-component)

| File | Changes |
|------|---------|
| `src/Service/AbstractPaymentRefundService.php` | Return RefundResponse (or array with business fields) |
| `src/Service/AbstractPaymentCaptureService.php` | Return CaptureResponse |

### Phase 6: Update Stripe Services

| File | Changes |
|------|---------|
| `src/Stripe/Service/RefundService.php` | Return RefundResponse instead of RefundResult |
| `src/Stripe/Service/CaptureService.php` | Return CaptureResponse instead of CaptureResult |
| `src/Stripe/Service/CancelAuthorizationService.php` | Return CancellationResponse |
| Other services | Update imports and return types |

### Phase 7: Delete Service/Result/ (payment-component)

| File | Action |
|------|--------|
| `src/Service/Result/CaptureResult.php` | DELETE |
| `src/Service/Result/RefundResult.php` | DELETE |
| `src/Service/Result/CancellationResult.php` | DELETE |
| `src/Service/Result/FraudCheckResult.php` | DELETE |
| `src/Service/Result/` folder | DELETE |

### Phase 8: Delete VoidResponse (payment-component)

| File | Action |
|------|--------|
| `src/Adapter/Response/VoidResponse.php` | DELETE (replaced by CancellationResponse) |

### Phase 9: Update Tests

| Location | Changes |
|----------|---------|
| `payment-component/tests/Unit/Service/Result/*` | DELETE test files |
| `payment-component/tests/Unit/Adapter/Response/*` | Update for new fields |
| `stripe/tests/Unit/Stripe/Service/*` | Update for Response return types |

---

## Breaking Changes

### 1. Return Type Changes

```php
// Services now return Response instead of Result
RefundService::processFullRefund(): RefundResponse  // was RefundResult
CaptureService::capture(): CaptureResponse  // was CaptureResult
CancelAuthorizationService::cancel(): CancellationResponse  // was CancellationResult
```

### 2. Method Name Changes

```php
// Response uses isSuccessful() (same as Result)
$response->isSuccessful()  // works same as before
```

### 3. Removed Classes

```php
// These no longer exist
CaptureResult::class
RefundResult::class
CancellationResult::class
FraudCheckResult::class
```

### 4. Renamed Class

```php
// VoidResponse renamed
VoidResponse::class  // REMOVED
CancellationResponse::class  // NEW
```

### 5. Business Fields Location

```php
// totalRefunded, availableForRefund no longer in Response
// Services return them separately if needed
$result = $service->refund(...);
$response = $result['response'];
$totalRefunded = $result['totalRefunded'];
```

---

## Implementation Order

1. **Create FraudCheckResponse** (new file, no dependencies)
2. **Rename VoidResponse → CancellationResponse** (update all usages)
3. **Enhance Response classes** with error fields and factory methods
4. **Update PaymentAdapterInterface** return types
5. **Update StripeAdapter** to use factory methods
6. **Update abstract services** to return Response
7. **Update Stripe services** to return Response
8. **Delete Service/Result/** folder and files
9. **Update all tests**
10. **Run pre-commit checks**

---

## Acceptance Criteria

- [ ] No `Service/Result/` folder exists
- [ ] All Response classes have `success()`/`failure()` factory methods
- [ ] All Response classes have `isSuccessful()`, `errorMessage`, `errorCode`
- [ ] `FraudCheckResponse` created in `Adapter/Response/`
- [ ] `VoidResponse` renamed to `CancellationResponse`
- [ ] All services return Response objects (not Result)
- [ ] Business fields (totalRefunded, etc.) handled in service layer
- [ ] All unit tests pass
- [ ] PHPStan passes
- [ ] `./bin/pre-commit-check.sh` passes

---

## Benefits

1. **Single DTO layer** - No duplication between Response and Result
2. **Clear ownership** - DTOs belong to Adapter layer
3. **Simpler mental model** - One place to look for DTOs
4. **Consistent naming** - All end with `Response`
5. **Less code** - ~400 lines deleted (4 Result files)

---

## Part 2: Stripe-Level Cleanup

### Current Stripe DTOs

Location: `stripe/src/Stripe/Service/Result/`

| File | Pattern | Issues |
|------|---------|--------|
| `SecurityValidationResult.php` | Implements interface from component | Good - keeps interface |
| `CheckoutSessionResult.php` | `success()`/`failure()` factories | In wrong folder (`Service/Result/`) |
| `CheckoutReturnResult.php` | `success()`/`failure()`/`securityFailure()` | Stripe-specific, in wrong folder |
| `ReconciliationResult.php` | Public constructor, `$success` | **Inconsistent** - no factories, wrong naming |

### Issues Found

1. **Location inconsistency:** Stripe has `Service/Result/` but we're removing this pattern from component
2. **Naming inconsistency:** `ReconciliationResult` uses `$success`, others use `$successful`
3. **Pattern inconsistency:** `ReconciliationResult` uses public constructor, others use factories
4. **Not readonly:** `ReconciliationResult` is not `readonly class`

### Stripe Changes

#### Move DTOs to `Stripe/Adapter/Response/`

| From | To |
|------|-----|
| `Service/Result/CheckoutSessionResult.php` | `Adapter/Response/CheckoutSessionResponse.php` |
| `Service/Result/CheckoutReturnResult.php` | `Adapter/Response/CheckoutReturnResponse.php` |
| `Service/Result/ReconciliationResult.php` | `Adapter/Response/ReconciliationResponse.php` |
| `Service/Result/SecurityValidationResult.php` | `Adapter/Response/SecurityValidationResponse.php` |

#### Standardize ReconciliationResponse

```php
// BEFORE (inconsistent)
final class ReconciliationResult
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $paymentIntentId,
        public readonly bool $success,  // Wrong name!
        public readonly string $action,
        public readonly string $reason,
        public readonly bool $contractUpdated = false
    ) {}
}

// AFTER (consistent)
final readonly class ReconciliationResponse
{
    private function __construct(
        private bool $successful,
        private ?string $orderId,
        private ?string $paymentIntentId,
        private ?string $action,
        private ?string $reason,
        private bool $contractUpdated,
        private ?string $errorMessage,
        private ?string $errorCode,
    ) {}

    public static function success(
        string $orderId,
        string $paymentIntentId,
        string $action,
        string $reason,
        bool $contractUpdated = false
    ): self

    public static function failure(string $errorMessage, ?string $errorCode = null): self

    public function isSuccessful(): bool
}
```

### Can Stripe DTOs Extend Component?

| Stripe DTO | Can Extend Component? | Reason |
|------------|----------------------|--------|
| `SecurityValidationResponse` | **Implements interface** | Already implements `SecurityValidationResultInterface` |
| `CheckoutSessionResponse` | No | Stripe Checkout specific (session_id, checkout_url) |
| `CheckoutReturnResponse` | No | Stripe-specific (paymentIntentStatus, isRequiresCapture) |
| `ReconciliationResponse` | No | Stripe-specific reconciliation logic |

**Conclusion:** Stripe DTOs are provider-specific. They should follow the same **pattern** as component (factories, naming), but not extend component classes.

### No Interfaces for DTOs

**Decision:** Do not create interfaces for DTOs. Follow YAGNI principle.

- DTOs are simple data carriers, not behavior contracts
- `SecurityValidationResultInterface` is an exception (already exists, has behavior)
- Stripe DTOs follow the same **pattern** as component but don't need interfaces

---

## Updated Files to Modify (Stripe)

### Phase 10: Move Stripe DTOs

| From | To |
|------|-----|
| `src/Stripe/Service/Result/CheckoutSessionResult.php` | `src/Stripe/Adapter/Response/CheckoutSessionResponse.php` |
| `src/Stripe/Service/Result/CheckoutReturnResult.php` | `src/Stripe/Adapter/Response/CheckoutReturnResponse.php` |
| `src/Stripe/Service/Result/ReconciliationResult.php` | `src/Stripe/Adapter/Response/ReconciliationResponse.php` |
| `src/Stripe/Service/Result/SecurityValidationResult.php` | `src/Stripe/Adapter/Response/SecurityValidationResponse.php` |

### Phase 11: Standardize ReconciliationResponse

- Make `readonly class`
- Add `success()`/`failure()` factories
- Rename `$success` → `$successful`
- Add `errorMessage`, `errorCode` fields

### Phase 12: Update Stripe Services

| File | Changes |
|------|---------|
| `src/Stripe/Service/CheckoutSessionService.php` | Return CheckoutSessionResponse |
| `src/Stripe/Service/CheckoutReturnService.php` | Return CheckoutReturnResponse |
| `src/Stripe/Service/OxpaidReconciliationService.php` | Return ReconciliationResponse |
| All handlers using these DTOs | Update imports |

### Phase 13: Delete Stripe Service/Result/

| Action | Path |
|--------|------|
| DELETE | `src/Stripe/Service/Result/` folder |

---

## Updated Acceptance Criteria

### Payment-Component
- [ ] No `Service/Result/` folder exists
- [ ] All Response classes have `success()`/`failure()` factory methods
- [ ] All Response classes have `isSuccessful()`, `errorMessage`, `errorCode`
- [ ] `FraudCheckResponse` created in `Adapter/Response/`
- [ ] `VoidResponse` renamed to `CancellationResponse`

### Stripe
- [ ] No `Service/Result/` folder exists
- [ ] All DTOs moved to `Adapter/Response/`
- [ ] All DTOs renamed from `*Result` to `*Response`
- [ ] `ReconciliationResponse` uses factory pattern
- [ ] `ReconciliationResponse` uses `$successful` (not `$success`)
- [ ] All DTOs are `final readonly class`

### Both
- [ ] All unit tests pass
- [ ] PHPStan passes
- [ ] `./bin/pre-commit-check.sh` passes

---

## References

- Previous work: Sprint 25 (DTO Consolidation) - consolidated Stripe↔Component duplicates
- This sprint: Consolidates to Response-only pattern
- Related: `20260128/reports/02-dto-inventory.md`
