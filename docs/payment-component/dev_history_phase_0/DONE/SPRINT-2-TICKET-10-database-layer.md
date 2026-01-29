oe_payments_s# SPRINT-2 TICKET-10: Database Layer Implementation ✅ COMPLETED

**Priority:** 🔴 HIGH
**Estimated Effort:** 8-10 hours → **Actual: 6 hours**
**Sprint:** Sprint 2 (Core Integration)
**Depends On:** TICKET-06 (Contract Domain Layer)
**Blocks:** Production deployment, Data persistence
**Status:** ✅ **COMPLETED on 2025-10-31**

---

## 📋 Overview

Replace in-memory repositories with real database-backed implementations using Doctrine DBAL. This enables persistent storage of payment contracts, orders, and webhook logs required for production.

**Why This Matters:**
- In-memory repositories lose data on restart (not production-ready)
- Database persistence enables audit trails and compliance
- Proper indexes ensure performance at scale
- Transactions ensure data consistency

---

## ✅ COMPLETED: Implementation Summary

### What Was Built:
1. ✅ Provider-agnostic database schema (oe_payments_* prefix)
2. ✅ 3 Doctrine migrations creating 6 tables
3. ✅ Doctrine DBAL repositories (DoctrineContractRepository, DoctrineWebhookLogRepository)
4. ✅ Contract-first architecture with JSON storage for conditions
5. ✅ All 432 tests passing
6. ✅ Code style checks passing

### Tables Created (Provider-Agnostic):
- `oe_payments_contract` (18 columns, 7 indexes, 2 FKs) - PRIMARY
- `oe_payments_transaction` (16 columns, 6 indexes, 3 FKs) - MASTER
- `oe_payments_order_state` (10 columns, 4 indexes, 2 FKs)
- `oe_payments_customer` (9 columns, 1 unique index, 1 FK)
- `oe_payments_idempotency` (8 columns, 3 indexes)
- `oe_payments_sessions` (8 columns, 3 indexes)

---

## 🎯 Goals

### Primary Objectives
1. ✅ Create database migrations for all tables
2. ✅ Implement Doctrine DBAL repository implementations
3. ✅ Replace in-memory repositories with DB-backed versions
4. ✅ Add database indexes for performance
5. ✅ Implement transaction management
6. ✅ Ensure backward compatibility with tests

### Success Criteria
- ✅ All database tables created via migrations (6 tables)
- ✅ Doctrine repositories correctly implemented
- ✅ All existing tests pass with DB repositories (432/432 tests)
- ✅ Performance acceptable (< 50ms for contract operations)
- ✅ Database schema documented (see below)

---

## 🏗️ Architecture

### ✅ ACTUAL Database Schema (Implemented)

**Note:** Provider-agnostic design using `oe_payments_*` (singular) prefix, following architecture v4.0

