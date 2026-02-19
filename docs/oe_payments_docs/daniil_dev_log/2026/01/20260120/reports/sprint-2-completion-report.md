# Sprint 2 Completion Report: Condition Handlers TDD Implementation

**Date:** 2026-01-20
**Status:** COMPLETE

---

## Overview

Sprint 2 implemented three condition handlers following TDD methodology and the Q&A decisions made during sprint planning:

1. **StockReservationHandler** - Reserves stock on contract creation (DRAFT)
2. **StockReleaseHandler** - Releases stock on terminal states (except FULFILLED)
3. **FraudCheckHandler** - Checks Stripe Radar score on payment authorization

---

## Q&A Decisions Applied

| Question | Decision |
|----------|----------|
| Stock manipulation | Direct OXSTOCK (no tracking table) |
| Fraud check source | Stripe Radar score (interface in component, impl in Stripe) |
| Fraud check outcome | Binary pass/fail only (no manual review) |
| Default threshold | 0.7 (scores >= 0.7 fail) |
| On fraud failure | Cancel contract |
| Stock reservation timing | On DRAFT (before Stripe redirect) |
| Stock reservation mode | Synchronous, can fail contract creation |
| Stock release timing | All terminal states except FULFILLED |
| Interface style | Contract-aware (`reserveForContract`, `releaseForContract`) |
| On release failure | Throw exception (strict consistency) |
| Handler location | All in payment-component (provider-independent) |
| Configuration | Admin toggles for stock reservation and fraud check |
| When OFF | OXID handles stock normally, fraud check skipped |

---

## Files Created/Modified

### payment-component (New Files)

```
src/Service/
├── StockServiceInterface.php          # Contract-aware stock operations
├── OxidStockService.php               # OXID implementation (direct OXSTOCK)
├── FraudCheckServiceInterface.php     # Fraud check contract
├── Result/
│   └── FraudCheckResult.php           # Pass/fail value object
└── Exception/
    ├── InsufficientStockException.php # For stock reservation failures
    └── StockReleaseException.php      # For stock release failures

tests/Unit/Service/
├── OxidStockServiceTest.php           # 11 tests
├── Result/
│   └── FraudCheckResultTest.php       # 4 tests
└── Exception/
    ├── InsufficientStockExceptionTest.php  # 3 tests
    └── StockReleaseExceptionTest.php       # 4 tests
```

### payment-component (Modified Files)

```
src/EventSystem/Handler/
├── StockReservationHandler.php        # Refactored to use StockServiceInterface
├── StockReleaseHandler.php            # Refactored to use StockServiceInterface
└── FraudCheckHandler.php              # Refactored to use FraudCheckServiceInterface

tests/Unit/EventSystem/Handler/
├── StockReservationHandlerTest.php    # 5 tests (rewritten)
├── StockReleaseHandlerTest.php        # 7 tests (rewritten)
└── FraudCheckHandlerTest.php          # 8 tests (rewritten)
```

### stripe (New Files)

```
src/Stripe/Service/
└── StripeRadarFraudCheckService.php   # Stripe Radar implementation

src/Stripe/Adapter/
├── StripeAdapterInterface.php         # Added getPaymentIntentRiskScore()
└── StripeAdapter.php                  # Implemented getPaymentIntentRiskScore()

tests/Unit/Service/
└── StripeRadarFraudCheckServiceTest.php  # 8 tests
```

### Configuration

```
services.yaml additions:
- StockServiceInterface -> OxidStockService
- StockReservationHandler (priority: 90, enabled: %payment.stock_reservation.enabled%)
- StockReleaseHandler (enabled: %payment.stock_reservation.enabled%)
- FraudCheckHandler (priority: 85, enabled: %payment.fraud_check.enabled%)
- FraudCheckServiceInterface -> StripeRadarFraudCheckService

parameters:
- payment.stock_reservation.enabled: true
- payment.fraud_check.enabled: true
- payment.fraud_check.threshold: 0.7
```

---

## Test Results

### payment-component
- **679 tests, 1554 assertions**
- All pass
- PHPStan level 6: No errors
- PHPMD: No issues
- PHPCS: No issues

### stripe
- **575 tests, 1325 assertions**
- All pass
- PHPStan level 6: No errors on new files

---

## Architecture Summary

### Stock Flow

```
┌──────────────────────────────────────────────────────────────────────┐
│ Contract Created (DRAFT)                                              │
│   ↓                                                                  │
│ ContractCreatedEvent dispatched                                       │
│   ↓                                                                  │
│ StockReservationHandler (priority 90)                                 │
│   ├─ enabled=true: Reserve stock via StockServiceInterface            │
│   │    ├─ Success: Fulfill TYPE_STOCK_RESERVED condition              │
│   │    └─ Failure: Fail contract (InsufficientStockException)         │
│   └─ enabled=false: Skip reservation, immediately fulfill condition   │
└──────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────┐
│ Terminal Event (CANCELLED/EXPIRED/FAILED - not FULFILLED)             │
│   ↓                                                                  │
│ StockReleaseHandler                                                   │
│   ├─ enabled=true: Release stock via StockServiceInterface            │
│   │    └─ Failure: Throw StockReleaseException (strict consistency)   │
│   └─ enabled=false: Skip release                                      │
└──────────────────────────────────────────────────────────────────────┘
```

### Fraud Flow

```
┌──────────────────────────────────────────────────────────────────────┐
│ PaymentAuthorizedEvent (after Stripe return)                          │
│   ↓                                                                  │
│ FraudCheckHandler (priority 85)                                       │
│   ├─ enabled=true: Check via FraudCheckServiceInterface               │
│   │    ├─ score < threshold: Fulfill TYPE_FRAUD_CHECK condition       │
│   │    └─ score >= threshold: Fail contract                           │
│   └─ enabled=false: Skip check, immediately fulfill condition         │
└──────────────────────────────────────────────────────────────────────┘

StripeRadarFraudCheckService:
  - Gets PaymentIntent ID from contract metadata
  - Retrieves risk_score from Stripe API (latest_charge.outcome.risk_score)
  - Normalizes 0-100 score to 0.0-1.0
  - Compares against configurable threshold (default 0.7)
```

---

## Key Design Decisions

1. **Contract-aware interfaces** - Stock operations work at the contract level, not individual items. This simplifies the API and allows handlers to focus on the contract lifecycle.

2. **Direct OXSTOCK manipulation** - No tracking table needed. Stock is decremented/incremented directly in `oxarticles.OXSTOCK`. Reservation metadata stored on contract.

3. **Strict consistency** - Stock release failures throw exceptions rather than silently failing. This ensures data integrity.

4. **Configurable via parameters** - All three toggles can be set in `services.yaml` parameters and potentially exposed via admin configuration.

5. **Provider abstraction** - `FraudCheckServiceInterface` is in payment-component, but the Stripe Radar implementation is in stripe module. Other providers can implement their own fraud check services.

---

## What's Next

Sprint 3: Capture/Refund Services Investigation

---

*Report generated: 2026-01-20*
