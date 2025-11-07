# PaymentWatch ↔ Payment Component Coupling

**Integration Architecture & Dependencies**

Version: 1.0.0
Date: 2025-11-11

---

## Overview

This document explains how **PaymentWatch** (the test helper module) integrates with and depends on the **Payment Component** (the production payment system).

### Key Relationship

```
┌─────────────────────────────────────────────────────────────┐
│                    PAYMENT COMPONENT                         │
│  (Production System - Handles Real Payments)                │
│                                                              │
│  ┌────────────────┐  ┌─────────────────┐  ┌──────────────┐ │
│  │   Contracts    │  │  Transactions   │  │   Orders     │ │
│  │                │  │                 │  │              │ │
│  │  - Create      │  │  - Authorize    │  │  - Create    │ │
│  │  - Commit      │  │  - Capture      │  │  - Update    │ │
│  │  - Fulfill     │  │  - Refund       │  │  - Complete  │ │
│  └────────┬───────┘  └────────┬────────┘  └──────┬───────┘ │
│           │                   │                   │         │
│           └───────────────────┼───────────────────┘         │
│                               │                             │
│                               ▼                             │
│                     ┌──────────────────┐                    │
│                     │    DATABASE      │                    │
│                     │                  │                    │
│                     │  Tables:         │                    │
│                     │  - osc_payment_  │                    │
│                     │    contract      │                    │
│                     │  - osc_payment_  │                    │
│                     │    transaction   │                    │
│                     │  - oxorder       │                    │
│                     └──────────┬───────┘                    │
└────────────────────────────────┼────────────────────────────┘
                                 │
                                 │ READ-ONLY ACCESS
                                 │ (via queries)
                                 ▼
┌─────────────────────────────────────────────────────────────┐
│                     PAYMENTWATCH                             │
│  (Test Helper - Verifies Payment States)                    │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  AssumptionController                                │  │
│  │                                                       │  │
│  │  POST /paymentwatch/assume                           │  │
│  │  {                                                    │  │
│  │    "assumption": {                                    │  │
│  │      "osc_payment_contract.OXSTATE": "committed"     │  │
│  │    }                                                  │  │
│  │  }                                                    │  │
│  └──────────────────┬───────────────────────────────────┘  │
│                     │                                       │
│                     ▼                                       │
│           ┌──────────────────┐                             │
│           │   QueryBuilder   │                             │
│           │                  │                             │
│           │  SELECT OXSTATE  │                             │
│           │  FROM osc_       │                             │
│           │    payment_      │                             │
│           │    contract      │                             │
│           │  WHERE ...       │                             │
│           └──────────────────┘                             │
└─────────────────────────────────────────────────────────────┘
```

---

## Integration Levels

### 1. Database-Level Coupling (READ-ONLY)

**Coupling Type:** Loose (Read-only queries)

**What PaymentWatch Reads:**

| Table | Columns Used | Purpose |
|-------|--------------|---------|
| `osc_payment_contract` | OXID, OXSTATE, OXORDERID, OXUSERID, OXBASKETAMOUNT | Contract state verification |
| `osc_payment_transaction` | OXID, OXSTATUS, OXAMOUNT, OXPROVIDERORDERID, OXTYPE | Transaction status checks |
| `oxorder` | OXID, OXTRANSSTATUS, OXPAID, OXTOTALORDERSUM, OXORDERNR | Order completion verification |

**Dependency:**
```php
// PaymentWatch depends on Payment Component's database schema
namespace OxidSolutionCatalysts\Payments\Watch\Service;

use Doctrine\DBAL\Connection;

class QueryBuilder
{
    public function __construct(
        private Connection $connection  // ← OXID's database connection
    ) {}

    public function execute(AssumptionRequest $request): AssumptionResponse
    {
        // Queries tables created by Payment Component
        $sql = "SELECT {$field} FROM {$table} WHERE {$condition}";
        // Example: SELECT OXSTATE FROM osc_payment_contract WHERE OXID = ?

        return $this->connection->fetchAssociative($sql, $params);
    }
}
```

