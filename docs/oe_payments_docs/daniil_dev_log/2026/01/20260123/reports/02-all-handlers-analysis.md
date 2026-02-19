# Analysis Report: All Stripe Handlers - Duplication & Thinness Assessment

**Date:** 2026-01-23
**Status:** ANALYSIS COMPLETE
**Total Handlers Analyzed:** 10

---

## Executive Summary

Analysis of all 10 Stripe event handlers reveals:
- **4 GOOD handlers** (thin, follow SOLID)
- **2 ACCEPTABLE handlers** (minor improvements possible)
- **4 FAT handlers** (need refactoring)

Key issues:
1. **RequestLog logging duplicated** across 3 handlers (~60 lines each)
2. **Capture handler has NO dedicated service** (389 lines)
3. **Order creation handler has too many responsibilities** (337 lines)
4. **PaymentIntent ID extraction duplicated** across handlers

---

## Handler Assessment Summary

| # | Handler | Lines | Rating | Has Service? | Issues |
|---|---------|-------|--------|--------------|--------|
| 1 | StripeRefundRequestHandler | 346 | **FAT** | Yes (RefundService) | Duplication, many responsibilities |
| 2 | StripeCancelAuthorizationRequestHandler | 211 | **MEDIUM** | No | Inline RequestLog, no service |
| 3 | OrderPaymentCompletedHandler | 104 | **GOOD** | Yes (OrderPaymentStateService) | None |
| 4 | StripePaymentReturnHandler | 93 | **GOOD** | No (just routes) | None |
| 5 | StripeCheckoutReturnHandler | 379 | **MEDIUM** | Yes (CheckoutReturnService) | Some inline logic remains |
| 6 | StripeCaptureRequestHandler | 389 | **FAT** | Partial (StripeCaptureService) | No dedicated capture service |
| 7 | StripePaymentStatusHandler | 185 | **ACCEPTABLE** | No (uses adapter) | Could extract routing |
| 8 | StripeContractCreationHandler | 120 | **GOOD** | Yes (MetadataService) | None - Template Method |
| 9 | StripeOrderCreationHandler | 337 | **FAT** | Yes (ShopOrderService) | Many responsibilities |
| 10 | StripeCheckoutSessionHandler | 167 | **GOOD** | Yes (CheckoutSessionService) | None |

---

## Detailed Analysis

### 1. StripeRefundRequestHandler (346 lines) - **FAT**

**Location:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`

**Current Responsibilities (8+):**
- Event validation
- Order loading
- PaymentIntent ID extraction
- Amount conversion
- Metadata building (DUPLICATED)
- RefundService delegation
- Order field updates
- Contract state updates
- RequestLog logging
- Context result setting

**Duplication Found:**
- `buildMetadata()` duplicated with RefundService (lines 187-200)
- Amount conversion logic

**Recommendation:** See Report #01 for detailed analysis.

---

### 2. StripeCancelAuthorizationRequestHandler (211 lines) - **MEDIUM**

**Location:** `src/Stripe/EventSystem/Handler/StripeCancelAuthorizationRequestHandler.php`

**Current Responsibilities (5):**
- Event validation
- PaymentIntent ID validation
- Stripe adapter call
- RequestLog logging (inline)
- Context result setting

**Issues:**
1. **No dedicated CancelAuthorizationService** - calls adapter directly
2. **RequestLog logging inline** (lines 136-155, 174-197) - 40+ lines of logging code

**Code Smell - Inline RequestLog:**
```php
private function logCancelRequest(string $paymentIntentId, ...): void
{
    try {
        $requestLog = oxNew(RequestLog::class);
        $requestLog->logRequest(...);
    } catch (\Throwable $e) {
        $this->logger->warning('Failed to log cancel request', ...);
    }
}
```

This exact pattern appears in Capture and Refund handlers.

**Recommendation:**
- Extract `CancelAuthorizationService` for adapter interaction
- Extract shared `RequestLogService` for logging

---

### 3. OrderPaymentCompletedHandler (104 lines) - **GOOD**

**Location:** `src/Stripe/EventSystem/Handler/OrderPaymentCompletedHandler.php`

**Current Responsibilities (3):**
- Event validation
- OrderPaymentStateService delegation
- Logging

**Assessment:** This handler is a model of SOLID compliance:
- Single responsibility (mark order as paid)
- Delegates to OrderPaymentStateService
- Clean, focused logic

**No changes needed.**

---

### 4. StripePaymentReturnHandler (93 lines) - **GOOD**

**Location:** `src/Stripe/EventSystem/Handler/StripePaymentReturnHandler.php`

**Current Responsibilities (3):**
- Event validation
- Immediate failure handling
- Event dispatching (StripePaymentExecuteEvent)

**Assessment:** Thin handler that just routes based on redirect_status.

**No changes needed.**

---

### 5. StripeCheckoutReturnHandler (379 lines) - **MEDIUM**

**Location:** `src/Stripe/EventSystem/Handler/StripeCheckoutReturnHandler.php`

**Current Responsibilities (7):**
- Event validation
- CheckoutReturnService delegation (validation)
- Contract loading
- Security validation
- Session state restoration
- Payment event dispatching
- Context management

**Positive:** Uses `CheckoutReturnService` for core validation logic.

**Issues:**
1. **buildSecurityContext()** (lines 225-232) - could be in SecurityService
2. **restoreDeliveryAddressHash()** (lines 234-249) - could be in SessionRestorationService
3. **handleRequiresCaptureStatus()** has similar logic to **dispatchPaymentEvent()** - both create PaymentAuthorizedEvent

**Code Duplication within Handler:**
```php
// In dispatchPaymentEvent() - lines 259-267
$event = new PaymentAuthorizedEvent(
    context: $context,
    authorizationId: $paymentIntentId,
    ...
);

