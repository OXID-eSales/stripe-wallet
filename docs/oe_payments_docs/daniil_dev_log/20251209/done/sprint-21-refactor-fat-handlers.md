# Sprint 21: Refactor ALL Fat Handlers (Extract Services)

**Date:** 2025-12-09
**Priority:** MEDIUM-HIGH
**Status:** PENDING
**Branch:** TBD (b-7.4.x-STRP-XX)
**Est. Effort:** 12-16 hours
**Depends On:** Sprint 18, 19, 20 (service extraction patterns established)

---

## Development Principles Checklist

| Principle | How Applied |
|-----------|-------------|
| **TDD-FIRST** | Write service tests first for each handler refactoring |
| **SOLID-SRP** | Handlers: event routing only. Services: business logic |
| **SOLID-OCP** | Services can be extended for new behaviors |
| **SOLID-DIP** | Handlers depend on service interfaces |
| **DI** | All dependencies injected via constructor |
| **Clean Code** | Methods ≤ 25 lines, no else expressions |
| **Containerization** | All tests via `docker compose exec` |

---

## Core Principle: Handler Responsibility

**Handlers MUST only:**
1. Receive events/requests
2. Extract parameters from event
3. Delegate to service(s)
4. Log results
5. Return response

**Handlers MUST NOT:**
- Contain business logic
- Make direct API calls
- Transform data beyond parameter extraction
- Have more than 75 lines of code

---

## Handlers Requiring Refactoring

### Priority Overview

| Priority | Handler | Lines | Services to Extract |
|----------|---------|-------|---------------------|
| HIGH | `StripeRefundRequestHandler` | 358 | RefundService |
| HIGH | `StripeCheckoutReturnHandler` | 312 | CheckoutReturnService, SessionRestorationService |
| MEDIUM | `StripeCheckoutSessionHandler` | 160 | StripeCheckoutSessionService (enhance) |
| MEDIUM | `StripeContractCreationHandler` | 164 | ContractMetadataService, DeliveryAddressService |
| MEDIUM | `StripeOrderCreationHandler` | 177 | StripeOrderCreationService (enhance) |
| MEDIUM | `StripePaymentStatusHandler` | 148 | PaymentStatusService, ThreeDSecureDetectionService |

---

## Phase 1: HIGH Priority - StripeRefundRequestHandler

**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`
**Current Lines:** 358
**Target Lines:** ≤75

### Current Responsibilities (Should Be Services)

1. Load order from database
2. Extract PaymentIntent ID
3. Get charge from Stripe API
4. Build refund parameters
5. Execute Stripe refund
6. Update order payment state
7. Log transaction

### Services to Create

#### RefundServiceInterface

```php
interface RefundServiceInterface
{
    public function processFullRefund(string $orderId, string $paymentIntentId): RefundResult;
    public function processPartialRefund(string $orderId, string $paymentIntentId, int $amountCents): RefundResult;
}
```

#### RefundResult DTO

```php
final class RefundResult
{
    public static function success(string $refundId, int $amount): self;
    public static function failure(string $errorMessage): self;
    public function isSuccessful(): bool;
    public function getRefundId(): ?string;
    public function getRefundedAmount(): ?int;
    public function getErrorMessage(): ?string;
}
```

### Refactored Handler Structure

```php
final class StripeRefundRequestHandler implements RefundRequestHandlerInterface
{
    public function __construct(
        private readonly RefundServiceInterface $refundService,
        private readonly LoggerInterface $logger
    ) {}

    public function handle(RefundRequestEvent $event): void
    {
        $result = $this->processRefundRequest($event);
        $this->logResult($event, $result);
    }