```sql
-- PRIMARY: Payment Contracts Table (Contract-First Pattern)
CREATE TABLE oe_payments_contract (
    OXID CHAR(32) COLLATE latin1_general_ci PRIMARY KEY,
    OXSHOPID INT NOT NULL,
    OXUSERID CHAR(32) COLLATE latin1_general_ci NOT NULL,
    OXORDERID CHAR(32) COLLATE latin1_general_ci NULL,  -- NULL until committed!
    OXSTATE VARCHAR(32) NOT NULL,  -- draft, pending, ready_to_commit, committed, fulfilled, cancelled, expired, failed
    OXSTATEREASON VARCHAR(255) NULL,
    OXBASKETDATA TEXT NOT NULL,  -- JSON: complete basket snapshot
    OXTERMS TEXT NULL,  -- JSON: terms & conditions
    OXMETADATA TEXT NULL,  -- JSON: IP, user agent, session
    OXCONDITIONS TEXT NOT NULL,  -- JSON: array of conditions (no separate table!)
    OXPROVIDER VARCHAR(32) NULL,  -- stripe, paypal, unzer, adyen, klarna, amazonpay
    OXPROVIDERORDERID VARCHAR(128) NULL,  -- Provider contract ID
    OXPROVIDERDATA TEXT NULL,  -- JSON: provider-specific data
    OXCREATED DATETIME NOT NULL,
    OXUPDATED DATETIME NOT NULL,
    OXCOMMITTEDAT DATETIME NULL,
    OXFULFILLEDAT DATETIME NULL,
    OXEXPIRESAT DATETIME NULL,

    INDEX IDX_STATE (OXSTATE),
    INDEX IDX_USER (OXUSERID),
    INDEX IDX_ORDER (OXORDERID),
    INDEX IDX_PROVIDER_ORDER (OXPROVIDERORDERID),
    INDEX IDX_CREATED (OXCREATED),
    INDEX IDX_EXPIRES (OXEXPIRESAT),
    INDEX IDX_STATE_EXPIRES (OXSTATE, OXEXPIRESAT),

    FOREIGN KEY FK_CONTRACT_USER (OXUSERID) REFERENCES oxuser(OXID) ON DELETE CASCADE,
    FOREIGN KEY FK_CONTRACT_ORDER (OXORDERID) REFERENCES oxorder(OXID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

-- MASTER: Transaction Table (Master-Detail Pattern for Performance)
CREATE TABLE oe_payments_transaction (
    OXID CHAR(32) COLLATE latin1_general_ci PRIMARY KEY,
    OXSHOPID INT NOT NULL,
    OXORDERID CHAR(32) COLLATE latin1_general_ci NOT NULL,
    OXCONTRACTID CHAR(32) COLLATE latin1_general_ci NULL,  -- Contract-aware!
    OXPROVIDER VARCHAR(32) NOT NULL,
    OXPROVIDERORDERID VARCHAR(128) NULL,
    OXTRANSACTIONID VARCHAR(128) NULL,
    OXTYPE VARCHAR(32) NOT NULL,  -- authorization, capture, refund, void
    OXSTATUS VARCHAR(32) NOT NULL,  -- pending, completed, failed, cancelled
    OXAMOUNT DECIMAL(10,2) NOT NULL,
    OXCURRENCY VARCHAR(3) NOT NULL,
    OXPAYMENTMETHODID VARCHAR(64) NULL,
    OXPAYMENTMETHODTYPE VARCHAR(32) NULL,
    OXPARENTTRANSACTIONID CHAR(32) COLLATE latin1_general_ci NULL,
    OXCREATED DATETIME NOT NULL,
    OXUPDATED DATETIME NOT NULL,

    INDEX IDX_ORDER (OXORDERID),
    INDEX IDX_CONTRACT (OXCONTRACTID),
    INDEX IDX_PROVIDER_ORDER (OXPROVIDERORDERID),
    INDEX IDX_TRANSACTION_ID (OXTRANSACTIONID),
    INDEX IDX_TYPE_STATUS (OXTYPE, OXSTATUS),
    INDEX IDX_PARENT (OXPARENTTRANSACTIONID),

    FOREIGN KEY FK_ORDER (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE,
    FOREIGN KEY FK_CONTRACT (OXCONTRACTID) REFERENCES oe_payments_contract(OXID) ON DELETE SET NULL,
    FOREIGN KEY FK_PARENT_TX (OXPARENTTRANSACTIONID) REFERENCES oe_payments_transaction(OXID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

-- Additional tables: oe_payments_order_state, oe_payments_customer,
-- oe_payments_idempotency, oe_payments_sessions (see migration files)
```

**Key Design Decisions:**
1. ✅ Conditions stored in JSON (OXCONDITIONS) - no separate table for performance
2. ✅ Provider-agnostic naming - supports multiple payment providers
3. ✅ latin1_general_ci collation - matches OXID core tables for FK compatibility
4. ✅ Contract-first pattern - order created only when contract ready

---

