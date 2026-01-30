# Response/Result Duplication Analysis

**Generated:** 2026-01-30

---

## Summary

The payment-component has two parallel DTO hierarchies for the same operations:

1. **Response classes** (Adapter layer) - Simple DTOs returned by adapters
2. **Result classes** (Service layer) - DTOs with success/failure handling

These duplicate ~60% of fields and require mapping code between layers.

---

## Current Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  APPLICATION LAYER                                          │
│  Uses: RefundResult, CaptureResult                          │
└────────────────────────────┬────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────┐
│  SERVICE LAYER (AbstractPaymentRefundService)               │
│  Receives: RefundResponse                                   │
│  Returns: RefundResult                                      │
│  Action: MAPS Response fields to Result (duplication!)      │
└────────────────────────────┬────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────┐
│  ADAPTER LAYER (PaymentAdapterInterface)                    │
│  Returns: RefundResponse, CaptureResponse                   │
└─────────────────────────────────────────────────────────────┘
```

---

## Detailed Field Comparison

### CaptureResponse vs CaptureResult

| Field | CaptureResponse | CaptureResult | Notes |
|-------|-----------------|---------------|-------|
| `providerPaymentId` | ✓ string | - | Response only |
| `captureId` | ✓ string | ✓ ?string | Both |
| `amountCaptured` | ✓ float | ✓ ?float | Both |
| `currency` | ✓ string | ✓ ?string | Both |
| `status` | ✓ string | - | Response only |
| `capturedAt` | ✓ DateTimeInterface | ✓ ?DateTimeImmutable | Both |
| `providerData` | ✓ array | ✓ array | Both |
| `metadata` | ✓ array | - | Response only |
| `successful` | - | ✓ bool | Result only |
| `errorMessage` | - | ✓ ?string | Result only |
| `errorCode` | - | ✓ ?string | Result only |

**Shared fields: 5 of 8 (62.5%)**

### RefundResponse vs RefundResult

| Field | RefundResponse | RefundResult | Notes |
|-------|----------------|--------------|-------|
| `providerPaymentId` | ✓ string | - | Response only |
| `refundId` | ✓ string | ✓ ?string | Both |
| `amountRefunded` | ✓ float | ✓ ?float | Both |
| `currency` | ✓ string | ✓ ?string | Both |
| `status` | ✓ string | ✓ ?string | Both |
| `refundedAt` | ✓ DateTimeInterface | ✓ ?DateTimeImmutable | Both |
| `reason` | ✓ ?string | - | Response only |
| `providerData` | ✓ array | ✓ array | Both |
| `metadata` | ✓ array | - | Response only |
| `successful` | - | ✓ bool | Result only |
| `totalRefunded` | - | ✓ ?float | Result only (business logic) |
| `availableForRefund` | - | ✓ ?float | Result only (business logic) |
| `errorMessage` | - | ✓ ?string | Result only |
| `errorCode` | - | ✓ ?string | Result only |

**Shared fields: 6 of 11 (54.5%)**

---

## Evidence of Parallel Flows

### Flow 1: Contract-Based (Response → Result mapping)

**File:** `payment-component/src/Service/AbstractPaymentRefundService.php:80-90`

```php
$response = $this->executeRefund($contract, $refundAmounts['refundAmount'], $reason);