**Important:** PaymentWatch does **NOT** write to these tables. It only reads current state.

---

### 2. Namespace Coupling (NONE)

**Coupling Type:** Independent

PaymentWatch and Payment Component use **separate namespaces**:

```php
// Payment Component (production code)
namespace OxidSolutionCatalysts\Payments\Component\...
namespace OxidSolutionCatalysts\Payments\Stripe\...

// PaymentWatch (test helper)
namespace OxidSolutionCatalysts\Payments\Watch\...
```

**No cross-dependencies:**
- PaymentWatch does NOT import Payment Component classes
- Payment Component does NOT import PaymentWatch classes
- They communicate only via **database state**

---

### 3. Domain Knowledge Coupling (MEDIUM)

**Coupling Type:** Shared Domain Understanding

PaymentWatch must understand Payment Component's **business logic**:

#### Contract Lifecycle

**Payment Component defines:**
```
DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
                         ↓
                      FAILED / EXPIRED
```

**PaymentWatch verifies:**
```javascript
// E2E test knows about contract states
await paymentWatch.waitForAssumption(
    'osc_payment_contract.OXSTATE',
    'committed',  // ← Must know valid states
    { whereClause: { 'osc_payment_contract.OXID': contractId } }
);
```

#### Transaction States

**Payment Component defines:**
```
pending → authorized → captured → completed
                ↓
             failed / refunded
```

**PaymentWatch verifies:**
```javascript
// Test knows about transaction lifecycle
await paymentWatch.assertAssumption(
    'osc_payment_transaction.OXSTATUS',
    'completed',  // ← Must know valid statuses
    { whereClause: { 'osc_payment_transaction.OXID': txnId } }
);
```

---

## Data Flow Examples

### Example 1: Payment Authorization Flow

**Step-by-step showing Payment Component → PaymentWatch interaction:**

```
┌─────────────────────────────────────────────────────────────┐
│ 1. USER INITIATES PAYMENT                                   │
└─────────────────────────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. PAYMENT COMPONENT                                         │
│                                                              │
│    ContractService::createContract()                        │
│    ├─ INSERT INTO osc_payment_contract                      │
│    │  (OXID, OXSTATE, OXUSERID, OXBASKETAMOUNT)            │
│    │  VALUES ('abc123', 'pending', 'user-1', 99.99)        │
│    └─ Returns contract ID: abc123                           │
└─────────────────────────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. E2E TEST (Playwright/Cypress)                            │
│                                                              │
│    // Verify contract created                               │
│    const result = await fetch('/paymentwatch/assume', {     │
│      body: JSON.stringify({                                 │
│        assumption: {                                         │
│          "osc_payment_contract.OXSTATE": "pending",         │
│          "where": {                                          │
│            "osc_payment_contract.OXID": "abc123"            │
│          }                                                   │
│        }                                                     │
│      })                                                      │
│    });                                                       │
│                                                              │
│    expect(result.assumption).toBe(true); ✅                 │
└─────────────────────────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. PAYMENTWATCH                                              │
│                                                              │
│    QueryBuilder::execute()                                  │
│    ├─ SELECT OXSTATE                                        │
│    │  FROM osc_payment_contract                             │
│    │  WHERE OXID = 'abc123'                                 │
│    ├─ Result: 'pending'                                     │
│    └─ Compare: 'pending' == 'pending' → TRUE ✅             │
└─────────────────────────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. STRIPE AUTHORIZATION COMPLETES                           │
│                                                              │
│    PaymentService::authorizePayment()                       │
│    ├─ UPDATE osc_payment_contract                           │
│    │  SET OXSTATE = 'ready_to_commit'                       │
│    │  WHERE OXID = 'abc123'                                 │
│    └─ State changed: pending → ready_to_commit              │
└─────────────────────────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. E2E TEST POLLS FOR STATE CHANGE                          │
│                                                              │
│    // Poll until state changes (max 10 seconds)             │
│    await paymentWatch.waitForAssumption(                    │
│      'osc_payment_contract.OXSTATE',                        │
│      'ready_to_commit',                                     │
│      { whereClause: { ... }, timeout: 10000 }               │
│    );                                                        │
│                                                              │
│    // Assumption passes after ~2 seconds ✅                 │
└─────────────────────────────────────────────────────────────┘
```