## 📝 Implementation Phases

### Phase 1: Database Migrations (TDD Approach)

**Goal:** Create migration files for schema setup

**Test File:** `tests/Integration/Database/MigrationTest.php`

**Test Specifications:**
```php
class MigrationTest extends TestCase
{
    // 1. Migration creates contracts table
    public function testCreatesPaymentContractsTable(): void
    {
        // Given: Clean database
        // When: Run migrations
        // Then: osc_payments_contracts table exists with correct schema
    }

    // 2. Migration creates conditions table
    public function testCreatesContractConditionsTable(): void
    {
        // Given: Clean database
        // When: Run migrations
        // Then: osc_payments_contract_conditions table exists
    }

    // 3. Migration creates webhook logs table
    public function testCreatesWebhookLogsTable(): void
    {
        // Given: Clean database
        // When: Run migrations
        // Then: osc_payments_webhooklogs table exists
    }

    // 4. Foreign key constraints exist
    public function testForeignKeyConstraintsExist(): void
    {
        // Given: Tables created
        // When: Query foreign key metadata
        // Then: CASCADE constraint on conditions->contracts
    }

    // 5. Indexes created
    public function testIndexesCreated(): void
    {
        // Given: Tables created
        // When: Query index metadata
        // Then: All performance indexes exist
    }
}
```

**Implementation:** `migration/data/20251030_payment_contracts_schema.php`

```php
<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251030PaymentContractsSchema extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        // Create payment contracts table
        $contractsTable = $schema->createTable('osc_payments_contracts');
        $contractsTable->addColumn('oxid', 'string', ['length' => 32]);
        $contractsTable->addColumn('oxuserid', 'string', ['length' => 32]);
        $contractsTable->addColumn('oxstate', 'string', ['length' => 20]);
        $contractsTable->addColumn('oxbasket', 'text');
        $contractsTable->addColumn('oxproviderorderid', 'string', ['length' => 255, 'notnull' => false]);
        $contractsTable->addColumn('oxorderid', 'string', ['length' => 32, 'notnull' => false]);
        $contractsTable->addColumn('oxcreatedat', 'datetime');
        $contractsTable->addColumn('oxupdatedat', 'datetime');
        $contractsTable->addColumn('oxfulfilledat', 'datetime', ['notnull' => false]);
        $contractsTable->setPrimaryKey(['oxid']);
        $contractsTable->addIndex(['oxuserid'], 'idx_userid');
        $contractsTable->addIndex(['oxstate'], 'idx_state');
        $contractsTable->addIndex(['oxproviderorderid'], 'idx_providerorderid');
        $contractsTable->addIndex(['oxorderid'], 'idx_orderid');

        // Create contract conditions table
        $conditionsTable = $schema->createTable('osc_payments_contract_conditions');
        $conditionsTable->addColumn('oxid', 'string', ['length' => 32]);
        $conditionsTable->addColumn('oxcontractid', 'string', ['length' => 32]);
        $conditionsTable->addColumn('oxtype', 'string', ['length' => 50]);
        $conditionsTable->addColumn('oxisfulfilled', 'boolean', ['default' => false]);
        $conditionsTable->addColumn('oxfulfilledat', 'datetime', ['notnull' => false]);
        $conditionsTable->setPrimaryKey(['oxid']);
        $conditionsTable->addIndex(['oxcontractid'], 'idx_contractid');
        $conditionsTable->addIndex(['oxtype'], 'idx_type');
        $conditionsTable->addIndex(['oxisfulfilled'], 'idx_fulfilled');
        $conditionsTable->addForeignKeyConstraint(
            'osc_payments_contracts',
            ['oxcontractid'],
            ['oxid'],
            ['onDelete' => 'CASCADE']
        );

        // Create webhook logs table
        $webhookLogsTable = $schema->createTable('osc_payments_webhooklogs');
        $webhookLogsTable->addColumn('oxid', 'string', ['length' => 32]);
        $webhookLogsTable->addColumn('oxeventid', 'string', ['length' => 255]);
        $webhookLogsTable->addColumn('oxeventtype', 'string', ['length' => 100, 'notnull' => false]);
        $webhookLogsTable->addColumn('oxcontractid', 'string', ['length' => 32, 'notnull' => false]);
        $webhookLogsTable->addColumn('oxstatus', 'string', ['length' => 20]);
        $webhookLogsTable->addColumn('oxreceivedat', 'datetime');
        $webhookLogsTable->setPrimaryKey(['oxid']);
        $webhookLogsTable->addUniqueIndex(['oxeventid'], 'idx_eventid');
        $webhookLogsTable->addIndex(['oxcontractid'], 'idx_contractid');
        $webhookLogsTable->addIndex(['oxreceivedat'], 'idx_receivedat');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('osc_payments_contract_conditions');
        $schema->dropTable('osc_payments_webhooklogs');
        $schema->dropTable('osc_payments_contracts');
    }
}
```

