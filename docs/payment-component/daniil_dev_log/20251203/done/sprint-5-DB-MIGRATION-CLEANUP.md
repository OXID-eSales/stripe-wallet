# Sprint 5: Database Migration Architecture Cleanup

**Date:** 2025-12-03
**Priority:** HIGH
**Status:** ✅ COMPLETED

---

## Problem Statement

The current codebase has critical architectural issues with database migrations:

### BUG 1: Migrations in Core/Event Class (Events.php)

**File:** `src/Stripe/Core/Events.php`

The `Events::onActivate()` method contains database schema modifications that should be in proper Doctrine migrations:

```php
// Line 68-73 - Called on module activation
public static function onActivate(): void
{
    self::addDatabaseStructure();      // ❌ Creates columns in core tables
    self::addStandardCheckoutTables(); // ❌ Creates oe_payments_* tables
    self::ensureStripePaymentMethods();
    // ...
}
```

**Why this is BAD:**
- Migrations are not versioned
- Cannot be rolled back
- Not tracked in `oxmigrations_osc_payments_stripe` table
- Runs on EVERY module activation (idempotent but inefficient)
- Schema changes should be in `migration/data/` folder

### BUG 2: Stripe Extension Modifying Core OXID Tables

**File:** `src/Stripe/Core/Events.php` (lines 168-183)

The extension adds STRIPE-specific columns to core OXID eShop tables:

```php
// Adding columns to oxorder (CORE TABLE!)
self::addColumnIfNotExists('oxorder', 'STRIPEDELCOSTREFUNDED', "ALTER TABLE `oxorder` ADD COLUMN `STRIPEDELCOSTREFUNDED` DOUBLE NOT NULL DEFAULT '0';");
self::addColumnIfNotExists('oxorder', 'STRIPEPAYCOSTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEWRAPCOSTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEGIFTCARDREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEVOUCHERDISCOUNTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEDISCOUNTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEMODE', ...);
self::addColumnIfNotExists('oxorder', 'STRIPESECONDCHANCEMAILSENT', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEEXTERNALTRANSID', ...);
self::addColumnIfNotExists('oxorder', 'STRIPESHIPMENTHASBEENMARKED', ...);

// Adding columns to oxorderarticles (CORE TABLE!)
self::addColumnIfNotExists('oxorderarticles', 'STRIPEQUANTITYREFUNDED', ...);
self::addColumnIfNotExists('oxorderarticles', 'STRIPEAMOUNTREFUNDED', ...);

// Adding columns to oxuser (CORE TABLE!)
self::addColumnIfNotExists('oxuser', 'STRIPECUSTOMERID', ...);
```

**Why this is BAD:**
- Pollutes core tables with vendor-specific columns
- Creates coupling between core schema and payment provider
- Makes it hard to uninstall the extension cleanly
- Violates separation of concerns
- All order-related data should be in `oe_payments_*` tables

### BUG 3: Tables Created in Events.php Instead of Migrations

**File:** `src/Stripe/Core/Events.php` (lines 272-336)

```php
protected static function addStandardCheckoutTables()
{
    // Creates oe_payments_transaction table
    self::addTableIfNotExists('oe_payments_transaction', "CREATE TABLE ...");

    // Creates oe_payments_order_state table
    self::addTableIfNotExists('oe_payments_order_state', "CREATE TABLE ...");
}
```

**Why this is BAD:**
- These should be in `migration/data/Version*.php` files
- Not versioned, not tracked, not rollbackable

### BUG 4: Legacy oe_payments_webhook_log References

Despite `Version20251202_Sprint2TableConsolidation.php` dropping the `oe_payments_webhook_log` table, code still references it:

**Files with references:**
- `src/Stripe/Service/WebhookProcessingService.php`
- `src/Stripe/Controller/Webhook/WebhookController.php`
- `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php`
- Multiple test and documentation files

---

## Architecture Rules

