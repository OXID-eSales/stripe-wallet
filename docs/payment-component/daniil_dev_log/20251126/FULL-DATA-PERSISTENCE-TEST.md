# Full Data Persistence Flow Test

**Date:** 2025-11-26
**Status:** Completed
**Test File:** `tests/Integration/Component/Checkout/FullDataPersistenceFlowTest.php`

## Overview

Created comprehensive integration tests that verify ALL `oe_payments_*` tables are populated during the checkout flow as documented in `docs/payment-component/puml/04-02-payment-smart-contract-flow-standard.puml`.

## Tables Tested

| Table | Tests | Description |
|-------|-------|-------------|
| `oe_payments_contract` | 2 | Contract creation, state machine, user/order links |
| `oe_payments_customer` | 2 | Customer payment profile, Stripe customer ID, saved methods |
| `oe_payments_order_state` | 2 | Order ↔ Contract link, payment state tracking |
| `oe_payments_transaction` | 4 | Authorization, capture, refund, find by order |
| `oe_payments_sessions` | 2 | Session data, expiry handling |
| `oxorder` | 2 | Real OXID order creation, totals, user link |
| `oxuser` | 2 | Real OXID user creation, links |

**Total: 13 tests, 72 assertions**

## Test Results

```
Full Data Persistence Flow
 ✔ ContractCreation PersistsContractWithUserAndOrder
 ✔ OrderCommit LinksContractToRealOrder
 ✔ Transaction PersistsAuthorizationTransaction
 ✔ Transaction PersistsCaptureTransaction
 ✔ Transaction PersistsRefundTransaction
 ✔ Transaction FindByOrderIdReturnsAllTransactions
 ✔ OrderState PersistsOrderContractLink
 ✔ OrderState TracksPaymentStateChanges
 ✔ PaymentCustomer PersistsCustomerPaymentProfile
 ✔ PaymentCustomer LinksToOxuser
 ✔ PaymentSession PersistsSessionData
 ✔ PaymentSession ExpiresCorrectly
 ✔ CompleteFlow PopulatesAllTables

OK (13 tests, 72 assertions)
```

## Complete Flow Test

The `testCompleteFlow_PopulatesAllTables()` test verifies the entire checkout flow:

```
1. oxuser           → Customer created
2. oe_payments_customer → Payment profile with Stripe customer ID
3. oe_payments_sessions → Payment session started
4. oe_payments_contract → Contract created and transitions:
                          DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
5. oxorder          → Real order created when committed
6. oe_payments_order_state → Order-contract link with payment state
7. oe_payments_transaction → Authorization and capture transactions
```

## Key Features

### 1. Real Database Connection
Uses OXID's `ConnectionProviderInterface` to get actual MySQL connection:
```php
$container = ContainerFactory::getInstance()->getContainer();
$connectionProvider = $container->get(ConnectionProviderInterface::class);
$this->connection = $connectionProvider->get();
```

### 2. Data Persistence (No Cleanup)
Data is committed, not rolled back:
```php
public function tearDown(): void
{
    $this->commitTransaction(); // Data stays in DB for inspection
}
```

### 3. Test Data Identification
All test IDs use `e2e_dp_` prefix (data persistence):
```sql
SELECT * FROM oe_payments_contract WHERE OXID LIKE 'e2e_dp_%';
SELECT * FROM oe_payments_transaction WHERE OXID LIKE 'e2e_dp_%';
SELECT * FROM oe_payments_order_state WHERE OXID LIKE 'e2e_dp_%';
SELECT * FROM oe_payments_customer WHERE OXID LIKE 'e2e_dp_%';
SELECT * FROM oe_payments_sessions WHERE OXID LIKE 'e2e_dp_%';
SELECT * FROM oxorder WHERE OXID LIKE 'e2e_dp_%';
SELECT * FROM oxuser WHERE OXID LIKE 'e2e_dp_%';
```

### 4. Foreign Key Compliance
Tests respect database foreign key constraints:
- Contracts are created before transactions
- Users are created before orders
- Orders are created before order_state records

## Tables NOT Tested

As requested, these tables are excluded (require Stripe API integration):
- `oe_payments_webhooklogs` - Requires real webhook events
- `oe_payments_idempotency` - Requires real API calls

## Pre-Commit Script Update

Added `--full` flag to run all tests including Integration:

```bash
# Fast check (Unit tests only) - default
./bin/pre-commit-check.sh

# Full check (Unit + Integration tests)
./bin/pre-commit-check.sh --full

# Skip PHPUnit tests
./bin/pre-commit-check.sh --no-phpunit
```

## Files Created/Modified

| File | Change |
|------|--------|
| `tests/Integration/Component/Checkout/FullDataPersistenceFlowTest.php` | **NEW** - 13 tests |
| `bin/pre-commit-check.sh` | Added `--full` flag |
| `README.md` | Added testing documentation |

## Running the Tests

```bash
# Run only data persistence tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
    extensions/stripe/tests/Integration/Component/Checkout/FullDataPersistenceFlowTest.php --testdox

# Run all integration tests
./bin/pre-commit-check.sh --full
```

## Cleanup Test Data

```sql
-- Clean up all E2E test data
DELETE FROM oe_payments_transaction WHERE OXID LIKE 'e2e_dp_%';
DELETE FROM oe_payments_order_state WHERE OXID LIKE 'e2e_dp_%';
DELETE FROM oe_payments_sessions WHERE OXID LIKE 'e2e_dp_%';
DELETE FROM oe_payments_customer WHERE OXID LIKE 'e2e_dp_%';
DELETE FROM oe_payments_contract WHERE OXID LIKE 'e2e_dp_%';
DELETE FROM oxorder WHERE OXID LIKE 'e2e_dp_%';
DELETE FROM oxuser WHERE OXID LIKE 'e2e_dp_%';
```

## Related Documentation

- Architecture: `docs/payment-component/01-architecture-layers.md`
- Flow Diagram: `docs/payment-component/puml/04-02-payment-smart-contract-flow-standard.puml`
- State Machine: `docs/payment-component/puml/05-order-state-contract-machine.puml`
- E2E Checkout Test: `docs/payment-component/daniil_dev_log/20251126/E2E-CHECKOUT-FLOW-TEST.md`