// In handleRequiresCaptureStatus() - lines 345-351
$event = new PaymentAuthorizedEvent(
    context: $context,
    authorizationId: $paymentIntentId,
    ...
);
```

**Recommendation:**
- Extract common PaymentAuthorizedEvent creation to private method
- Consider extracting session restoration logic

---

### 6. StripeCaptureRequestHandler (389 lines) - **FAT** (HIGH PRIORITY)

**Location:** `src/Stripe/EventSystem/Handler/StripeCaptureRequestHandler.php`

**Current Responsibilities (9+):**
- Event validation
- Contract loading
- State validation
- PaymentIntent ID extraction
- Capture request building
- Stripe adapter call
- Contract state transition
- RequestLog logging (inline)
- Context result setting

**Critical Issue: No Dedicated CaptureService!**

While `StripeCaptureService` exists, it only extends `AbstractPaymentCaptureService` for state validation (65 lines). The actual capture logic is entirely in the handler.

**Internal Duplication:**
`executeCapture()` and `executeDirectCapture()` have nearly identical code:

```php
// executeCapture() - lines 181-241
$request = new CapturePaymentRequest(...);
$response = $this->stripeAdapter->capturePayment($request);
$this->logger->info('Stripe capture successful', ...);
$this->logCaptureRequest($response, $event);
$context->set('captureSuccess', true);
// ... more identical code

// executeDirectCapture() - lines 252-307
$request = new CapturePaymentRequest(...);
$response = $this->stripeAdapter->capturePayment($request);
$this->logger->info('Stripe direct capture successful', ...);
$this->logCaptureRequest($response, $event);
$context->set('captureSuccess', true);
// ... more identical code
```

**~50 lines of duplicated capture logic within the same handler!**

**RequestLog Pattern (also duplicated):**
- `logCaptureRequest()` lines 315-335
- `logExceptionToRequestLog()` lines 354-375

**Recommendation:**
- Create `CaptureService` with `processCapture()` method
- Move all capture logic to service
- Use shared `RequestLogService`

---

### 7. StripePaymentStatusHandler (185 lines) - **ACCEPTABLE**

**Location:** `src/Stripe/EventSystem/Handler/StripePaymentStatusHandler.php`

**Current Responsibilities (5):**
- Event validation
- Contract loading
- Adapter call (payment details)
- Status routing (match expression)
- Event dispatching

**Assessment:** Acceptable size. Uses adapter factory directly which is fine for simple retrieval.

**Minor Improvement:** Status routing logic could be extracted to `PaymentStatusRouterService`, but not critical.

---

### 8. StripeContractCreationHandler (120 lines) - **GOOD**

**Location:** `src/Stripe/EventSystem/Handler/StripeContractCreationHandler.php`

**Assessment:** Excellent example of Template Method pattern:
- Extends `ContractCreationHandler` base class
- Implements only Stripe-specific hooks
- Delegates metadata to `ContractMetadataService`

**No changes needed.**

---

### 9. StripeOrderCreationHandler (337 lines) - **FAT** (MEDIUM PRIORITY)

**Location:** `src/Stripe/EventSystem/Handler/StripeOrderCreationHandler.php`

**Current Responsibilities (8+):**
- Event validation
- Contract state validation
- Early order detection
- Basket validation
- Order creation
- Existing order handling
- Transaction ID updates
- OXPAID updates
- Event dispatching

**Issues:**

1. **handleExistingOrder()** (lines 207-264) duplicates logic with **handlePostOrderCreation()** (lines 266-285):
```php
// In handleExistingOrder() - lines 249-263
$contract->commitToOrder($orderId);
$this->contractRepository->save($contract);
if (!$requiresCapture) {
    $this->updateOrderPaidTimestamp($orderId, ...);
}
$committedEvent = new ContractCommittedEvent(...);
$this->eventDispatcher->dispatch($committedEvent);