### Rule 1: Migrations Only in `migration/data/`
All database schema changes MUST be in Doctrine migrations located at:
```
/migration/data/VersionYYYYMMDD*.php
```

### Rule 2: Never Modify Core OXID Tables
Stripe extension MUST NOT add columns to:
- `oxorder`
- `oxorderarticles`
- `oxuser`
- Any other `ox*` core table

### Rule 3: Use oe_payments_* Tables Only
All Stripe-related order data MUST be stored in:
- `oe_payments_contract` - Payment contracts (Component)
- `oe_payments_order_state` - Payment state per order
- `oe_payments_transaction` - Individual transactions
- `oe_payments_stripe_*` - Stripe-specific data (if needed)

### Rule 4: Stripe-Specific Extensions
If existing tables don't fit the data model, create new tables:
- `oe_payments_stripe_order_refund` - For refund-related columns
- `oe_payments_stripe_customer` - For customer mapping

---

## Tasks

### Task 1: Remove STRIPE* Column Additions from Events.php

**File:** `src/Stripe/Core/Events.php`

Remove the following from `addDatabaseStructure()`:
```php
// DELETE THESE - we don't need them!
self::addColumnIfNotExists('oxorder', 'STRIPEDELCOSTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEPAYCOSTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEWRAPCOSTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEGIFTCARDREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEVOUCHERDISCOUNTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEDISCOUNTREFUNDED', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEMODE', ...);
self::addColumnIfNotExists('oxorder', 'STRIPESECONDCHANCEMAILSENT', ...);
self::addColumnIfNotExists('oxorder', 'STRIPEEXTERNALTRANSID', ...);
self::addColumnIfNotExists('oxorder', 'STRIPESHIPMENTHASBEENMARKED', ...);
self::addColumnIfNotExists('oxorderarticles', 'STRIPEQUANTITYREFUNDED', ...);
self::addColumnIfNotExists('oxorderarticles', 'STRIPEAMOUNTREFUNDED', ...);
self::addColumnIfNotExists('oxuser', 'STRIPECUSTOMERID', ...);
```

**Why?** All transaction data is already tracked in `oe_payments_transaction`:
- Refund amounts → calculated from transaction history
- Customer ID → stored in `oe_payments_customer.OXPAYMENTCUSTOMERID`
- External trans ID → stored in `oe_payments_transaction.OXTRANSACTIONID`

### Task 2: Move Table Creation from Events.php to Migrations

**File:** `src/Stripe/Core/Events.php`

The `addStandardCheckoutTables()` method creates tables in `onActivate()`.
These should be in `migration/data/` files instead.

**Already done:** Tables `oe_payments_transaction` and `oe_payments_order_state` are already
defined in proper migrations. Remove duplicate creation from Events.php.

### Task 3: Update OrderRefund Service

**File:** `src/Stripe/Controller/Admin/OrderRefund.php`

Update to calculate refund totals from `oe_payments_transaction` instead of reading
STRIPE* columns from `oxorder`:

```php
// OLD: Read from oxorder.STRIPEDELCOSTREFUNDED
// NEW: Calculate from transaction history
$refundedAmount = $this->getRefundedAmountFromTransactions($orderId);
```

### Task 4: ✅ DONE - Remove Legacy oe_payments_webhook_log References

Already completed:
- [x] `src/Stripe/Service/WebhookProcessingService.php` → `oe_payments_webhooklogs`
- [x] `src/Stripe/Controller/Webhook/WebhookController.php` → `oe_payments_webhooklogs`
- [x] `tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php` → `oe_payments_webhooklogs`

---

## Files to Modify

```
# Events.php - Remove DB schema modifications
src/Stripe/Core/Events.php

# Services to update (calculate from transaction history)
src/Stripe/Controller/Admin/OrderRefund.php

# Legacy cleanup (DONE)
src/Stripe/Service/WebhookProcessingService.php ✅
src/Stripe/Controller/Webhook/WebhookController.php ✅
tests/Integration/Stripe/Webhook/OxpaidWebhookUpdateTest.php ✅
```