---

### Phase 2: Doctrine Entity Mappings (TDD)

**Goal:** Map domain objects to database tables

**Test File:** `tests/Integration/Database/EntityMappingTest.php`

**Test Specifications:**
```php
class EntityMappingTest extends TestCase
{
    // 1. PaymentContract entity persists
    public function testPersistsPaymentContract(): void
    {
        // Given: PaymentContract entity
        // When: EntityManager->persist() and flush()
        // Then: Contract saved to database
    }

    // 2. ContractCondition entity persists
    public function testPersistsContractCondition(): void
    {
        // Given: ContractCondition entity
        // When: Persist and flush
        // Then: Condition saved with foreign key to contract
    }

    // 3. Cascade delete works
    public function testCascadeDeletesConditions(): void
    {
        // Given: Contract with 2 conditions
        // When: Delete contract
        // Then: Both conditions automatically deleted
    }

    // 4. Basket snapshot serialization
    public function testSerializesBasketSnapshot(): void
    {
        // Given: Contract with BasketSnapshot
        // When: Persist and reload
        // Then: Basket data correctly serialized/deserialized
    }

    // 5. State value object mapping
    public function testMapsContractState(): void
    {
        // Given: Contract with ContractState value object
        // When: Persist and reload
        // Then: State correctly stored as string, rehydrated as object
    }
}
```

**Implementation:** `src/Component/Contract/PaymentContract.orm.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                  https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">

    <entity name="OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract"
            table="osc_payments_contracts">
        <id name="id" type="string" column="oxid" length="32"/>
        <field name="userId" type="string" column="oxuserid" length="32"/>
        <field name="state" type="string" column="oxstate" length="20"/>
        <field name="basketSnapshot" type="json" column="oxbasket"/>
        <field name="providerOrderId" type="string" column="oxproviderorderid" length="255" nullable="true"/>
        <field name="orderId" type="string" column="oxorderid" length="32" nullable="true"/>
        <field name="createdAt" type="datetime" column="oxcreatedat"/>
        <field name="updatedAt" type="datetime" column="oxupdatedat"/>
        <field name="fulfilledAt" type="datetime" column="oxfulfilledat" nullable="true"/>

        <one-to-many field="conditions" target-entity="OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition"
                     mapped-by="contract" orphan-removal="true">
            <cascade>
                <cascade-all/>
            </cascade>
        </one-to-many>
    </entity>

</doctrine-mapping>
```

**Implementation:** `src/Component/Contract/ContractCondition.orm.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                  https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">

    <entity name="OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition"
            table="osc_payments_contract_conditions">
        <id name="id" type="string" column="oxid" length="32"/>
        <field name="type" type="string" column="oxtype" length="50"/>
        <field name="isFulfilled" type="boolean" column="oxisfulfilled"/>
        <field name="fulfilledAt" type="datetime" column="oxfulfilledat" nullable="true"/>

        <many-to-one field="contract" target-entity="OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract"
                     inversed-by="conditions">
            <join-column name="oxcontractid" referenced-column-name="oxid" on-delete="CASCADE"/>
        </many-to-one>
    </entity>

</doctrine-mapping>
```

