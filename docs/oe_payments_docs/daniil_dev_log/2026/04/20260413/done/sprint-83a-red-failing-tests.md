# Sprint 83a: RED — Failing Tests for Transaction History

**Date:** 2026-04-13
**Branch:** `b-7.4.x`
**Parent:** Sprint 83 (Transaction History Table)

## Objective

Write failing unit tests for the two new methods before any production code exists. Tests must fail with "method not found" or similar — confirming they test something that doesn't exist yet.

## Deliverables

### File 1: `tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php` (CREATE)

Tests for `OrderRefundViewDataProvider::getTransactionsForContract()`:

| Test | Arrange | Act | Assert |
|------|---------|-----|--------|
| `testGetTransactionsForContractReturnsTransactionArrays` | Mock `TransactionRepositoryInterface` returns 2 `Transaction` objects | `getTransactionsForContract('c_123')` | Returns array of 2 `toArray()` results |
| `testGetTransactionsForContractReturnsEmptyArrayWhenNoTransactions` | Mock repo returns `[]` | `getTransactionsForContract('c_empty')` | Returns `[]` |
| `testGetTransactionsForContractCallsFindByContractId` | Mock repo expects `findByContractId('c_456')` called once | `getTransactionsForContract('c_456')` | `expects($this->once())` passes |

Dependencies to mock:
- `TransactionRepositoryInterface` — the new dependency
- `StripeOrderApiService` — existing constructor dependency (stub, not used in this method)

### File 2: `tests/Unit/Stripe/Controller/Admin/OrderRefundTransactionHistoryTest.php` (CREATE)

Tests for `OrderRefund::getTransactions()`, following `OrderRefundVisibilityTest` pattern:

| Test | Arrange | Act | Assert |
|------|---------|-----|--------|
| `testGetTransactionsReturnsDataWhenContractIdExists` | `TestableOrderRefundForTransactions` with mocked provider returning data, mocked dispatcher returning contract ID | `getTransactions()` | Returns transaction array data |
| `testGetTransactionsReturnsEmptyArrayWhenNoContractId` | Mocked dispatcher returns `null` contract ID | `getTransactions()` | Returns `[]` |

Testable subclass: `TestableOrderRefundForTransactions extends OrderRefund`
- Constructor injects `?Order`, `?OrderRefundViewDataProvider`, `?OrderActionDispatcher`
- Overrides `getOrder()`, `getViewDataProvider()`, `getActionDispatcher()`
- Follows exact same pattern as `TestableOrderRefundForVisibility`

## Acceptance Criteria

- [ ] Both test files created, PSR-12 compliant
- [ ] All 5 tests FAIL (RED) when run — method `getTransactionsForContract()` / `getTransactions()` does not exist
- [ ] No production code modified