---

## Database Schema After Fix

### Current (BAD):
```
oxorder
├── OXID, OXSHOPID, OXUSERID, ... (core columns)
├── STRIPEDELCOSTREFUNDED      ❌ Stripe-specific (DELETE)
├── STRIPEPAYCOSTREFUNDED      ❌ Stripe-specific (DELETE)
├── ...                        ❌ 10 more STRIPE columns (DELETE)

oxorderarticles
├── OXID, OXORDERID, ...       (core columns)
├── STRIPEQUANTITYREFUNDED     ❌ Stripe-specific (DELETE)
├── STRIPEAMOUNTREFUNDED       ❌ Stripe-specific (DELETE)

oxuser
├── OXID, OXUSERNAME, ...      (core columns)
├── STRIPECUSTOMERID           ❌ Stripe-specific (DELETE)
```

### After Fix (GOOD):
```
oxorder                        (untouched core table)
├── OXID, OXSHOPID, OXUSERID, OXTRANSID, OXPAID, OXTRANSSTATUS...

oe_payments_transaction        (existing - tracks ALL transactions)
├── OXORDERID → oxorder.OXID
├── OXCONTRACTID → oe_payments_contract.OXID
├── OXPROVIDERORDERID          (PaymentIntent ID: pi_...)
├── OXTRANSACTIONID            (Charge/Refund ID: ch_..., re_...)
├── OXTYPE                     ('payment', 'refund', 'authorization')
├── OXSTATUS                   ('pending', 'completed', 'failed')
├── OXAMOUNT                   (transaction amount)
├── OXCURRENCY
├── OXCREATED, OXUPDATED

oe_payments_order_state        (existing - payment state per order)
├── OXORDERID → oxorder.OXID
├── OXPAYMENTSTATE             ('pending', 'authorized', 'paid', 'refunded')
├── OXAUTHORIZEDAMOUNT
├── OXCAPTUREDAMOUNT
├── OXREFUNDEDAMOUNT           (total refunded - calculated or stored)

oe_payments_customer           (existing)
├── OXUSERID → oxuser.OXID
├── OXPAYMENTCUSTOMERID        (Stripe customer ID: cus_...)
```

### Key Insight: Transaction History Is Enough

Instead of storing detailed refund breakdowns per cost type:
```
❌ STRIPEDELCOSTREFUNDED = 5.00
❌ STRIPEPAYCOSTREFUNDED = 2.00
❌ STRIPEWRAPCOSTREFUNDED = 3.00
```

We store transaction history:
```
✅ Transaction: type=refund, amount=10.00, status=completed
```

If detailed breakdown is needed, store it in transaction metadata (JSON) or
calculate from the original order costs proportionally.

---

## Testing Strategy

### TDD Approach (RED → GREEN → REFACTOR)

```
┌─────────────────────────────────────────────────────────────────┐
│  TDD CYCLE                                                      │
│                                                                 │
│  1. RED   → Write failing test                                  │
│  2. GREEN → Write minimal code to pass                          │
│  3. REFACTOR → Clean up, ensure LSP/SOLID compliance            │
│                                                                 │
│  REPEAT for each test case                                      │
└─────────────────────────────────────────────────────────────────┘
```

### SOLID Principles Applied

- **S**ingle Responsibility: Each service does ONE thing
- **O**pen/Closed: Extend via interfaces, not modification
- **L**iskov Substitution: Repository interfaces allow mock injection
- **I**nterface Segregation: Small, focused interfaces
- **D**ependency Injection: All dependencies injected via constructor

### No Over-Engineering

- **Don't reinvent the wheel** - Use existing OXID/Doctrine migrations and helpers
- **Don't duplicate code** - Reuse existing repositories, extend rather than copy
- **Don't duplicate meanings** - One source of truth for each data point
- **Minimal changes** - Only modify what's necessary to fix the architecture
- **No premature abstraction** - Add abstraction only when pattern repeats 3+ times
- **No hypothetical features** - Implement what's needed NOW, not "might need later"

