# Database Implementation Guide - Sprint 1 (Part 1: Migrations)
## TDD Approach for Migrations, Models, and Repositories (Contract-Aware)

**Version:** 4.0.0
**Date:** 2025-10-22
**Target:** OXID eShop 7.4+
**Philosophy:** Test-First Development with Clean Architecture + Smart Contracts

**Related Docs:**
- [02-database-and-models.md](02-database-and-models.md) - Contract-aware database schema
- [01-architecture-layers.md](01-architecture-layers.md) - Contract-aware architecture
- [09-tdd-strategy.md](09-tdd-strategy.md) - Complete TDD strategy
- [10-test-organization.md](10-test-organization.md) - Component vs Provider tests
- [IMPLEMENTATION-TICKETS-SPRINT-1.md](IMPLEMENTATION-TICKETS-SPRINT-1.md) - Sprint tickets

**Other Parts:**
- **Part 1 (this file):** Overview, TDD Philosophy, and Migration Strategy
- [Part 2](IMPLEMENTATION-DB-SPRINT-1-PART-2-MODELS.md): Component Models Implementation
- [Part 3](IMPLEMENTATION-DB-SPRINT-1-PART-3-REPOSITORIES.md): Repository Pattern & Testing

---

## Table of Contents

1. [Overview](#overview)
2. [TDD Philosophy for Database Layer](#tdd-philosophy-for-database-layer)
3. [Migration Strategy](#migration-strategy)
4. [Complete Migration PHP Files for OXID 7.4](#complete-migration-php-files-for-oxid-74)

---

## Overview

### Goals for Sprint 1 Database Work

**Primary Objectives:**
1. ✅ Create **contract table** as primary entity (`oe_payments_contract`)
2. ✅ Enhance existing tables with contract FK (`OXCONTRACTID`)
3. ✅ Implement contract domain models (PaymentContract, ContractCondition, BasketSnapshot)
4. ✅ Build contract-aware repository layer with full test coverage
5. ✅ Ensure 100% data integrity through constraints
6. ✅ Achieve 95%+ test coverage on database layer
7. ✅ **CRITICAL: Comprehensive smart contract functionality testing**

**Architecture Principles (Contract-Aware):**
- **Contract-first pattern** - Contract created BEFORE order
- **NO class extensions** in metadata.php
- **Component tables reference OXID core** via FK (OXUSERID → oxuser.OXID, OXORDERID → oxorder.OXID)
- **Order creation deferred** - OXORDERID is NULL in contract until committed
- **Clean isolation** - can drop component tables without affecting core
- **Test-first approach** - write tests before implementation

---

## TDD Philosophy for Database Layer

### The Red-Green-Refactor Cycle

```
┌──────────────────────────────────────────────────────────┐
│  1. RED: Write failing test                              │
│     ↓                                                     │
│  2. GREEN: Write minimal code to pass                    │
│     ↓                                                     │
│  3. REFACTOR: Improve code without breaking tests        │
│     ↓                                                     │
│  4. REPEAT: Next feature                                 │
└──────────────────────────────────────────────────────────┘
```

### Why TDD for Database Layer?

| Benefit | Impact |
|---------|--------|
| **Data Integrity** | Constraints tested before implementation |
| **FK Relationships** | Verify cascade behaviors work correctly |
| **State Transitions** | Test all valid/invalid state changes |
| **Concurrent Access** | Test race conditions and locks |
| **Performance** | Benchmark queries during development |
| **Regression Prevention** | Catch schema changes that break logic |

### Test Priority for Database Layer

**🔴 CRITICAL (P0)** - Must test FIRST:
- **Smart Contract Lifecycle** (draft → pending → ready_to_commit → committed → fulfilled)
- **Contract Conditions** (payment_authorized, fraud_check, inventory_reserved, etc.)
- **Condition State Tracking** (pending → completed/failed with timestamps)
- Foreign key constraints (CASCADE, SET NULL)
- Unique constraints (prevent duplicates)
- Required fields validation (NOT NULL)
- Data integrity (refund ≤ captured amount)
- State machine transitions (invalid states rejected)
- Concurrent access (pessimistic locking)
- **Contract expiration** (automatic timeout after 24 hours)
- **Contract-to-order linking** (OXORDERID NULL until committed)

**🟠 HIGH (P1)** - Test SECOND:
- Repository CRUD operations
- Query performance
- Transaction rollback
- Audit trail immutability

**🟡 MEDIUM (P2)** - Test THIRD:
- Complex queries (JOINs)
- Computed columns
- Index efficiency

---

## Migration Strategy

### Migration Files Structure (Contract-Aware)

**Location:** `migration/` directory in component

```
migration/
├── 001_create_payment_contract_table.sql                 (NEW - PRIMARY TABLE!)
├── 002_create_payment_transaction_table.sql
├── 003_enhance_tables_with_contract_fk.sql              (NEW - Add OXCONTRACTID to existing tables)
├── 004_create_payment_authorization_details_table.sql
├── 005_create_payment_3ds_details_table.sql
├── 006_create_payment_refund_details_table.sql
├── 007_create_payment_delivery_tracking_table.sql
├── 008_create_payment_provider_data_table.sql
├── 009_create_payment_order_state_table.sql
├── 010_create_payment_customer_table.sql
├── 011_create_payment_idempotency_table.sql
├── 012_create_payment_saved_methods_table.sql
└── 013_create_payment_sessions_table.sql
```

**Migration Order (CRITICAL):**
1. **001** - Create contract table FIRST (no dependencies)
2. **002** - Create transaction table (will reference contract via FK)
3. **003** - Enhance existing tables with contract FK
4. **004-013** - Create remaining tables

---

## Complete Migration PHP Files for OXID 7.4

### Migration Helper Class

First, create a migration helper class for OXID 7.4:

```php
<?php
// src/Infrastructure/Migration/MigrationRunner.php

declare(strict_types=1);

namespace Osc\Payment\Component\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;

/**
 * Migration Runner for OXID 7.4+
 *
 * Executes SQL migrations with proper error handling
 */
final class MigrationRunner
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * Run a migration file
     */
    public function runMigration(string $migrationFile): void
    {
        $migrationPath = $this->getMigrationPath($migrationFile);

        if (!file_exists($migrationPath)) {
            throw new \RuntimeException("Migration file not found: {$migrationPath}");
        }

        $sql = file_get_contents($migrationPath);

        try {
            $this->connection->executeStatement($sql);
        } catch (DBALException $e) {
            throw new \RuntimeException(
                "Migration failed: {$migrationFile}\nError: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Run all pending migrations
     */
    public function runAllMigrations(): void
    {
        $migrations = $this->getMigrationFiles();

        foreach ($migrations as $migration) {
            echo "Running migration: {$migration}\n";
            $this->runMigration($migration);
            echo "✓ Migration completed: {$migration}\n";
        }
    }

    /**
     * Get migration file path
     */
    private function getMigrationPath(string $filename): string
    {
        return __DIR__ . '/../../../migration/' . $filename;
    }

    /**
     * Get all migration files in order
     */
    private function getMigrationFiles(): array
    {
        $migrationDir = __DIR__ . '/../../../migration/';
        $files = glob($migrationDir . '*.sql');
        sort($files);
        return array_map('basename', $files);
    }

    /**
     * Check if a specific migration has been run
     */
    public function hasRunMigration(string $migrationName): bool
    {
        // Implementation depends on your migration tracking table
        // For simplicity, we'll check if the table exists
        $tableName = $this->extractTableNameFromMigration($migrationName);
        return $this->tableExists($tableName);
    }

    /**
     * Check if a table exists
     */
    private function tableExists(string $tableName): bool
    {
        $schemaManager = $this->connection->createSchemaManager();
        return $schemaManager->tablesExist([$tableName]);
    }

    /**
     * Extract table name from migration filename
     */
    private function extractTableNameFromMigration(string $filename): string
    {
        // Extract table name from filename like "001_create_payment_contract_table.sql"
        preg_match('/create_(.+)_table\.sql$/', $filename, $matches);
        return $matches[1] ?? '';
    }
}
```

### Migration 001: Payment Contract Table (PHP)

```php
<?php
// src/Infrastructure/Migration/Migrations/Migration001CreatePaymentContractTable.php

declare(strict_types=1);

namespace Osc\Payment\Component\Infrastructure\Migration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Schema\Schema;

/**
 * Migration 001: Create Payment Contract Table
 *
 * Creates the primary contract table for smart-contract pattern
 */
final class Migration001CreatePaymentContractTable
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function up(): void
    {
        $schema = $this->connection->createSchemaManager()->introspectSchema();

        if ($schema->hasTable('oe_payments_contract')) {
            return; // Already exists
        }

        $table = $schema->createTable('oe_payments_contract');

        // Primary key
        $table->addColumn('OXID', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'comment' => 'Contract ID (UUID)'
        ]);
        $table->setPrimaryKey(['OXID']);

        // Shop & user references
        $table->addColumn('OXSHOPID', Types::INTEGER, [
            'notnull' => true,
            'comment' => 'Shop ID (multi-shop support)'
        ]);

        $table->addColumn('OXUSERID', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'comment' => 'FK to oxuser.OXID'
        ]);

        $table->addColumn('OXORDERID', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'comment' => 'FK to oxorder.OXID (NULL until committed!)'
        ]);

        // Contract state
        $table->addColumn('OXSTATE', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'comment' => 'draft, pending, ready_to_commit, committed, fulfilled, cancelled, expired, failed'
        ]);

        $table->addColumn('OXSTATEREASON', Types::STRING, [
            'length' => 255,
            'notnull' => false,
            'comment' => 'Reason for state (if failed/cancelled)'
        ]);

        // Snapshot data (immutable)
        $table->addColumn('OXBASKETDATA', Types::JSON, [
            'notnull' => true,
            'comment' => 'Complete basket snapshot (items, discounts, totals)'
        ]);

        $table->addColumn('OXTERMS', Types::JSON, [
            'notnull' => false,
            'comment' => 'Terms & conditions agreed by customer'
        ]);

        $table->addColumn('OXMETADATA', Types::JSON, [
            'notnull' => false,
            'comment' => 'Additional metadata (IP, user agent, session ID)'
        ]);

        // Fulfillment conditions
        $table->addColumn('OXCONDITIONS', Types::JSON, [
            'notnull' => true,
            'comment' => 'Array of conditions with status'
        ]);

        // Provider data
        $table->addColumn('OXPROVIDER', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'comment' => 'Payment provider: stripe, paypal, unzer, adyen, klarna, amazonpay'
        ]);

        $table->addColumn('OXPROVIDERORDERID', Types::STRING, [
            'length' => 128,
            'notnull' => false,
            'comment' => 'Provider contract ID (PaymentIntent ID, Order ID, etc.)'
        ]);

        $table->addColumn('OXPROVIDERDATA', Types::JSON, [
            'notnull' => false,
            'comment' => 'Provider-specific data'
        ]);

        // Timestamps
        $table->addColumn('OXCREATED', Types::DATETIME_MUTABLE, [
            'notnull' => true,
            'comment' => 'Contract creation timestamp'
        ]);

        $table->addColumn('OXUPDATED', Types::DATETIME_MUTABLE, [
            'notnull' => true,
            'comment' => 'Last update timestamp'
        ]);

        $table->addColumn('OXCOMMITTEDAT', Types::DATETIME_MUTABLE, [
            'notnull' => false,
            'comment' => 'When order was created (contract committed)'
        ]);

        $table->addColumn('OXFULFILLEDAT', Types::DATETIME_MUTABLE, [
            'notnull' => false,
            'comment' => 'When payment was captured (contract fulfilled)'
        ]);

        $table->addColumn('OXEXPIRESAT', Types::DATETIME_MUTABLE, [
            'notnull' => false,
            'comment' => 'Contract expiration (default: +24 hours)'
        ]);

        // Indexes
        $table->addIndex(['OXSTATE'], 'IDX_STATE');
        $table->addIndex(['OXUSERID'], 'IDX_USER');
        $table->addIndex(['OXORDERID'], 'IDX_ORDER');
        $table->addIndex(['OXPROVIDERORDERID'], 'IDX_PROVIDER_ORDER');
        $table->addIndex(['OXCREATED'], 'IDX_CREATED');
        $table->addIndex(['OXEXPIRESAT'], 'IDX_EXPIRES');
        $table->addIndex(['OXSTATE', 'OXEXPIRESAT'], 'IDX_STATE_EXPIRES');

        // Foreign keys
        $table->addForeignKeyConstraint(
            'oxuser',
            ['OXUSERID'],
            ['OXID'],
            ['onDelete' => 'CASCADE'],
            'FK_CONTRACT_USER'
        );

        $table->addForeignKeyConstraint(
            'oxorder',
            ['OXORDERID'],
            ['OXID'],
            ['onDelete' => 'SET NULL'],
            'FK_CONTRACT_ORDER'
        );

        // Apply schema changes
        $schemaManager = $this->connection->createSchemaManager();
        $schemaManager->createTable($table);
    }

    public function down(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $schemaManager->dropTable('oe_payments_contract');
    }
}
```

### Migration 002: Payment Transaction Table (PHP)

```php
<?php
// src/Infrastructure/Migration/Migrations/Migration002CreatePaymentTransactionTable.php

declare(strict_types=1);

namespace Osc\Payment\Component\Infrastructure\Migration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Schema\Schema;

/**
 * Migration 002: Create Payment Transaction Table
 *
 * Creates the master transaction table with contract FK
 */
final class Migration002CreatePaymentTransactionTable
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function up(): void
    {
        $schema = $this->connection->createSchemaManager()->introspectSchema();

        if ($schema->hasTable('oe_payments_transaction')) {
            return;
        }

        $table = $schema->createTable('oe_payments_transaction');

        // Primary key
        $table->addColumn('OXID', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'comment' => 'Transaction ID'
        ]);
        $table->setPrimaryKey(['OXID']);

        // Core references
        $table->addColumn('OXSHOPID', Types::INTEGER, [
            'notnull' => true,
            'comment' => 'Shop ID'
        ]);

        $table->addColumn('OXORDERID', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'comment' => 'FK to oxorder.OXID'
        ]);

        $table->addColumn('OXCONTRACTID', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'comment' => 'FK to oe_payments_contract.OXID (NEW!)'
        ]);

        // Provider identification
        $table->addColumn('OXPROVIDER', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'comment' => 'Provider: stripe, paypal, unzer, amazon'
        ]);

        $table->addColumn('OXPROVIDERORDERID', Types::STRING, [
            'length' => 128,
            'notnull' => false,
            'comment' => 'Provider order ID'
        ]);

        $table->addColumn('OXTRANSACTIONID', Types::STRING, [
            'length' => 128,
            'notnull' => false,
            'comment' => 'Provider transaction ID'
        ]);

        // Transaction basics
        $table->addColumn('OXTYPE', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'comment' => 'Type: authorization, capture, refund, void'
        ]);

        $table->addColumn('OXSTATUS', Types::STRING, [
            'length' => 32,
            'notnull' => true,
            'comment' => 'Status: pending, completed, failed, cancelled'
        ]);

        $table->addColumn('OXAMOUNT', Types::DECIMAL, [
            'precision' => 10,
            'scale' => 2,
            'notnull' => true,
            'comment' => 'Transaction amount'
        ]);

        $table->addColumn('OXCURRENCY', Types::STRING, [
            'length' => 3,
            'notnull' => true,
            'comment' => 'Currency code (ISO 4217)'
        ]);

        // Payment method
        $table->addColumn('OXPAYMENTMETHODID', Types::STRING, [
            'length' => 64,
            'notnull' => false,
            'comment' => 'Payment method identifier'
        ]);

        $table->addColumn('OXPAYMENTMETHODTYPE', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'comment' => 'Payment method type'
        ]);

        // Relationships
        $table->addColumn('OXPARENTTRANSACTIONID', Types::STRING, [
            'length' => 32,
            'notnull' => false,
            'comment' => 'Parent transaction ID'
        ]);

        // Timestamps
        $table->addColumn('OXCREATED', Types::DATETIME_MUTABLE, [
            'notnull' => true,
            'comment' => 'Created timestamp'
        ]);

        $table->addColumn('OXUPDATED', Types::DATETIME_MUTABLE, [
            'notnull' => true,
            'comment' => 'Updated timestamp'
        ]);

        // Indexes
        $table->addIndex(['OXORDERID'], 'IDX_ORDER');
        $table->addIndex(['OXCONTRACTID'], 'IDX_CONTRACT');
        $table->addIndex(['OXPROVIDERORDERID'], 'IDX_PROVIDER_ORDER');
        $table->addIndex(['OXTRANSACTIONID'], 'IDX_TRANSACTION_ID');
        $table->addIndex(['OXTYPE', 'OXSTATUS'], 'IDX_TYPE_STATUS');
        $table->addIndex(['OXPARENTTRANSACTIONID'], 'IDX_PARENT');

        // Foreign keys
        $table->addForeignKeyConstraint(
            'oxorder',
            ['OXORDERID'],
            ['OXID'],
            ['onDelete' => 'CASCADE'],
            'FK_TX_ORDER'
        );

        $table->addForeignKeyConstraint(
            'oe_payments_contract',
            ['OXCONTRACTID'],
            ['OXID'],
            ['onDelete' => 'SET NULL'],
            'FK_TX_CONTRACT'
        );

        $table->addForeignKeyConstraint(
            'oe_payments_transaction',
            ['OXPARENTTRANSACTIONID'],
            ['OXID'],
            ['onDelete' => 'SET NULL'],
            'FK_TX_PARENT'
        );

        // Apply schema changes
        $schemaManager = $this->connection->createSchemaManager();
        $schemaManager->createTable($table);
    }

    public function down(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $schemaManager->dropTable('oe_payments_transaction');
    }
}
```

### Migration 003: Enhance Existing Tables with Contract FK (PHP)

```php
<?php
// src/Infrastructure/Migration/Migrations/Migration003EnhanceTablesWithContractFK.php

declare(strict_types=1);

namespace Osc\Payment\Component\Infrastructure\Migration\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Migration 003: Enhance Existing Tables with Contract FK
 *
 * Adds OXCONTRACTID to order_state and other relevant tables
 */
final class Migration003EnhanceTablesWithContractFK
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    public function up(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $schema = $schemaManager->introspectSchema();

        // Add OXCONTRACTID to oe_payments_order_state
        if ($schema->hasTable('oe_payments_order_state')) {
            $table = $schema->getTable('oe_payments_order_state');

            if (!$table->hasColumn('OXCONTRACTID')) {
                $table->addColumn('OXCONTRACTID', Types::STRING, [
                    'length' => 32,
                    'notnull' => false,
                    'comment' => 'FK to oe_payments_contract.OXID'
                ]);

                $table->addIndex(['OXCONTRACTID'], 'IDX_CONTRACT');

                $table->addForeignKeyConstraint(
                    'oe_payments_contract',
                    ['OXCONTRACTID'],
                    ['OXID'],
                    ['onDelete' => 'SET NULL'],
                    'FK_ORDER_STATE_CONTRACT'
                );

                // Apply changes
                $schemaManager->alterTable($table);
            }
        }
    }

    public function down(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $schema = $schemaManager->introspectSchema();

        if ($schema->hasTable('oe_payments_order_state')) {
            $table = $schema->getTable('oe_payments_order_state');

            if ($table->hasColumn('OXCONTRACTID')) {
                $table->dropColumn('OXCONTRACTID');
                $schemaManager->alterTable($table);
            }
        }
    }
}
```

---

**Continue to [Part 2: Component Models](IMPLEMENTATION-DB-SPRINT-1-PART-2-MODELS.md)**
