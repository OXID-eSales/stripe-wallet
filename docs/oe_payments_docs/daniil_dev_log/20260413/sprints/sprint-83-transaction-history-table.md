# Sprint 83: Transaction History Table on Admin Order Detail Page

**Date:** 2026-04-13
**Branch:** `b-7.4.x`
**Ticket:** STRP-119

## Problem

The admin order detail page (Stripe tab) shows payment details (contract ID, order ID, payment type, transaction ID) but has **no visibility into the transaction history** of the contract. Admins cannot see the sequence of authorizations, captures, and refunds that occurred for a given order/contract without going to the Stripe Dashboard.

## Goal

Add a **Transaction History** table to the Stripe order detail tab, displayed immediately after the "Payment Details" fieldset. The table shows all `oe_payments_transaction` records linked to the current order's contract, sorted by creation date ascending.

## Approach

**Minimal, clean implementation** reusing existing infrastructure:

1. **payment-component already provides:** `TransactionRepositoryInterface::findByContractId(string): Transaction[]` — returns all transactions for a contract, sorted by `createdAt ASC`. No new repository code needed.

2. **`OrderRefundViewDataProvider`** (`src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php`) — add `TransactionRepositoryInterface` as constructor dependency + `getTransactionsForContract(string $contractId): array` method. Returns `Transaction::toArray()` for each record (simple array data for Twig).

3. **`OrderRefund` controller** (`src/Stripe/Controller/Admin/OrderRefund.php`) — add `getTransactions(): array` that gets the contract ID via existing `getContractId()` and delegates to the view data provider.

4. **Twig template** (`views/twig/admin/stripe_order_refund.html.twig`) — add a `<fieldset>` with a `<table>` after the Payment Details fieldset (line 196). Columns: Type, Status, Amount, Currency, Transaction ID, Date.

5. **Translations** — add keys to `views/admin_twig/{en,de}/stripe_lang.php`.

6. **DI wiring** — add `$transactionRepository` argument to `OrderRefundViewDataProvider` in `services.yaml:876`.

## What We Reuse (No New Code)

| Component | Location | Already Exists |
|-----------|----------|---------------|
| `Transaction` entity | `payment-component/src/Contract/Transaction.php` | `toArray()`, `fromArray()` |
| `TransactionRepositoryInterface` | `payment-component/src/Repository/TransactionRepositoryInterface.php` | `findByContractId()` |
| `DoctrineTransactionRepository` | `payment-component/src/Repository/DoctrineTransactionRepository.php` | Full implementation |
| DI registration | `services.yaml:769-777` | Interface → Doctrine alias |
| `OrderRefund::getContractId()` | `src/Stripe/Controller/Admin/OrderRefund.php:245` | Resolves contract ID from order |
| `OrderRefund::getOrder()` | `src/Stripe/Controller/Admin/OrderRefund.php:231` | Loads order by edit object ID |

## Existing Test Infrastructure

Tests follow established patterns from Sprint 82:

| File | Pattern | Used For |
|------|---------|----------|
| `tests/Unit/.../OrderRefundVisibilityTest.php` | `TestableOrderRefundForVisibility` — constructor injects `?Order`, `?OrderRefundViewDataProvider`, skips OXID admin bootstrap | Unit tests for controller methods |
| `tests/Integration/.../OrderRefundControllerTest.php` | `TestableOrderRefund` — setter-based injection for `ActionDispatcher`, `ViewDataProvider`, `Order`, `EditObjectId`; skips CSRF | Integration tests for full action flows |
| `tests/Unit/.../OrderRefundCsrfTest.php` | `TestableOrderRefundForCsrf` — separate testable subclass for CSRF-specific tests | Security tests |

**Key convention:** Each concern gets its own test file + own testable subclass. `TestableOrderRefundForVisibility` (visibility), `TestableOrderRefundForCsrf` (csrf), `TestableOrderRefund` (integration). Sprint 83 follows the same pattern.

## What We Add/Modify