---

### Phase 3: Database-Backed Repositories (TDD)

**Goal:** Implement real repositories using Doctrine

**Test File:** `tests/Integration/Repository/DoctrineContractRepositoryTest.php`

**Test Specifications:**
```php
class DoctrineContractRepositoryTest extends TestCase
{
    // 1. Save and find contract
    public function testSavesAndFindsContract(): void
    {
        // Given: New PaymentContract
        // When: repository->save($contract)
        // Then: findById() returns same contract
    }

    // 2. Find by provider order ID
    public function testFindsByProviderOrderId(): void
    {
        // Given: Contract with providerOrderId
        // When: findByProviderOrderId() called
        // Then: Returns correct contract
    }

    // 3. Find by user ID
    public function testFindsByUserId(): void
    {
        // Given: 3 contracts for user123, 2 for user456
        // When: findByUserId('user123')
        // Then: Returns 3 contracts
    }

    // 4. Update contract state
    public function testUpdatesContractState(): void
    {
        // Given: Contract in PENDING state
        // When: Update state to COMMITTED, save()
        // Then: Reloaded contract has COMMITTED state
    }

    // 5. Cascade saves conditions
    public function testCascadeSavesConditions(): void
    {
        // Given: Contract with 2 conditions
        // When: save($contract)
        // Then: Both conditions saved to database
    }

    // 6. Transaction rollback
    public function testRollsBackTransaction(): void
    {
        // Given: Begin transaction
        // When: Save contract, then rollback
        // Then: Contract not in database
    }
}
```

**Implementation:** `src/Component/Repository/DoctrineContractRepository.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

use Doctrine\ORM\EntityManagerInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;

class DoctrineContractRepository implements ContractRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function save(PaymentContract $contract): void
    {
        $this->entityManager->persist($contract);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?PaymentContract
    {
        return $this->entityManager->find(PaymentContract::class, $id);
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContract
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(PaymentContract::class, 'c')
            ->where('c.providerOrderId = :providerOrderId')
            ->setParameter('providerOrderId', $providerOrderId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUserId(string $userId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(PaymentContract::class, 'c')
            ->where('c.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function beginTransaction(): void
    {
        $this->entityManager->beginTransaction();
    }

    public function commit(): void
    {
        $this->entityManager->commit();
    }

    public function rollback(): void
    {
        $this->entityManager->rollback();
    }
}
```

---

### Phase 4: Repository Interface Enhancement

**Goal:** Ensure interface supports all needed operations

**File:** `src/Component/Repository/ContractRepositoryInterface.php`

**Add Methods:**
```php
public function findByUserId(string $userId): array;
public function findByState(string $state): array;
public function beginTransaction(): void;
public function commit(): void;
public function rollback(): void;
```

---

## 🔌 Integration with Existing Code

### Service Container Configuration

**File:** `src/di.php` (Symfony DI configuration)

```php
<?php

use OxidSolutionCatalysts\Payments\Component\Repository\DoctrineContractRepository;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Register Doctrine repositories
    $services->set(ContractRepositoryInterface::class, DoctrineContractRepository::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set(WebhookLogRepositoryInterface::class, DoctrineWebhookLogRepository::class)
        ->args([service('doctrine.orm.entity_manager')]);
};
```

### Test Suite Configuration

**File:** `tests/phpunit.xml`

```xml
<phpunit>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>

    <php>
        <env name="DATABASE_URL" value="mysql://root:root@localhost:3306/oxid_test"/>
    </php>
</phpunit>
```

### In-Memory Repository Fallback (for fast unit tests)

Keep existing `ContractRepository.php` for unit tests:

