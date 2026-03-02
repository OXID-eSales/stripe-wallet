# Analysis Report: StripeRefundRequestHandler vs RefundService

**Date:** 2026-01-23
**Status:** ANALYSIS COMPLETE
**Severity:** MEDIUM (Code smell, not critical bug)

---

## Executive Summary

The `StripeRefundRequestHandler` (346 lines) and `RefundService` (188 lines) show **partial duplication** and the handler contains **too many responsibilities** that should be delegated to services. While a Sprint 21 refactoring already extracted core refund logic to `RefundService`, the handler still contains ~200 lines of code that could be further extracted.

---

## Files Analyzed

| File | Location | Lines |
|------|----------|-------|
| StripeRefundRequestHandler | `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | 346 |
| RefundService | `src/Stripe/Service/RefundService.php` | 188 |
| RefundServiceInterface | `src/Stripe/Service/RefundServiceInterface.php` | 79 |

---

## Duplication Analysis

### 1. `buildMetadata()` Method - DUPLICATED

**Handler (lines 187-200):**
```php
private function buildMetadata(StripeRefundRequestEvent $event, string $orderId): array
{
    $metadata = [
        'order_id' => $orderId,
        'initiator' => $event->getInitiator(),
    ];

    if ($description = $event->getDescription()) {
        $metadata['description'] = $description;
    }

    return $metadata;
}
```

**Service (lines 132-144):**
```php
private function buildMetadata(string $orderId, string $initiator, ?string $description): array
{
    $metadata = [
        'order_id' => $orderId,
        'initiator' => $initiator,
    ];

    if ($description !== null) {
        $metadata['description'] = $description;
    }

    return $metadata;
}
```

**Issue:** Both have identical logic but different signatures. Handler extracts from event, service receives primitives.

**Recommendation:** Remove from handler; let service handle all metadata construction.

---

### 2. Amount Conversion - PARTIAL DUPLICATION

**Handler (lines 176-182):**
```php
private function convertAmountToCents(?float $amount): ?int
{
    if ($amount === null) {
        return null;
    }
    return (int) round($amount * 100);
}
```

**Service:** Expects amount in cents (no conversion needed)

**Issue:** Handler converts before calling service, but this could be encapsulated in service.

**Recommendation:** Service should accept float amounts and handle conversion internally (or create a Money value object).

---

## Handler Responsibilities (Current State)

The handler currently performs **8 distinct responsibilities**:

| # | Responsibility | Lines | Should Be in Handler? |
|---|---------------|-------|----------------------|
| 1 | Event validation | 56-59 | Yes |
| 2 | Order loading | 102-113 | No - OrderService |
| 3 | PaymentIntent ID extraction | 115-133 | Borderline |
| 4 | Amount conversion | 176-182 | No - Service |
| 5 | Metadata building | 187-200 | No - Service |
| 6 | RefundService delegation | 135-174 | Yes |
| 7 | Order field updates | 221-241 | No - OrderUpdateService |
| 8 | Contract state updates | 243-257 | No - ContractService |
| 9 | RequestLog logging | 259-278, 311-332 | No - LoggingService |
| 10 | Context result setting | 280-293 | Yes |
| 11 | Exception handling | 295-307 | Yes |

**Verdict:** Handler has ~5 responsibilities it shouldn't have (violates SRP).

---

## Service Responsibilities (Current State)

The service correctly handles:

| # | Responsibility | Lines | Correct? |
|---|---------------|-------|----------|
| 1 | Full refund orchestration | 45-65 | Yes |
| 2 | Partial refund orchestration | 67-88 | Yes |
| 3 | Charge-based refund | 90-105 | Yes |
| 4 | Charge ID from PaymentIntent | 107-127 | Yes |
| 5 | Metadata building | 132-144 | Yes |
| 6 | Reason validation | 146-153 | Yes |
| 7 | Stripe response handling | 155-176 | Yes |
| 8 | Error handling | 178-187 | Yes |

**Verdict:** Service follows SRP well but has duplicated metadata code.

---

## Proposed Thin Handler Pattern

Based on the project's `handler-abstraction-pattern.md`, the handler should be thin:

```php
class StripeRefundRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly RefundServiceInterface $refundService,
        private readonly OrderRefundServiceInterface $orderRefundService,
        private readonly RefundLoggerInterface $refundLogger,
    ) {}

    public function handle(object $event): void
    {
        if (!$event instanceof StripeRefundRequestEvent) {
            return;
        }

        $context = $event->getContext();

        try {
            // 1. Execute refund via service
            $result = $this->refundService->processRefund(
                RefundRequest::fromEvent($event)
            );

            // 2. Update order if successful
            if ($result->isSuccessful()) {
                $this->orderRefundService->updateAfterRefund(
                    $event->getOrderId(),
                    $result,
                    $event->isFullRefund()
                );
            }

            // 3. Log and set results
            $this->refundLogger->log($result, $event);
            $this->setContextResults($context, $result);

        } catch (\Throwable $e) {
            $this->handleException($e, $context);
        }
    }
}
```

**Target:** ~80-100 lines (down from 346)

---

## Recommended Refactoring

### Phase 1: Remove Duplications

1. **Remove `buildMetadata()` from handler** - Service already has this
2. **Remove `convertAmountToCents()` from handler** - Move to service

### Phase 2: Extract New Services

| New Service | Responsibilities | Lines Saved |
|-------------|------------------|-------------|
| `OrderRefundService` | Order loading, field updates after refund | ~50 lines |
| `RefundLogger` | RequestLog operations, exception logging | ~50 lines |

### Phase 3: Simplify Handler

1. **Create `RefundRequest` DTO** - Encapsulate event data extraction
2. **Handler only orchestrates** - Calls services, sets context

---

## Impact Assessment

### Benefits

- **SRP Compliance:** Each class has one reason to change
- **Testability:** Services can be unit tested independently
- **Reusability:** `OrderRefundService` can be used by other handlers
- **Maintainability:** Smaller, focused classes are easier to understand

### Risks

- **Additional Classes:** 2-3 new service classes
- **DI Configuration:** More services to wire in `services.yaml`
- **Refactoring Effort:** Moderate (estimated 2-4 hours)

---

## Code Quality Metrics

| Metric | Current | Target | Improvement |
|--------|---------|--------|-------------|
| Handler Lines | 346 | ~80 | -77% |
| Handler Responsibilities | 8+ | 3-4 | -60% |
| Duplicated Code | 2 methods | 0 | -100% |
| Cyclomatic Complexity | High | Low | Significant |

---

## Conclusion

**Yes, there is duplication** (`buildMetadata()`), and **yes, the handler can be thinner**.

The current handler violates SRP by handling:
- Order operations
- Contract state updates
- Logging operations
- Data transformation

**Recommended action:** Implement Phase 1 (remove duplications) as a quick win, then consider Phase 2-3 for fuller refactoring.

---

## Related Documentation

- `docs/payment-component/dev_history/architecture/handler-abstraction-pattern.md`
- `docs/payment-component/architecture/07-capture-refund-operations.md`

---

**Report by:** Claude Code Analysis
**Date:** 2026-01-23