---

### Example 2: Order Creation Verification

**Payment Component creates order, PaymentWatch verifies:**

```typescript
// E2E Test
describe('Order Creation', () => {
  test('contract committed when order created', async () => {
    // 1. Payment Component: Create contract
    const contractId = await triggerPayment(99.99);

    // 2. Payment Component: Fulfill all conditions
    await fulfillConditions(contractId);

    // 3. Payment Component: Creates order automatically
    //    → INSERT INTO oxorder (OXID, OXUSERID, OXTOTALORDERSUM, OXTRANSSTATUS)
    //    → UPDATE osc_payment_contract SET OXSTATE='committed', OXORDERID='order-123'

    // 4. PaymentWatch: Verify contract committed
    await paymentWatch.assertAssumption(
      'osc_payment_contract.OXSTATE',
      'committed',
      { whereClause: { 'osc_payment_contract.OXID': contractId } }
    );

    // 5. PaymentWatch: Verify order ID linked
    await paymentWatch.assertAssumption(
      'osc_payment_contract.OXORDERID',
      null,
      {
        operator: 'IS NOT NULL',
        whereClause: { 'osc_payment_contract.OXID': contractId }
      }
    );

    // 6. PaymentWatch: Verify order exists
    const orderIdResult = await getOrderIdFromContract(contractId);
    await paymentWatch.assertAssumption(
      'oxorder.OXTRANSSTATUS',
      'OK',
      { whereClause: { 'oxorder.OXID': orderIdResult } }
    );
  });
});
```

---

## Coupling Boundaries

### What PaymentWatch CAN Do

✅ **Read database state**
```php
// Query any table Payment Component uses
SELECT OXSTATE FROM osc_payment_contract WHERE OXID = ?
SELECT OXSTATUS FROM osc_payment_transaction WHERE OXID = ?
SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = ?
```

✅ **Verify data consistency**
```javascript
// Check relationships
await paymentWatch.assertAssumption(
  'osc_payment_transaction.OXCONTRACTID',
  contractId,
  { whereClause: { 'osc_payment_transaction.OXID': txnId } }
);
```

✅ **Poll for state changes**
```javascript
// Wait for async operations to complete
await paymentWatch.waitForAssumption(
  'osc_payment_contract.OXSTATE',
  'fulfilled',
  { timeout: 15000 }
);
```

### What PaymentWatch CANNOT Do

❌ **Modify Payment Component state**
```php
// PaymentWatch does NOT do this:
// UPDATE osc_payment_contract SET OXSTATE = 'committed'  ❌
// INSERT INTO osc_payment_transaction (...)              ❌
// DELETE FROM oxorder WHERE OXID = ?                     ❌
```

❌ **Call Payment Component methods**
```php
// PaymentWatch does NOT do this:
// $contractService->commitContract($contractId);  ❌
// $paymentService->capturePayment($txnId);        ❌
```

❌ **Trigger business logic**
```php
// Tests trigger logic, PaymentWatch only verifies:
// Test Framework → User Action → Payment Component → Database
//                                                        ↓
//                                             PaymentWatch (reads)
```

---

## Dependency Direction

