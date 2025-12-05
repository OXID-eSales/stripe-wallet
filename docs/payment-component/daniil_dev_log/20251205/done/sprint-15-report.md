# Sprint 15: NO_CONTRACT is ERROR - COMPLETED

**Date:** 2025-12-05
**Status:** COMPLETED
**Branch:** b-7.4.x-auth-STRP-70

---

## Summary

Fixed incorrect assumption that NO_CONTRACT is an edge case. Every PaymentIntent MUST have a Contract - NO_CONTRACT is an ERROR, not legacy support.

**Key Decisions (APPROVED):**
- Legacy data: Delete invalid orders manually
- HTTP response: Log ERROR + return 200 (so Stripe doesn't retry)

---

## Test Results

```
PHPUnit 11.5.44
Total Tests: 1224
Sprint 15 Tests: 28
Status: OK (all passing)

Pre-commit Check:
✓ PHP Code Sniffer passed
✓ PHPUnit tests passed
✓ PHPStan passed
✓ PHPMD passed
Status: COMMITABLE
```

---

## Files Modified (5)

### Source Files (2)

| File | Changes |
|------|---------|
| `src/Stripe/Webhook/Handler/PaymentIntentSucceededHandler.php` | Contract required, NO_CONTRACT logs error + returns 200, OXPAID only with committed contract |
| `src/Stripe/Service/OxpaidReconciliationService.php` | Contract checked first (before Stripe API), removed dead code (`fulfillRelatedContract`, `$logger`) |

### Test Files (3)

| File | Changes |
|------|---------|
| `tests/Unit/Stripe/Webhook/Handler/PaymentIntentSucceededHandlerTest.php` | Added `@group sprint-15`, updated tests to require contract, added `logsErrorWhenNoContractFound` |
| `tests/Unit/Stripe/Service/OxpaidReconciliationServiceTest.php` | Added `@group sprint-15`, added `reconcileOrderFailsWithoutContract`, `reconcileAllFailsOrdersWithoutContracts`, all tests require contract |
| `tests/Integration/Stripe/Webhook/WebhookContractTransitionTest.php` | Added `@group sprint-15`, replaced `paymentIntentSucceededHandlesNoContract` with `paymentIntentSucceededLogsErrorWhenNoContract` |

### Bug Fix (1)

| File | Changes |
|------|---------|
| `src/Component/Webhook/WebhookEvent.php` | Added PHPStan type annotation to fix `array<mixed>` return type |

---

## SOLID Principles Applied

### Single Responsibility
- `PaymentIntentSucceededHandler` - Handles webhook only if contract exists
- `OxpaidReconciliationService` - Reconciles only with valid contract

### Liskov Substitution (LSP)
```php
// Type-hint interface, not concrete class
private readonly ContractRepositoryInterface $contractRepository;
```

### Dependency Injection
```php
// Constructor injection - removed unused $logger
public function __construct(
    private readonly Connection $connection,
    private readonly StripeAdapterFactoryInterface $adapterFactory,
    private readonly ContractRepositoryInterface $contractRepository,
    private readonly ?FileLoggerInterface $fileLogger = null
) {}
```

---

## Behavior Changes

### PaymentIntentSucceededHandler

| Scenario | Before (Wrong) | After (Correct) |
|----------|----------------|-----------------|
| No contract | Update OXPAID, success | Log ERROR, success (200) |
| Contract exists, not committed | Skip fulfillment | Skip fulfillment, no OXPAID |
| Contract committed | Fulfill + OXPAID | Fulfill + OXPAID |

### OxpaidReconciliationService

| Scenario | Before (Wrong) | After (Correct) |
|----------|----------------|-----------------|
| No contract | Call Stripe API, update OXPAID | Return `no_contract` failure immediately |
| Contract exists | Call Stripe API, update | Call Stripe API, update |

---

## Test Coverage

### New Tests Added

| Test | Description |
|------|-------------|
| `logsErrorWhenNoContractFound` | NO_CONTRACT logs error, returns 200 |
| `reconcileOrderFailsWithoutContract` | Reconciliation fails without contract |
| `reconcileAllFailsOrdersWithoutContracts` | Batch reconciliation skips orders without contracts |
| `paymentIntentSucceededLogsErrorWhenNoContract` | Integration test for NO_CONTRACT error |

### Updated Tests

| Test | Change |
|------|--------|
| `updatesOxpaidTimestampWithContract` | Now requires contract in COMMITTED state |
| `reconcileOrderUpdatesOxpaidWhenPaymentIsCaptured` | Now requires contract |
| `reconcileOrderSkipsWhenPaymentNotCaptured` | Now requires contract |
| `reconcileOrderHandlesApiError` | Now requires contract |
| `reconcileAllProcessesAllOrders` | Now requires contracts for all orders |

---

## Acceptance Criteria

### Must Have
- [x] `payment_intent.succeeded` without contract logs ERROR (returns 200)
- [x] `reconcileOrder()` without contract returns FAILURE
- [x] OXPAID is NOT updated without valid contract
- [x] NO_CONTRACT logged as ERROR, not SUCCESS
- [x] All tests pass (1224 tests)

### Should Have
- [x] Clear error messages for debugging
- [x] Proper logging for NO_CONTRACT errors
- [x] Removed dead code (fulfillRelatedContract method)

---

## Run Commands

```bash
# Run Sprint 15 tests
docker compose exec php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --group sprint-15

# Run webhook handler tests
docker compose exec php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
    extensions/stripe/tests/Unit/Stripe/Webhook/Handler/PaymentIntentSucceededHandlerTest.php

# Run reconciliation tests
docker compose exec php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
    extensions/stripe/tests/Unit/Stripe/Service/OxpaidReconciliationServiceTest.php

# Run all tests
./bin/pre-commit-check.sh
```

---

## Summary Table

| Issue | Before (Wrong) | After (Correct) |
|-------|----------------|-----------------|
| NO_CONTRACT | Treated as success/edge case | ERROR - logged and tracked |
| OXPAID update | Updated without contract | Only with valid COMMITTED contract |
| Stripe API call | Called even without contract | Only called if contract exists |
| Webhook response | 200 OK (silent) | 200 OK (logged error) |
| Log level | SUCCESS/INFO | ERROR |
| Dead code | `fulfillRelatedContract()` existed | Removed |