// In handlePostOrderCreation() - lines 271-284
$contract->commitToOrder($orderId);
$this->contractRepository->save($contract);
if (!$requiresCapture) {
    $this->updateOrderPaidTimestamp($orderId, ...);
}
$committedEvent = new ContractCommittedEvent(...);
$this->eventDispatcher->dispatch($committedEvent);
```

**~20 lines of identical code!**

2. **Transaction ID update** (lines 237-247) should be in a service

**Recommendation:**
- Extract `commitAndDispatch()` private method
- Consider `OrderTransactionUpdateService` for OXTRANSID updates
- Extract basket validation to service if reused elsewhere

---

### 10. StripeCheckoutSessionHandler (167 lines) - **GOOD**

**Location:** `src/Stripe/EventSystem/Handler/StripeCheckoutSessionHandler.php`

**Assessment:** Good delegation pattern:
- Uses `CheckoutSessionService` for session creation
- Uses `TokenService` for security tokens
- Handler just orchestrates

**No changes needed.**

---

## Cross-Cutting Duplication Patterns

### Pattern 1: RequestLog Logging (~40-60 lines per handler)

Found in 3 handlers:
- `StripeCaptureRequestHandler` (lines 315-375)
- `StripeRefundRequestHandler` (lines 259-332)
- `StripeCancelAuthorizationRequestHandler` (lines 136-197)

**Total duplicated lines: ~150-180 lines**

**Solution:** Create `RequestLogService`:
```php
interface RequestLogServiceInterface
{
    public function logRequest(string $action, array $request, array $response, string $referenceId): void;
    public function logException(string $action, \Throwable $e, string $referenceId): void;
}
```

### Pattern 2: PaymentIntent ID Extraction

Similar logic in multiple handlers:
- `StripeRefundRequestHandler::getPaymentIntentId()` (lines 115-133)
- `StripeCaptureRequestHandler::getPaymentIntentId()` (lines 145-171)

**Solution:** Extract to `PaymentIntentIdResolver` service or share via trait.

### Pattern 3: Contract State Updates

Similar pattern in:
- `StripeRefundRequestHandler::updateContractState()`
- `StripeCaptureRequestHandler::executeCapture()` (contract transition)
- `StripeOrderCreationHandler::handlePostOrderCreation()`

---

## Prioritized Refactoring Recommendations

### HIGH Priority

| Task | Impact | Effort | Files Affected |
|------|--------|--------|----------------|
| Create CaptureService | Removes 200+ lines from handler | Medium | 1 handler, 1 new service |
| Create RequestLogService | Removes 150+ lines total | Medium | 3 handlers, 1 new service |

### MEDIUM Priority

| Task | Impact | Effort | Files Affected |
|------|--------|--------|----------------|
| Refactor RefundRequestHandler | Removes 100+ lines | Medium | 1 handler, 1 service |
| Extract common code in OrderCreationHandler | Removes 20+ lines | Low | 1 handler |
| Create CancelAuthorizationService | Removes 50+ lines | Low | 1 handler, 1 new service |

### LOW Priority

| Task | Impact | Effort | Files Affected |
|------|--------|--------|----------------|
| Extract PaymentIntentIdResolver | DRY improvement | Low | 2-3 handlers |
| Refactor CheckoutReturnHandler internal duplication | Minor | Low | 1 handler |

---

## Metrics Summary

| Metric | Current | After Refactoring | Improvement |
|--------|---------|-------------------|-------------|
| Total handler lines | 2,330 | ~1,600 | -31% |
| Average lines per handler | 233 | ~160 | -31% |
| Handlers with >250 lines | 4 | 0 | -100% |
| Duplicated code blocks | 5+ | 0 | -100% |
| Handlers rated GOOD | 4 | 8+ | +100% |

---

## Recommended New Services

1. **CaptureService** (HIGH)
   - `processCapture(contract, amount, initiator): CaptureResult`
   - `processDirectCapture(paymentIntentId, amount, orderId): CaptureResult`

2. **RequestLogService** (HIGH)
   - `logRequest(action, request, response, referenceId): void`
   - `logException(action, exception, referenceId): void`

3. **CancelAuthorizationService** (MEDIUM)
   - `cancelPaymentIntent(paymentIntentId, reason): CancellationResult`

4. **PaymentIntentIdResolver** (LOW)
   - `resolveFromContract(contract): ?string`
   - `resolveFromOrder(order): ?string`

---

## Conclusion

The Stripe handler layer has **significant room for improvement**:

1. **4 handlers need refactoring** (Refund, Capture, Cancel, OrderCreation)
2. **RequestLog logging is heavily duplicated** (~150 lines across 3 handlers)
3. **StripeCaptureRequestHandler is the worst offender** (389 lines, internal duplication, no service)

The pattern established by `OrderPaymentCompletedHandler` and `StripeContractCreationHandler` should be followed: **thin handlers that delegate to focused services**.

---

## Related Documentation

- Report #01: `01-refund-handler-service-analysis.md`
- Architecture: `docs/payment-component/dev_history/architecture/handler-abstraction-pattern.md`

---

**Report by:** Claude Code Analysis
**Date:** 2026-01-23