return RefundResult::create(
    refundId: $response->refundId,           // Mapping
    amountRefunded: $response->amountRefunded, // Mapping
    currency: $response->currency,            // Mapping
    totalRefunded: $newTotalRefunded,
    availableForRefund: $newAvailableForRefund,
    refundedAt: $response->refundedAt instanceof DateTimeImmutable
        ? $response->refundedAt
        : DateTimeImmutable::createFromMutable($response->refundedAt), // Mapping with conversion
    providerData: $response->providerData ?? [] // Mapping
);
```

**Problem:** 6 lines of pure mapping code that exists only because we have two DTOs.

### Flow 2: Direct API (Bypasses Response Entirely)

**File:** `stripe/src/Stripe/Service/RefundService.php:177-182`

```php
return RefundResult::success(
    $refund->id ?? 'unknown',
    (int) ($refund->amount ?? 0),
    $refund->currency ?? 'eur',
    $status
);
```

**Observation:** Stripe's RefundService creates RefundResult directly from Stripe SDK, never using RefundResponse. This proves RefundResponse adds no value in this flow.

---

## Design Intent vs Reality

### Original Design Intent

```
Adapter (Stripe SDK) → RefundResponse (normalized) → Service → RefundResult (with business logic)
```

The idea was:
1. **RefundResponse**: Provider-agnostic normalization of raw API response
2. **RefundResult**: Business-level result with computed fields

### Reality

1. The "normalization" is minimal - just field renaming
2. Business fields (`totalRefunded`, `availableForRefund`) are computed in the service, not stored
3. Stripe already bypasses RefundResponse in direct flows
4. 60% field overlap means we're maintaining two classes for the same data

---

## Response Classes Usage Analysis

### RefundResponse Usages

| Location | Usage |
|----------|-------|
| `PaymentAdapterInterface::refundPayment()` | Return type |
| `StripeAdapter::refundPayment()` | Creates and returns |
| `LazyStripeAdapter::refundPayment()` | Delegates |
| `AbstractPaymentRefundService::executeRefund()` | Receives |
| `AbstractPaymentRefundService::afterRefund()` | Receives (for logging) |

### CaptureResponse Usages

| Location | Usage |
|----------|-------|
| `PaymentAdapterInterface::capturePayment()` | Return type |
| `StripeAdapter::capturePayment()` | Creates and returns |
| `LazyStripeAdapter::capturePayment()` | Delegates |
| `AbstractPaymentCaptureService` | (if exists) |

---

## Recommendations

### Primary Recommendation: Keep Response Classes, Delete Result Classes

**Direction changed:** Consolidate to `Adapter/Response/` layer. Delete `Service/Result/` entirely.

1. **Enhance Response classes** with error fields (`successful`, `errorMessage`, `errorCode`)
2. **Add factory methods** to Response classes: `success()`, `failure()`
3. **Create FraudCheckResponse** (no existing Response for this)
4. **Rename VoidResponse → CancellationResponse**
5. **Delete all Service/Result/** files
6. **Update services** to return Response objects

### Benefits

- Single DTO layer in `Adapter/Response/`
- Clear ownership - DTOs belong to Adapter layer
- Simpler mental model - one place for DTOs
- Consistent naming - all end with `Response`
- ~400 lines deleted (4 Result files)

### Business Fields

`totalRefunded`, `availableForRefund` stay in service layer (computed values, not adapter data).
Services return array or wrapper if business fields needed alongside Response.

---

## Files Affected

### To Delete (5 files + folder)

```
payment-component/src/Service/Result/CaptureResult.php
payment-component/src/Service/Result/RefundResult.php
payment-component/src/Service/Result/CancellationResult.php
payment-component/src/Service/Result/FraudCheckResult.php
payment-component/src/Service/Result/  (folder)
payment-component/src/Adapter/Response/VoidResponse.php  (renamed)
```

### To Enhance (2 files)

```
payment-component/src/Adapter/Response/CaptureResponse.php  (add error fields, factories)
payment-component/src/Adapter/Response/RefundResponse.php   (add error fields, factories)
```

### To Create (2 files)

```
payment-component/src/Adapter/Response/CancellationResponse.php  (replaces VoidResponse)
payment-component/src/Adapter/Response/FraudCheckResponse.php    (new)
```

### To Update (services)

```
stripe/src/Stripe/Service/RefundService.php              (return RefundResponse)
stripe/src/Stripe/Service/CaptureService.php             (return CaptureResponse)
stripe/src/Stripe/Service/CancelAuthorizationService.php (return CancellationResponse)
```

### Other Response Classes (keep as-is)

| Class | Action |
|-------|--------|
| `PaymentResponse` | Keep (unique purpose) |
| `AuthorizationResponse` | Keep (unique purpose) |
| `OrderResponse` | Keep (unique purpose) |
| `PaymentDetailsResponse` | Keep (unique purpose) |
| `PaymentMethodResponse` | Keep (unique purpose) |
| `ThreeDSecureResponse` | Keep (unique purpose) |