```
✗ BAD:  Create oe_payments_stripe_order_refund table with 10 columns
✓ GOOD: Use existing oe_payments_transaction to track refund history

✗ BAD:  Store refund amount in oxorder.STRIPEDELCOSTREFUNDED AND oe_payments_order_state
✓ GOOD: Calculate from oe_payments_transaction (single source of truth)

✗ BAD:  Create RefundCalculationService, RefundHistoryService, RefundSummaryService
✓ GOOD: Add getRefundedAmount() method to existing TransactionRepository
```

### Test Structure

```
tests/
├── Unit/Stripe/Core/
│   └── EventsCleanupTest.php              # Verify Events.php doesn't create tables
├── Unit/Stripe/Service/
│   └── RefundCalculationServiceTest.php   # Test refund calculation from transactions
├── Integration/Stripe/
│   └── TransactionHistoryTest.php         # Verify transaction history persistence
└── e2e/playwright/tests/admin/
    └── refund-functionality.spec.ts       # E2E refund workflow
```

### Phase 1: Unit Tests (TDD RED)

**Test 1: Events.php Cleanup Test**

```php
/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Core\Events
 * @group unit
 * @group events
 * @group tdd-red
 */
final class EventsCleanupTest extends TestCase
{
    /**
     * @test
     */
    public function eventsDoesNotAddStripeColumnsToOxorder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../src/Stripe/Core/Events.php');

        // After cleanup, these should NOT exist
        $this->assertStringNotContainsString('STRIPEDELCOSTREFUNDED', $source);
        $this->assertStringNotContainsString('STRIPEPAYCOSTREFUNDED', $source);
        // ... etc
    }

    /**
     * @test
     */
    public function eventsDoesNotCreateOscPaymentTables(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../src/Stripe/Core/Events.php');

        // Tables should come from migrations, not Events.php
        preg_match_all('/CREATE TABLE.*osc_payment/', $source, $matches);
        $this->assertEmpty($matches[0], 'Events.php should not CREATE osc_payment tables');
    }
}
```

**Test 2: Refund Calculation Service Test**

```php
/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\RefundCalculationService
 * @group unit
 * @group refund
 * @group tdd-red
 */
final class RefundCalculationServiceTest extends TestCase
{
    /**
     * @test
     * Liskov Substitution: Service accepts TransactionRepositoryInterface
     */
    public function calculatesRefundedAmountFromTransactionHistory(): void
    {
        // Arrange - mock repository returns transaction history
        $repository = $this->createMock(TransactionRepositoryInterface::class);
        $repository->method('findByOrderId')->willReturn([
            ['OXTYPE' => 'refund', 'OXSTATUS' => 'completed', 'OXAMOUNT' => 30.00],
            ['OXTYPE' => 'refund', 'OXSTATUS' => 'completed', 'OXAMOUNT' => 20.00],
        ]);

        // DI: Inject mock repository
        $service = new RefundCalculationService($repository);

        // Act
        $total = $service->getRefundedAmount('order_123');

        // Assert
        $this->assertEquals(50.00, $total);
    }

    /**
     * @test
     */
    public function ignoresPendingRefunds(): void
    {
        $repository = $this->createMock(TransactionRepositoryInterface::class);
        $repository->method('findByOrderId')->willReturn([
            ['OXTYPE' => 'refund', 'OXSTATUS' => 'completed', 'OXAMOUNT' => 30.00],
            ['OXTYPE' => 'refund', 'OXSTATUS' => 'pending', 'OXAMOUNT' => 20.00], // Should be ignored
        ]);

        $service = new RefundCalculationService($repository);
        $total = $service->getRefundedAmount('order_123');

        $this->assertEquals(30.00, $total); // Only completed refunds
    }
}
```

### Phase 2: Integration Tests

**Test 3: Transaction History Integration Test**