```
┌─────────────────────────────────────────────────────────────┐
│                    PAYMENT COMPONENT                         │
│                   (Business Logic)                           │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │ Contract     │  │ Transaction  │  │ Order        │     │
│  │ Service      │  │ Service      │  │ Service      │     │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘     │
│         │                  │                  │             │
│         └──────────────────┼──────────────────┘             │
│                            ▼                                │
│                   ┌─────────────────┐                       │
│                   │    DATABASE     │                       │
│                   │                 │                       │
│                   │  - Contracts    │                       │
│                   │  - Transactions │                       │
│                   │  - Orders       │                       │
│                   └────────┬────────┘                       │
└────────────────────────────┼────────────────────────────────┘
                             │
                             │ One-Way Dependency
                             │ (Read-Only)
                             ▼
┌─────────────────────────────────────────────────────────────┐
│                      PAYMENTWATCH                            │
│                    (Test Verification)                       │
│                                                              │
│  Has NO knowledge of:                                        │
│  - Contract Service implementation                           │
│  - Transaction Service implementation                        │
│  - Order Service implementation                              │
│                                                              │
│  Only knows:                                                 │
│  - Database schema (table/column names)                     │
│  - Valid state values (domain knowledge)                    │
│  - Business rules (what states are valid)                   │
└─────────────────────────────────────────────────────────────┘
```

**Key Point:** Payment Component is **unaware** that PaymentWatch exists. This is **good design** (one-way dependency).

---

## Shared Dependencies

### 1. Database Schema

**Defined by:** Payment Component (via migrations)

**Used by:**
- Payment Component (read/write)
- PaymentWatch (read-only)

**Migration Example:**
```php
// Payment Component migration
class CreatePaymentContractTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('osc_payment_contract', ['id' => false, 'primary_key' => 'OXID'])
            ->addColumn('OXID', 'string', ['limit' => 32])
            ->addColumn('OXSTATE', 'string', ['limit' => 50])  // ← PaymentWatch reads this
            ->addColumn('OXORDERID', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('OXUSERID', 'string', ['limit' => 32])
            ->addColumn('OXBASKETAMOUNT', 'decimal', ['precision' => 10, 'scale' => 2])
            ->addTimestamps()
            ->create();
    }
}
```

**PaymentWatch assumes this schema exists:**
```php
// PaymentWatch query
SELECT OXSTATE, OXORDERID
FROM osc_payment_contract
WHERE OXID = ?
```

### 2. Domain Values

**Defined by:** Payment Component (business logic)

**Known by:** PaymentWatch (test knowledge)

**Contract States:**
```php
// Payment Component (ContractStateEnum.php)
enum ContractState: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case READY_TO_COMMIT = 'ready_to_commit';
    case COMMITTED = 'committed';
    case FULFILLED = 'fulfilled';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
}
```

**PaymentWatch tests use these values:**
```javascript
// E2E test must know valid values
await paymentWatch.assertAssumption(
  'osc_payment_contract.OXSTATE',
  'committed',  // ← Must match ContractState::COMMITTED->value
  { whereClause: { ... } }
);
```

### 3. OXID Framework

Both modules depend on OXID eShop framework:

```php
// Both use OXID's database connection
use OxidEsales\Eshop\Core\DatabaseProvider;

$connection = DatabaseProvider::getDb()->getConnection();
```

---

## Versioning & Compatibility

### Schema Changes Impact

**If Payment Component changes schema:**