| Action | File | Change |
|--------|------|--------|
| CREATE | `tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php` | New test: `getTransactionsForContract()` with mocked `TransactionRepositoryInterface` |
| CREATE | `tests/Unit/Stripe/Controller/Admin/OrderRefundTransactionHistoryTest.php` | New test: `getTransactions()` using `TestableOrderRefundForTransactions` subclass |
| MODIFY | `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | Add `TransactionRepositoryInterface` constructor param + `getTransactionsForContract()` |
| MODIFY | `src/Stripe/Controller/Admin/OrderRefund.php` | Add `getTransactions(): array` (thin delegation) |
| MODIFY | `views/twig/admin/stripe_order_refund.html.twig` | Add transaction history fieldset after line 196 |
| MODIFY | `views/admin_twig/en/stripe_lang.php` | Add 8 translation keys |
| MODIFY | `views/admin_twig/de/stripe_lang.php` | Add 8 translation keys |
| MODIFY | `services.yaml` | Add `$transactionRepository` arg to `OrderRefundViewDataProvider` (~line 876) |

## TDD Approach

### Phase 1: RED — Write Failing Tests

**Unit Test 1: `OrderRefundViewDataProviderTest`** (NEW file)
```
Arrange: mock TransactionRepositoryInterface, mock StripeOrderApiService
Act: call getTransactionsForContract('contract_123')
Assert: returns array of Transaction::toArray() data
```
Tests:
- `testGetTransactionsForContractReturnsTransactionArrays()` — repo returns 2 transactions, method returns 2 arrays
- `testGetTransactionsForContractReturnsEmptyArrayWhenNoTransactions()` — repo returns [], method returns []
- `testGetTransactionsForContractCallsFindByContractId()` — verifies repo method is called with correct ID

**Unit Test 2: `OrderRefundTransactionHistoryTest`** (NEW file, follows `OrderRefundVisibilityTest` pattern)
```
Arrange: TestableOrderRefundForTransactions with mocked ViewDataProvider + ActionDispatcher
Act: call getTransactions()
Assert: returns transaction data from provider
```
Tests:
- `testGetTransactionsReturnsDataWhenContractIdExists()` — contract resolves, provider returns data
- `testGetTransactionsReturnsEmptyArrayWhenNoContractId()` — no contract, returns []

### Phase 2: GREEN — Implement

1. Add `TransactionRepositoryInterface` to `OrderRefundViewDataProvider::__construct()`
2. Add `getTransactionsForContract(string $contractId): array` — maps `findByContractId()` through `toArray()`
3. Add `getTransactions(): array` to `OrderRefund` — gets contractId, delegates to provider
4. Update `services.yaml` DI wiring for `OrderRefundViewDataProvider`
5. Add Twig template block after Payment Details fieldset
6. Add EN/DE translations

### Phase 3: REFACTOR

- `./bin/pre-commit-check.sh --full` — all tests GREEN, PHPCS/PHPStan/PHPMD clean

## Transaction Table Columns

| Column | Source | Notes |
|--------|--------|-------|
| Type | `Transaction::getType()` | authorization, capture, refund, void |
| Status | `Transaction::getStatus()` | pending, completed, failed, cancelled |
| Amount | `Transaction::getAmount()` | Formatted with order currency |
| Currency | `Transaction::getCurrency()` | ISO 4217 |
| Transaction ID | `Transaction::getTransactionId()` | Provider transaction ID (nullable, show `-` if null) |
| Date | `Transaction::getCreatedAt()` | Y-m-d H:i:s format from `toArray()` |

## Translation Keys

| Key | EN | DE |
|-----|----|----|
| `STRIPE_TRANSACTION_HISTORY` | Transaction History | Transaktionsverlauf |
| `STRIPE_TRANSACTION_TYPE` | Type | Typ |
| `STRIPE_TRANSACTION_STATUS` | Status | Status |
| `STRIPE_TRANSACTION_AMOUNT` | Amount | Betrag |
| `STRIPE_TRANSACTION_CURRENCY` | Currency | Währung |
| `STRIPE_TRANSACTION_PROVIDER_ID` | Provider Transaction ID | Anbieter-Transaktions-ID |
| `STRIPE_TRANSACTION_DATE` | Date | Datum |
| `STRIPE_NO_TRANSACTIONS` | No transactions recorded. | Keine Transaktionen vorhanden. |

## Subtasks

| # | Task | File(s) | Status | Acceptance Criteria |
|---|------|---------|--------|---------------------|
| 1 | Write failing unit test for ViewDataProvider | `tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php` | pending | 3 tests call `getTransactionsForContract()`, all fail (method not found) |
| 2 | Write failing unit test for OrderRefund | `tests/Unit/Stripe/Controller/Admin/OrderRefundTransactionHistoryTest.php` | pending | 2 tests call `getTransactions()`, all fail (method not found). Uses `TestableOrderRefundForTransactions` following `OrderRefundVisibilityTest` pattern |
| 3 | Implement `getTransactionsForContract()` on ViewDataProvider | `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php`, `services.yaml` | pending | Constructor accepts `TransactionRepositoryInterface`, method returns `Transaction::toArray()[]`, DI wired |
| 4 | Implement `getTransactions()` on controller | `src/Stripe/Controller/Admin/OrderRefund.php` | pending | Delegates to provider via `getContractId()`, returns `array` |
| 5 | Add Twig template block | `views/twig/admin/stripe_order_refund.html.twig` | pending | Table renders after Payment Details fieldset (after line 196) |
| 6 | Add EN/DE translations | `views/admin_twig/{en,de}/stripe_lang.php` | pending | All 8 keys present in both files |
| 7 | Run full pre-commit check | - | pending | All tests GREEN, 0 PHPCS errors, 0 PHPStan errors, 0 new PHPMD violations |

## Out of Scope

- Pagination for transaction table (unlikely to exceed ~10 rows per order)
- Filtering/sorting controls (KISS — static table is sufficient)
- Links to Stripe Dashboard from transaction IDs (future enhancement)
- New database queries or repository methods (everything exists in payment-component)

## Deliverables

- 2 new unit test files
- 2 source files modified (ViewDataProvider, OrderRefund controller)
- 1 template modified
- 2 translation files modified
- 1 services.yaml modified
- Sprint completion report in `done/`
