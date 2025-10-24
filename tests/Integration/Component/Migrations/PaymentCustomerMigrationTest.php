<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Tests\Integration\Component\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OxidSolutionCatalysts\Payments\Migrations\Version20251024140200;
use Psr\Log\NullLogger;

/**
 * Test for payment customer table migration
 */
class PaymentCustomerMigrationTest extends MigrationTestBase
{
    private const TABLE_NAME = 'osc_stripe_payment_customer';

    /** @test */
    public function migration_creates_payment_customer_table(): void
    {
        // Given: Fresh schema
        $schema = new Schema();
        $migration = new Version20251024140200($this->connection, new NullLogger());

        // When: Running migration
        $migration->up($schema);
        $this->refreshSchema();

        // Then: Table should exist
        $this->assertTableExists(self::TABLE_NAME);
    }

    /** @test */
    public function payment_customer_table_has_correct_columns(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: All required columns should exist
        $this->assertColumnExists(self::TABLE_NAME, 'OXID');
        $this->assertColumnExists(self::TABLE_NAME, 'CUSTOMER_ID');
        $this->assertColumnExists(self::TABLE_NAME, 'OXUSERID');
        $this->assertColumnExists(self::TABLE_NAME, 'STRIPE_CUSTOMER_ID');
        $this->assertColumnExists(self::TABLE_NAME, 'DEFAULT_PAYMENT_METHOD');
        $this->assertColumnExists(self::TABLE_NAME, 'OXCREATED');
        $this->assertColumnExists(self::TABLE_NAME, 'OXTIMESTAMP');
    }

    /** @test */
    public function payment_customer_table_has_correct_column_types(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: Columns should have correct types
        $this->assertColumnType(self::TABLE_NAME, 'OXID', 'string');
        $this->assertColumnType(self::TABLE_NAME, 'CUSTOMER_ID', 'string');
        $this->assertColumnType(self::TABLE_NAME, 'OXUSERID', 'string');
        $this->assertColumnType(self::TABLE_NAME, 'STRIPE_CUSTOMER_ID', 'string');
        $this->assertColumnType(self::TABLE_NAME, 'DEFAULT_PAYMENT_METHOD', 'string');
        $this->assertColumnType(self::TABLE_NAME, 'OXCREATED', 'datetime');
        $this->assertColumnType(self::TABLE_NAME, 'OXTIMESTAMP', 'datetime');
    }

    /** @test */
    public function payment_customer_table_has_primary_key(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: Primary key should exist
        $this->assertPrimaryKeyExists(self::TABLE_NAME);
    }

    /** @test */
    public function payment_customer_table_has_required_indexes(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: Required indexes should exist
        $this->assertIndexExists(self::TABLE_NAME, 'CUSTOMER_ID_UNIQUE');
        $this->assertIndexExists(self::TABLE_NAME, 'OXUSERID_INDEX');
        $this->assertIndexExists(self::TABLE_NAME, 'STRIPE_CUSTOMER_ID_INDEX');
    }

    /** @test */
    public function payment_customer_migration_is_idempotent(): void
    {
        // Given: Fresh schema and migration
        $schema = new Schema();
        $migration = new Version20251024140200($this->connection, new NullLogger());

        // When: Running migration twice
        $migration->up($schema);
        $migration->up($schema); // Should not fail
        $this->refreshSchema();

        // Then: Table should still exist and be correct
        $this->assertTableExists(self::TABLE_NAME);
        $this->assertColumnExists(self::TABLE_NAME, 'OXID');
    }

    /** @test */
    public function customer_id_has_unique_constraint(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: CUSTOMER_ID should have unique index
        $this->assertIndexExists(self::TABLE_NAME, 'CUSTOMER_ID_UNIQUE');
    }

    /** @test */
    public function stripe_customer_id_is_indexed_for_lookups(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: STRIPE_CUSTOMER_ID should be indexed
        $this->assertIndexExists(self::TABLE_NAME, 'STRIPE_CUSTOMER_ID_INDEX');
    }

    /**
     * Ensure migration has been run
     */
    private function ensureMigrationRan(): void
    {
        if (!$this->schema->hasTable(self::TABLE_NAME)) {
            $schema = new Schema();
            $migration = new Version20251024140200($this->connection, new NullLogger());
            $migration->up($schema);
            $this->refreshSchema();
        }
    }

    public function tearDown(): void
    {
        // Clean up: Drop table if exists
        if ($this->schema->hasTable(self::TABLE_NAME)) {
            $this->connection->executeStatement("DROP TABLE IF EXISTS " . self::TABLE_NAME);
        }

        parent::tearDown();
    }
}