    private function processRefundRequest(RefundRequestEvent $event): RefundResult
    {
        if ($event->isPartialRefund()) {
            return $this->refundService->processPartialRefund(
                $event->getOrderId(),
                $event->getPaymentIntentId(),
                $event->getAmountCents()
            );
        }

        return $this->refundService->processFullRefund(
            $event->getOrderId(),
            $event->getPaymentIntentId()
        );
    }
}
```

### Files to Create

| File | Purpose |
|------|---------|
| `src/Stripe/DTO/RefundResult.php` | Refund operation result |
| `src/Stripe/Service/RefundServiceInterface.php` | Service abstraction |
| `src/Stripe/Service/RefundService.php` | Refund business logic |
| `tests/Unit/Stripe/Service/RefundServiceTest.php` | TDD tests |

---

## Phase 2: HIGH Priority - StripeCheckoutReturnHandler

**File:** `src/Stripe/Handler/StripeCheckoutReturnHandler.php`
**Current Lines:** 312
**Target Lines:** ≤75

### Current Responsibilities (Should Be Services)

1. Extract session ID from request
2. Retrieve Stripe checkout session
3. Validate payment status
4. Handle various return scenarios (success, failure, cancel)
5. Restore user session if needed
6. Update contract state
7. Create/update order
8. Redirect user

### Services to Create

#### CheckoutReturnServiceInterface

```php
interface CheckoutReturnServiceInterface
{
    public function processReturn(CheckoutReturnRequest $request): CheckoutReturnResult;
    public function handleSuccess(string $sessionId): CheckoutReturnResult;
    public function handleFailure(string $sessionId, string $reason): CheckoutReturnResult;
    public function handleCancel(string $sessionId): CheckoutReturnResult;
}
```

#### SessionRestorationServiceInterface

```php
interface SessionRestorationServiceInterface
{
    public function restoreSession(string $sessionId): bool;
    public function restoreBasket(string $contractId): bool;
}
```

### Refactored Handler Structure

```php
final class StripeCheckoutReturnHandler implements CheckoutReturnHandlerInterface
{
    public function __construct(
        private readonly CheckoutReturnServiceInterface $returnService,
        private readonly SessionRestorationServiceInterface $sessionService,
        private readonly LoggerInterface $logger
    ) {}

