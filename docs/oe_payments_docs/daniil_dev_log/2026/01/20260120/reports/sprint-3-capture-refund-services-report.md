# Sprint 3: Capture/Refund Services Refactoring Report

**Date:** 2026-01-20
**Status:** Completed

---

## Summary

Refactored `PaymentCaptureService` and `PaymentRefundService` to use Template Method pattern via abstract base classes. Created Stripe-specific implementations that override hook methods for provider-specific behavior.

---

## Q&A Decisions

| # | Question | Decision |
|---|----------|----------|
| Q1 | Unused services approach | B) Refactor to Abstract (Template Method pattern) |
| Q2 | Scope | A) Both Capture & Refund |
| Q3 | State validation | B) Hook method with default |
| Q4 | Adapter handling | B) Constructor injection (Liskov Substitution) |
| Q5 | After capture | A) Hook method `afterCapture()` with default |
| Q6 | Refund identifier | A) Use contractId (contract-centric) |
| Q7 | Partial refunds | A) Yes, in base class with optional amount |
| Q8 | Error handling | A) Throw exceptions |
| Q9 | Stripe integration | A) Create Stripe services, handlers delegate |

---

## Files Created

### payment-component (8 files)

| File | Purpose |
|------|---------|
| `src/Service/AbstractPaymentCaptureService.php` | Template Method base class for capture |
| `src/Service/AbstractPaymentRefundService.php` | Template Method base class for refund |
| `src/Service/Exception/CaptureFailedException.php` | Exception for capture failures |
| `src/Service/Exception/RefundFailedException.php` | Exception for refund failures |
| `src/Service/Result/CaptureResult.php` | Value object for capture results |
| `src/Service/Result/RefundResult.php` | Value object for refund results |
| `tests/Unit/Service/Exception/CaptureFailedExceptionTest.php` | Tests for exception |
| `tests/Unit/Service/Exception/RefundFailedExceptionTest.php` | Tests for exception |

### stripe (4 files)

| File | Purpose |
|------|---------|
| `src/Stripe/Service/StripeCaptureService.php` | Stripe-specific capture (AUTHORIZED state) |
| `src/Stripe/Service/StripeRefundService.php` | Stripe-specific refund (contract-based) |
| `tests/Unit/Stripe/Service/StripeCaptureServiceTest.php` | 10 tests |
| `tests/Unit/Stripe/Service/StripeRefundServiceTest.php` | 10 tests |

---

## Files Modified

### payment-component

| File | Changes |
|------|---------|
| `src/Service/PaymentCaptureService.php` | Now extends AbstractPaymentCaptureService |
| `src/Service/PaymentRefundService.php` | Now extends AbstractPaymentRefundService |
| `tests/Unit/Service/PaymentCaptureServiceTest.php` | Updated for new API (8 tests) |
| `tests/Unit/Service/PaymentRefundServiceTest.php` | Updated for new API (10 tests) |

### stripe

| File | Changes |
|------|---------|
| `services.yaml` | Added StripeCaptureService and StripeRefundService registrations |

---

## Architecture

### Template Method Pattern

```
AbstractPaymentCaptureService (payment-component)
├── capture() - final, orchestrates flow
├── validateStateForCapture() - hook, default: COMMITTED
├── afterCapture() - hook, default: fulfill()
│
├── PaymentCaptureService - uses defaults
│
└── StripeCaptureService (stripe)
    ├── validateStateForCapture() - override: AUTHORIZED
    └── afterCapture() - override: captureAuthorization()
```

### Key Differences

| Behavior | Default (PaymentCaptureService) | Stripe (StripeCaptureService) |
|----------|--------------------------------|------------------------------|
| Valid state | COMMITTED | AUTHORIZED |
| After capture | `fulfill()` | `captureAuthorization()` |

---

## Test Results

| Suite | Tests | Status |
|-------|-------|--------|
| payment-component Unit | 688 | ✅ Pass |
| stripe Unit | 595 | ✅ Pass |

### New Tests Added

- `CaptureFailedExceptionTest` - 4 tests
- `RefundFailedExceptionTest` - 4 tests
- `PaymentCaptureServiceTest` - 8 tests (updated)
- `PaymentRefundServiceTest` - 10 tests (updated)
- `StripeCaptureServiceTest` - 10 tests
- `StripeRefundServiceTest` - 10 tests

---

## Services Configuration

Added to `services.yaml`:

```yaml
OxidEsales\Payments\Stripe\Service\StripeCaptureService:
  arguments:
    $contractRepository: '@OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface'
    $paymentAdapter: '@OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface'
    $logger: '@oxid_esales.monolog.logger'
  public: true

OxidEsales\Payments\Stripe\Service\StripeRefundService:
  arguments:
    $contractRepository: '@OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface'
    $transactionRepository: '@OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface'
    $paymentAdapter: '@OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface'
    $logger: '@oxid_esales.monolog.logger'
  public: true
```

---

## Future Work

- **Phase 6 (deferred):** Update handlers to delegate to services
  - `StripeCaptureRequestHandler` → delegate to `StripeCaptureService`
  - `StripeRefundRequestHandler` → delegate to `StripeRefundService`

This was deferred as the existing handlers work correctly and the refactor would be a larger change.