| Change Type | Impact on PaymentWatch | Action Required |
|-------------|------------------------|-----------------|
| Add column | None (PaymentWatch doesn't query it) | None |
| Rename column | **BREAKING** | Update PaymentWatch queries |
| Change column type | **BREAKING** (if affects comparisons) | Update operator logic |
| Add table | None (unless tests need it) | Optional: Add new assumptions |
| Drop table | **BREAKING** (if PaymentWatch queries it) | Remove affected tests |

**Example Breaking Change:**

```php
// Payment Component v4.0 → v5.0
// Migration: Rename OXSTATE to OXCONTRACTSTATE

// PaymentWatch BREAKS:
SELECT OXSTATE FROM osc_payment_contract  // ❌ Column not found

// Fix required:
SELECT OXCONTRACTSTATE FROM osc_payment_contract  // ✅
```

### State Values Changes

**If Payment Component changes state enum:**

```php
// Payment Component v4.0
enum ContractState: string {
    case COMMITTED = 'committed';
}

// Payment Component v5.0 (breaking change)
enum ContractState: string {
    case COMMITTED = 'contract_committed';  // ← Value changed!
}
```

**PaymentWatch tests BREAK:**
```javascript
// Old test
await paymentWatch.assertAssumption(
  'osc_payment_contract.OXSTATE',
  'committed',  // ❌ No longer matches database value
  { ... }
);

// Fixed test
await paymentWatch.assertAssumption(
  'osc_payment_contract.OXSTATE',
  'contract_committed',  // ✅ Updated to match new value
  { ... }
);
```

---

## Integration Points Summary

### Database Layer (Primary Integration)

```
Payment Component              PaymentWatch
      │                             │
      │ WRITE                       │ READ
      ▼                             ▼
┌──────────────────────────────────────────┐
│          DATABASE (MySQL)                │
│                                          │
│  Tables:                                 │
│  - osc_payment_contract                 │
│  - osc_payment_transaction              │
│  - oxorder                              │
└──────────────────────────────────────────┘
```

### Test Execution Layer

```
┌─────────────────────────────────────────────────────────────┐
│                   E2E TEST FRAMEWORK                         │
│            (Playwright / Cypress / Jest)                     │
│                                                              │
│  ┌─────────────────────┐         ┌────────────────────────┐ │
│  │  Test Actions       │         │  PaymentWatch Client   │ │
│  │                     │         │                        │ │
│  │  - Click buttons    │         │  - await assume(...)   │ │
│  │  - Fill forms       │         │  - await waitFor(...)  │ │
│  │  - Trigger webhooks │         │  - assert state        │ │
│  └─────────┬───────────┘         └───────────┬────────────┘ │
│            │                                  │              │
│            │                                  │              │
└────────────┼──────────────────────────────────┼──────────────┘
             │                                  │
             ▼                                  ▼
┌─────────────────────────┐      ┌─────────────────────────────┐
│   PAYMENT COMPONENT     │      │      PAYMENTWATCH           │
│   (Production Code)     │      │      (Test Helper)          │
│                         │      │                             │
│   Changes DB State      │      │   Reads DB State            │
└─────────────────────────┘      └─────────────────────────────┘
```

---

## Decoupling Strategies

### 1. Abstract Database Schema (Future)

**Current:** PaymentWatch knows exact table/column names

**Better:** Introduce abstraction layer

```php
// Abstract schema config
interface PaymentSchemaInterface
{
    public function getContractTable(): string;
    public function getContractStateColumn(): string;
    public function getTransactionTable(): string;
    // ...
}

// Payment Component provides implementation
class PaymentSchema implements PaymentSchemaInterface
{
    public function getContractTable(): string
    {
        return 'osc_payment_contract';
    }

    public function getContractStateColumn(): string
    {
        return 'OXSTATE';
    }
}

// PaymentWatch uses abstraction
class QueryBuilder
{
    public function __construct(
        private Connection $connection,
        private PaymentSchemaInterface $schema  // ← Inject schema
    ) {}

    public function execute(AssumptionRequest $request): AssumptionResponse
    {
        $table = $this->schema->getContractTable();  // ← No hardcoded names
        $column = $this->schema->getContractStateColumn();

        $sql = "SELECT {$column} FROM {$table} WHERE ...";
        // ...
    }
}
```

**Benefits:**
- Schema changes don't break PaymentWatch
- Easier to support multiple Payment Component versions
- Clear contract between systems

### 2. Shared Constants (Recommended)

**Current:** Tests hardcode state values

**Better:** Share constant definitions

```php
// In Payment Component
namespace OxidSolutionCatalysts\Payments\Component\Contract;

final class ContractStates
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const COMMITTED = 'committed';
    public const FULFILLED = 'fulfilled';
    // ...
}

// PaymentWatch can import and use
use OxidSolutionCatalysts\Payments\Component\Contract\ContractStates;

// In tests
await paymentWatch.assertAssumption(
  'osc_payment_contract.OXSTATE',
  ContractStates::COMMITTED,  // ← Type-safe, no magic strings
  { ... }
);
```

**Benefits:**
- Compiler/IDE catches invalid states
- Refactoring updates all references
- Single source of truth

### 3. API-Based Integration (Alternative Approach)

**Current:** Direct database reads

**Alternative:** Payment Component exposes API

```php
// Payment Component provides status endpoint
GET /api/payment/contract/{id}/status
Response: { "state": "committed", "orderId": "order-123" }

// PaymentWatch queries API instead of database
class ApiQueryBuilder
{
    public function getContractState(string $contractId): string
    {
        $response = $this->httpClient->get("/api/payment/contract/{$contractId}/status");
        return $response['state'];
    }
}
```

**Benefits:**
- No direct database coupling
- Payment Component controls exposed data
- Easier to version API independently

**Drawbacks:**
- Additional complexity
- Performance overhead (HTTP vs direct SQL)
- Still couples to API contract

---

## Best Practices

### 1. Keep PaymentWatch Read-Only

✅ **Good:**
```php
// PaymentWatch only reads
SELECT OXSTATE FROM osc_payment_contract WHERE OXID = ?
```

❌ **Bad:**
```php
// PaymentWatch should NEVER write
UPDATE osc_payment_contract SET OXSTATE = 'committed'  // ❌
```

### 2. Test Fixture Management

**Who creates test data?**

✅ **Good approach:**
```typescript
// E2E test creates data via Payment Component
const contractId = await paymentComponent.createContract({ ... });

// PaymentWatch only verifies
await paymentWatch.assertAssumption(...);
```

❌ **Bad approach:**
```typescript
// Don't insert test data directly into Payment Component tables
await db.insert('osc_payment_contract', { ... });  // ❌ Bypasses business logic
```

### 3. Version Compatibility Matrix

**Maintain compatibility table:**

| Payment Component | PaymentWatch | Compatible | Notes |
|-------------------|--------------|------------|-------|
| v4.0.x | v1.0.x | ✅ | Initial release |
| v4.1.x | v1.0.x | ✅ | Backward compatible |
| v5.0.x | v1.0.x | ❌ | Schema changed |
| v5.0.x | v2.0.x | ✅ | Updated for v5 schema |

### 4. Documentation Sync

**When Payment Component changes:**

1. Update schema documentation
2. Update state enum documentation
3. Update PaymentWatch queries (if needed)
4. Update E2E test examples
5. Update integration test expectations

---

## Real-World Usage Example

### Complete E2E Test with Coupling Points Highlighted

```typescript
import { test } from '@playwright/test';
import { PaymentWatchClient } from './helpers/paymentWatch';

test('Stripe payment flow completes successfully', async ({ page, request }) => {
  const paymentWatch = new PaymentWatchClient(request, API_KEY);

  // ═══════════════════════════════════════════════════════════
  // STEP 1: User action triggers Payment Component
  // ═══════════════════════════════════════════════════════════
  await page.goto('/checkout');
  await page.click('#payment-method-stripe');
  await page.click('#place-order');

  // Payment Component creates contract:
  // INSERT INTO osc_payment_contract (OXID, OXSTATE, ...)
  // VALUES ('contract-123', 'pending', ...)

  const contractId = await extractContractIdFromUrl(page);

  // ═══════════════════════════════════════════════════════════
  // COUPLING POINT 1: PaymentWatch reads database
  // Knows: table name (osc_payment_contract)
  // Knows: column name (OXSTATE)
  // Knows: valid value ('pending')
  // ═══════════════════════════════════════════════════════════
  await paymentWatch.assertAssumption(
    'osc_payment_contract.OXSTATE',
    'pending',
    { whereClause: { 'osc_payment_contract.OXID': contractId } }
  );

  // ═══════════════════════════════════════════════════════════
  // STEP 2: Complete Stripe payment (triggers Payment Component)
  // ═══════════════════════════════════════════════════════════
  await completeStripePayment(page);

  // Payment Component updates contract:
  // UPDATE osc_payment_contract
  // SET OXSTATE = 'ready_to_commit'
  // WHERE OXID = 'contract-123'

  // ═══════════════════════════════════════════════════════════
  // COUPLING POINT 2: PaymentWatch polls for state change
  // Knows: expected next state ('ready_to_commit')
  // Knows: state transition timing (async, need to poll)
  // ═══════════════════════════════════════════════════════════
  await paymentWatch.waitForAssumption(
    'osc_payment_contract.OXSTATE',
    'ready_to_commit',
    {
      whereClause: { 'osc_payment_contract.OXID': contractId },
      timeout: 15000  // Understands this is async operation
    }
  );

  // ═══════════════════════════════════════════════════════════
  // STEP 3: Payment Component creates order automatically
  // ═══════════════════════════════════════════════════════════

  // Payment Component:
  // 1. INSERT INTO oxorder (OXID, OXUSERID, ...)
  // 2. UPDATE osc_payment_contract
  //    SET OXSTATE = 'committed', OXORDERID = 'order-456'

  // ═══════════════════════════════════════════════════════════
  // COUPLING POINT 3: PaymentWatch verifies relationships
  // Knows: contract links to order via OXORDERID
  // Knows: order table structure
  // ═══════════════════════════════════════════════════════════
  await paymentWatch.assertAssumption(
    'osc_payment_contract.OXSTATE',
    'committed',
    { whereClause: { 'osc_payment_contract.OXID': contractId } }
  );

  await paymentWatch.assertAssumption(
    'osc_payment_contract.OXORDERID',
    null,
    {
      operator: 'IS NOT NULL',
      whereClause: { 'osc_payment_contract.OXID': contractId }
    }
  );

  // ═══════════════════════════════════════════════════════════
  // COUPLING POINT 4: PaymentWatch verifies order state
  // Knows: order status values ('OK')
  // Knows: order table name (oxorder)
  // ═══════════════════════════════════════════════════════════
  const orderId = await getOrderIdFromContract(contractId);

  await paymentWatch.assertAssumption(
    'oxorder.OXTRANSSTATUS',
    'OK',
    { whereClause: { 'oxorder.OXID': orderId } }
  );

  console.log('✅ Payment flow completed successfully');
});
```

---

## Summary

### Coupling Characteristics

| Aspect | Level | Type | Impact |
|--------|-------|------|--------|
| **Code Coupling** | ❌ None | Independent namespaces | Low |
| **Database Coupling** | ✅ High | Read-only queries | Medium |
| **Domain Coupling** | ✅ High | Shared business knowledge | High |
| **API Coupling** | ❌ None | No direct API calls | Low |
| **Deployment Coupling** | ✅ Medium | Same codebase/module | Medium |

### Key Takeaways

1. **One-Way Dependency**: PaymentWatch depends on Payment Component, NOT vice versa
2. **Read-Only**: PaymentWatch never modifies Payment Component state
3. **Database-Level**: Primary integration is via shared database tables
4. **Domain Knowledge**: Tests must understand business logic (states, transitions)
5. **Schema-Dependent**: Breaking changes in schema require PaymentWatch updates
6. **Test-Only**: PaymentWatch exists only for testing, never in production use

### Maintenance Responsibilities

**Payment Component team must:**
- Document schema changes in migrations
- Document state value changes in enums
- Update PaymentWatch documentation when breaking changes occur
- Provide migration guides for major version upgrades

**PaymentWatch tests must:**
- Stay synchronized with Payment Component domain model
- Update queries when schema changes
- Update expected values when enums change
- Test against current Payment Component version

---

## Related Documentation

- **PaymentWatch API:** [README.md](README.md)
- **Implementation Guide:** [01-implementation-guide.md](01-implementation-guide.md)
- **TDD Guide:** [tdd/INDEX.md](tdd/INDEX.md)
- **Integration Tests:** [tdd/05-phase5-6-integration.md](tdd/05-phase5-6-integration.md)
- **Payment Component:** [../payment-component/README.md](../payment-component/README.md)

---

**PaymentWatch is tightly coupled to Payment Component's database schema and domain model, but remains architecturally independent through read-only access.** 🔗