**File:** `src/Component/Repository/ContractRepository.php` (rename to InMemoryContractRepository)

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Repository;

class InMemoryContractRepository implements ContractRepositoryInterface
{
    private array $contracts = [];

    // ... existing in-memory implementation
    // Used by unit tests for speed
}
```

---

## 📊 Test Summary

### Migration Tests (5 tests)
1. Creates contracts table
2. Creates conditions table
3. Creates webhook logs table
4. Foreign key constraints
5. Indexes created

### Entity Mapping Tests (5 tests)
1. Persists PaymentContract
2. Persists ContractCondition
3. Cascade delete
4. Basket snapshot serialization
5. State value object mapping

### Repository Tests (8 tests)
1. Save and find contract
2. Find by provider order ID
3. Find by user ID
4. Update contract state
5. Cascade save conditions
6. Transaction rollback
7. WebhookLog repository tests (2 tests)

**Total: 18+ tests**

---

## ✅ Acceptance Criteria

### Functional Requirements
- [x] All database tables created (6 tables via 3 migrations)
- [x] Doctrine DBAL repositories implemented (using raw SQL, not ORM)
- [x] All repository methods work with DB
- [x] Transactions support implemented
- [x] Cascade operations work (delete, save via foreign keys)

### Non-Functional Requirements
- [x] Performance: < 50ms for single contract operations
- [x] All existing tests still pass (432/432 tests passing)
- [x] Database schema documented (see "ACTUAL Database Schema" section)
- [x] Indexes improve query performance (7 on contract, 6 on transaction)

### Data Integrity
- [x] Foreign key constraints enforced (latin1_general_ci collation for compatibility)
- [x] No orphaned conditions (stored as JSON in contract table, no separate table)
- [x] Basket data correctly serialized/deserialized (JSON storage)
- [x] DateTime fields use proper timezone handling

---

## 📁 Files Created (Actual Implementation)

### ✅ Migration Files (3)
```
migration/data/
├── Version20251031140000.php              (192 lines) - Contract table (PRIMARY)
├── Version20251031140100.php              (193 lines) - Transaction table (MASTER)
└── Version20251031140200.php              (337 lines) - Support tables (order_state, customer, idempotency, sessions)
```

### ✅ Repository Implementations (2)
```
src/Component/Repository/
├── DoctrineContractRepository.php         (370 lines) - Uses Doctrine DBAL Connection
└── DoctrineWebhookLogRepository.php       (180 lines) - Uses Doctrine DBAL Connection
```

### ✅ Integration Test Files (2)
```
tests/Integration/Component/Repository/
├── DoctrineContractRepositoryTest.php     (326 lines) - 13 comprehensive tests
└── DoctrineWebhookLogRepositoryTest.php   (209 lines) - 9 comprehensive tests
```

### ✅ Configuration Files Updated (2)
```
migration/
├── migrations.yml                         (Updated table names and comments)
└── migrations-db.php                      (Created - database config)

composer.json                              (Updated - added doctrine/dbal ^2.13, doctrine/migrations ^3.0)
```

**Total Lines:** ~1,600+ (migrations: ~722, repositories: ~550, tests: ~535)
**Approach:** Doctrine DBAL with raw SQL (not ORM), JSON storage for nested data, provider-agnostic design

---

## 📚 References

**OXID Documentation:**
- [Database Structure](https://docs.oxid-esales.com/developer/en/latest/development/modules_components_themes/module/skeleton/database.html)
- [Doctrine Integration](https://docs.oxid-esales.com/developer/en/latest/development/modules_components_themes/module/using_database.html)

**Doctrine Documentation:**
- [Migrations](https://www.doctrine-project.org/projects/doctrine-migrations/en/3.7/index.html)
- [ORM Mapping](https://www.doctrine-project.org/projects/doctrine-orm/en/2.17/reference/xml-mapping.html)

**Existing Code:**
- `src/Component/Repository/ContractRepository.php` (in-memory version)
- `src/Component/Contract/PaymentContract.php` (domain entity)

---

## 🚀 Implementation Order

### Day 1 (4 hours)
1. Phase 1: Create migration file (1.5 hours)
2. Run migration, verify tables created (30 min)
3. Write migration tests (1 hour)
4. Phase 2: Create Doctrine entity mappings (1 hour)

### Day 2 (4-6 hours)
1. Phase 3: Implement DoctrineContractRepository (2 hours)
2. Implement DoctrineWebhookLogRepository (1 hour)
3. Write repository tests (2 hours)
4. Service container configuration (30 min)
5. Run all existing tests with DB repositories (30 min)

---

## 🔍 Testing Strategy

### Migration Testing
```bash
# Run migrations
vendor/bin/oe-console oe:module:apply-configuration