```php
/**
 * @covers \OxidSolutionCatalysts\Payments\Component\Repository\DoctrineTransactionRepository
 * @group integration
 * @group transaction
 */
final class TransactionHistoryIntegrationTest extends IntegrationTestCase
{
    /**
     * @test
     */
    public function recordsRefundTransactionToDatabase(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $repository = new DoctrineTransactionRepository($this->connection);

        // Act
        $repository->save(new Transaction(
            id: 'tx_refund_' . uniqid(),
            shopId: 1,
            orderId: $orderId,
            provider: 'stripe',
            type: 'refund',
            status: 'completed',
            amount: 50.00,
            currency: 'EUR'
        ));

        // Assert
        $transactions = $repository->findByOrderId($orderId);
        $this->assertCount(1, $transactions);
        $this->assertEquals('refund', $transactions[0]['OXTYPE']);
    }
}
```

### Phase 3: E2E Tests (Playwright)

**Test 4: Refund Functionality E2E**

```typescript
// tests/admin/refund-functionality.spec.ts
test.describe('Admin: Refund Functionality', () => {
  test('Refund amount calculated from transaction history', async ({ page }) => {
    // Navigate to admin orders
    const adminLogin = new AdminLoginPage(page);
    await adminLogin.login();

    const ordersPage = new AdminOrdersPage(page);
    await ordersPage.navigateToOrders();
    await ordersPage.selectFirstOrder();
    await ordersPage.clickStripeTab();

    // Verify refund amount displayed
    const refundAmount = await ordersPage.getRefundedAmount();
    expect(refundAmount).toBeGreaterThanOrEqual(0);
  });
});
```

### Test Execution Commands

```bash
# ═══════════════════════════════════════════════════════════════════════════════
# UNIT TESTS (no database required)
# ═══════════════════════════════════════════════════════════════════════════════

# Run Events cleanup tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --group events

# Run refund calculation tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --group refund

# ═══════════════════════════════════════════════════════════════════════════════
# INTEGRATION TESTS (requires database + OXID bootstrap)
# ═══════════════════════════════════════════════════════════════════════════════

docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --group transaction

# ═══════════════════════════════════════════════════════════════════════════════
# E2E TESTS (Playwright)
# ═══════════════════════════════════════════════════════════════════════════════

cd tests/e2e/playwright && npx playwright test tests/admin/refund-functionality.spec.ts

# ═══════════════════════════════════════════════════════════════════════════════
# FULL TEST SUITE (CI/CD)
# ═══════════════════════════════════════════════════════════════════════════════

./source/extensions/stripe/bin/pre-commit-check.sh
```

### Definition of Done

- [ ] All TDD RED tests written (failing)
- [ ] Implementation passes all tests (GREEN)
- [ ] Code refactored for SOLID/LSP compliance
- [ ] Integration tests pass with actual database
- [ ] Pre-commit-check.sh passes
- [ ] Move `todo/sprint-5-*.md` → `done/sprint-5-*.md`
- [ ] Create `done/sprint-5-REPORT.md`
- [ ] status.md updated

---

## Rollback Plan

If issues arise:
1. Keep Events.php changes commented out (don't delete yet)
2. Code should work with or without STRIPE* columns
3. Only remove columns from existing databases after verification

---

## Acceptance Criteria

- [ ] Events.php no longer adds STRIPE* columns to core tables
- [ ] Events.php no longer creates oe_payments_* tables (migrations handle this)
- [ ] OrderRefund service calculates refunds from `oe_payments_transaction`
- [x] Legacy oe_payments_webhook_log references removed (DONE)
- [ ] All tests pass
- [ ] Refund functionality works end-to-end

---

## Summary: Simplified Approach

**Before (over-engineered):**
- 13 STRIPE* columns in core tables
- Detailed refund breakdown per cost type
- Complex data model

**After (simple):**
- Zero columns added to core tables
- Transaction history in `oe_payments_transaction`
- Calculate totals on-the-fly when needed
- Store detailed breakdown in transaction metadata (JSON) if needed