    public function handle(Request $request): Response
    {
        $returnRequest = CheckoutReturnRequest::fromHttpRequest($request);
        $result = $this->returnService->processReturn($returnRequest);

        $this->logResult($returnRequest, $result);

        return $this->buildResponse($result);
    }
}
```

### Files to Create

| File | Purpose |
|------|---------|
| `src/Stripe/DTO/CheckoutReturnRequest.php` | Request DTO |
| `src/Stripe/DTO/CheckoutReturnResult.php` | Result DTO |
| `src/Stripe/Service/CheckoutReturnServiceInterface.php` | Service abstraction |
| `src/Stripe/Service/CheckoutReturnService.php` | Return handling logic |
| `src/Stripe/Service/SessionRestorationServiceInterface.php` | Session abstraction |
| `src/Stripe/Service/SessionRestorationService.php` | Session restoration logic |
| `tests/Unit/Stripe/Service/CheckoutReturnServiceTest.php` | TDD tests |
| `tests/Unit/Stripe/Service/SessionRestorationServiceTest.php` | TDD tests |

---

## Phase 3: MEDIUM Priority - StripeCheckoutSessionHandler

**File:** `src/Stripe/Handler/StripeCheckoutSessionHandler.php`
**Current Lines:** 160
**Target Lines:** ≤75

### Current Responsibilities (Should Be Services)

1. Build line items from basket
2. Configure shipping options
3. Build checkout session parameters
4. Create Stripe checkout session
5. Store session data

### Services to Create/Enhance

#### LineItemBuilderInterface

```php
interface LineItemBuilderInterface
{
    public function buildFromBasket(BasketInterface $basket): array;
    public function buildShippingOptions(BasketInterface $basket): array;
}
```

#### CheckoutSessionServiceInterface (Enhance Existing)

```php
interface CheckoutSessionServiceInterface
{
    public function createSession(CheckoutSessionRequest $request): CheckoutSessionResult;
    public function buildSessionParams(BasketInterface $basket, PaymentContractInterface $contract): array;
}
```

### Files to Create/Modify

| File | Purpose |
|------|---------|
| `src/Stripe/Service/LineItemBuilderInterface.php` | Line item abstraction |
| `src/Stripe/Service/LineItemBuilder.php` | Line item building logic |
| `tests/Unit/Stripe/Service/LineItemBuilderTest.php` | TDD tests |

---

## Phase 4: MEDIUM Priority - StripeContractCreationHandler

**File:** `src/Stripe/Handler/StripeContractCreationHandler.php`
**Current Lines:** 164
**Target Lines:** ≤75

### Current Responsibilities (Should Be Services)

1. Extract basket data
2. Build contract metadata
3. Extract delivery address
4. Create payment contract
5. Store contract

### Services to Create

#### ContractMetadataServiceInterface

```php
interface ContractMetadataServiceInterface
{
    public function buildMetadata(BasketInterface $basket, UserInterface $user): array;
    public function extractShippingInfo(BasketInterface $basket): ShippingInfo;
}
```

#### DeliveryAddressServiceInterface

```php
interface DeliveryAddressServiceInterface
{
    public function extractDeliveryAddress(BasketInterface $basket): ?AddressInterface;
    public function formatForProvider(AddressInterface $address): array;
}
```

### Files to Create

| File | Purpose |
|------|---------|
| `src/Stripe/Service/ContractMetadataServiceInterface.php` | Metadata abstraction |
| `src/Stripe/Service/ContractMetadataService.php` | Metadata building logic |
| `src/Stripe/Service/DeliveryAddressServiceInterface.php` | Address abstraction |
| `src/Stripe/Service/DeliveryAddressService.php` | Address extraction logic |
| `tests/Unit/Stripe/Service/ContractMetadataServiceTest.php` | TDD tests |
| `tests/Unit/Stripe/Service/DeliveryAddressServiceTest.php` | TDD tests |

---

## Phase 5: MEDIUM Priority - StripeOrderCreationHandler

**File:** `src/Stripe/Handler/StripeOrderCreationHandler.php`
**Current Lines:** 177
**Target Lines:** ≤75

### Current Responsibilities (Should Be Services)

1. Load contract
2. Validate contract state
3. Build order from contract
4. Store order
5. Update contract with order ID
6. Dispatch order created event

### Services to Enhance

#### OrderCreationServiceInterface (Already exists, enhance)

```php
interface OrderCreationServiceInterface
{
    public function createOrderFromContract(PaymentContractInterface $contract): OrderCreationResult;
    public function validateContractForOrderCreation(PaymentContractInterface $contract): ValidationResult;
}
```

### Files to Modify

| File | Change |
|------|--------|
| `src/Stripe/Service/StripeOrderCreationService.php` | Move business logic from handler |
| `tests/Unit/Stripe/Service/StripeOrderCreationServiceTest.php` | Add tests for moved logic |

---

## Phase 6: MEDIUM Priority - StripePaymentStatusHandler

**File:** `src/Stripe/Handler/StripePaymentStatusHandler.php`
**Current Lines:** 148
**Target Lines:** ≤75

### Current Responsibilities (Should Be Services)

1. Load contract
2. Query Stripe for payment status
3. Detect 3D Secure status
4. Update contract state
5. Build response

### Services to Create

#### PaymentStatusServiceInterface

```php
interface PaymentStatusServiceInterface
{
    public function getPaymentStatus(string $contractId): PaymentStatusResult;
    public function getStatusByPaymentIntent(string $paymentIntentId): PaymentStatusResult;
}
```

#### ThreeDSecureDetectionServiceInterface

```php
interface ThreeDSecureDetectionServiceInterface
{
    public function detect3DSStatus(object $paymentIntent): ThreeDSecureStatus;
    public function requires3DS(object $paymentIntent): bool;
}
```

### Files to Create

| File | Purpose |
|------|---------|
| `src/Stripe/DTO/PaymentStatusResult.php` | Status result DTO |
| `src/Stripe/DTO/ThreeDSecureStatus.php` | 3DS status DTO |
| `src/Stripe/Service/PaymentStatusServiceInterface.php` | Status abstraction |
| `src/Stripe/Service/PaymentStatusService.php` | Status checking logic |
| `src/Stripe/Service/ThreeDSecureDetectionServiceInterface.php` | 3DS abstraction |
| `src/Stripe/Service/ThreeDSecureDetectionService.php` | 3DS detection logic |
| `tests/Unit/Stripe/Service/PaymentStatusServiceTest.php` | TDD tests |
| `tests/Unit/Stripe/Service/ThreeDSecureDetectionServiceTest.php` | TDD tests |

---

## Implementation Order

### Recommended Execution Order

1. **Phase 1: StripeRefundRequestHandler** (4h)
   - Most isolated, clear business logic
   - Good template for other refactorings

2. **Phase 2: StripeCheckoutReturnHandler** (4h)
   - Complex but critical flow
   - Establishes session restoration pattern

3. **Phase 3: StripeCheckoutSessionHandler** (2h)
   - Builds on Phase 2 patterns
   - Line item builder reusable

4. **Phase 4: StripeContractCreationHandler** (2h)
   - Metadata service reusable
   - Address service shared with checkout

5. **Phase 5: StripeOrderCreationHandler** (2h)
   - Enhances existing service
   - Validates contract patterns

6. **Phase 6: StripePaymentStatusHandler** (2h)
   - 3DS detection isolated
   - Status service completes pattern

---

## Services Summary

### New Services to Create

| Service | Responsibility |
|---------|---------------|
| `RefundService` | Refund processing logic |
| `CheckoutReturnService` | Return flow handling |
| `SessionRestorationService` | Session/basket restoration |
| `LineItemBuilder` | Basket to line items conversion |
| `ContractMetadataService` | Contract metadata building |
| `DeliveryAddressService` | Address extraction/formatting |
| `PaymentStatusService` | Payment status checking |
| `ThreeDSecureDetectionService` | 3DS status detection |

### DTOs to Create

| DTO | Purpose |
|-----|---------|
| `RefundResult` | Refund operation result |
| `CheckoutReturnRequest` | Return request data |
| `CheckoutReturnResult` | Return operation result |
| `PaymentStatusResult` | Status check result |
| `ThreeDSecureStatus` | 3DS detection result |
| `ShippingInfo` | Shipping information |

---

## Verification Checklist

### Per-Handler Checklist

- [ ] Service interface created
- [ ] Service implementation created
- [ ] TDD tests written (RED → GREEN)
- [ ] Handler refactored to ≤75 lines
- [ ] Handler only delegates to services
- [ ] All unit tests pass
- [ ] PHPStan level 6 passes
- [ ] Manual flow test passes

### Final Checklist

- [ ] All 6 handlers refactored
- [ ] All new services registered in services.yaml
- [ ] Pre-commit check passes
- [ ] E2E checkout flow passes
- [ ] E2E refund flow passes

---

## Metrics Before/After

| Handler | Before LOC | After LOC | Target |
|---------|------------|-----------|--------|
| StripeRefundRequestHandler | 358 | ≤75 | ≤75 |
| StripeCheckoutReturnHandler | 312 | ≤75 | ≤75 |
| StripeCheckoutSessionHandler | 160 | ≤75 | ≤75 |
| StripeContractCreationHandler | 164 | ≤75 | ≤75 |
| StripeOrderCreationHandler | 177 | ≤75 | ≤75 |
| StripePaymentStatusHandler | 148 | ≤75 | ≤75 |
| **Total Handler LOC** | **1319** | **≤450** | **≤450** |

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Breaking checkout flow | Critical | E2E tests before/after each phase |
| Breaking refund flow | High | Manual refund test per phase |
| Session restoration bugs | High | Unit tests + manual test |
| Payment status errors | Medium | Unit tests for all status codes |

---

## Success Criteria

1. All handlers ≤75 lines
2. Each method ≤25 lines
3. All business logic in services
4. Handlers only orchestrate
5. All unit tests pass
6. All E2E tests pass
7. PHPStan level 6 passes
8. No else expressions in new code

---

## Related Issues

- CODE_REVIEW.md Section 1.6 (HIGH: Fat Handler Anti-Pattern)
- CODE_REVIEW.md Section 4.6 (HIGH: Fat Handler Pattern - Stripe Layer)
- Sprint 18: ContractFulfillmentService (pattern established)
- Sprint 16: OrderPaymentStateService (pattern established)

---

**Last Updated:** 2025-12-09