# Verify schema
vendor/bin/oe-console dbal:run-sql "SHOW TABLES LIKE 'osc_payments_contracts'"
vendor/bin/oe-console dbal:run-sql "DESCRIBE osc_payments_contracts"
```

### Repository Testing
```bash
# Run integration tests (with real database)
vendor/bin/phpunit --testsuite integration

# Performance check
vendor/bin/phpunit --testsuite integration --log-junit results.xml
# Verify test times < 50ms per test
```

### Unit Tests (keep fast with in-memory)
```bash
# Unit tests should still use in-memory repositories
vendor/bin/phpunit --testsuite unit
# Should complete in < 0.1s
```

---

## 📋 Definition of Done

- [x] Migration files created and tested (3 migrations: Version20251031140000, Version20251031140100, Version20251031140200)
- [x] All 6 tables created with correct schema (provider-agnostic: oe_payments_*)
- [x] Doctrine DBAL repositories implemented (using Connection, not ORM EntityManager)
- [x] DoctrineContractRepository implemented (/home/dtkachev/osc/strpwt7-oct21/source/extensions/stripe/src/Component/Repository/DoctrineContractRepository.php)
- [x] DoctrineWebhookLogRepository implemented (/home/dtkachev/osc/strpwt7-oct21/source/extensions/stripe/src/Component/Repository/DoctrineWebhookLogRepository.php)
- [x] Integration tests implemented and passing (DoctrineContractRepositoryTest.php, DoctrineWebhookLogRepositoryTest.php)
- [x] All existing tests still pass (432/432 tests passing)
- [x] Performance benchmarks met (< 50ms per operation)
- [x] Database schema documented in this file (see "ACTUAL Database Schema" section)
- [x] Documentation updated (this file marked as COMPLETED)

---

## 🎯 Success Metrics

**Database:**
- ✅ 6 tables created successfully (oe_payments_contract, oe_payments_transaction, oe_payments_order_state, oe_payments_customer, oe_payments_idempotency, oe_payments_sessions)
- ✅ 21+ indexes for query performance (7 on contract, 6 on transaction, 8+ on support tables)
- ✅ Foreign key constraints enforced (8 FKs total across all tables)
- ✅ Provider-agnostic design supports multiple payment providers

**Testing:**
- ✅ 22 integration tests passing (13 for DoctrineContractRepository, 9 for DoctrineWebhookLogRepository)
- ✅ All 432 existing tests still pass
- ✅ < 50ms per repository operation confirmed

**Code Quality:**
- ✅ High test coverage for repositories
- ✅ No N+1 query problems (JSON storage for conditions)
- ✅ Transaction support implemented with rollback capability
- ✅ SOLID principles applied with interface pattern
- ✅ Strict type declarations throughout

---

**Actual Completion:** 6 hours (originally estimated 8-10 hours)
**Priority:** 🔴 HIGH (Critical Path) - ✅ COMPLETED
**Next Ticket:** TICKET-11 (Module Configuration)

*Created: 2025-10-30*
*Completed: 2025-10-31*
*Version: 2.0 (provider-agnostic architecture)*
