<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Tests\Integration\Component\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OxidSolutionCatalysts\Payments\Migrations\Version20251024140300;
use Psr\Log\NullLogger;

/**
 * Test for payment basket snapshot table migration
 */
class PaymentBasketSnapshotMigrationTest extends MigrationTestBase
{
    private const TABLE_NAME = 'osc_stripe_payment_basket_snapshot';

    /** @test */
    public function migration_creates_payment_basket_snapshot_table(): void
    {
        // Given: Fresh schema
        $schema = new Schema();
        $migration = new Version20251024140300($this->connection, new NullLogger());

        // When: Running migration
        $migration->up($schema);
        $this->refreshSchema();

        // Then: Table should exist
        $this->assertTableExists(self::TABLE_NAME);
    }

    /** @test */
    public function payment_basket_snapshot_table_has_correct_columns(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: All required columns should exist
        $this->assertColumnExists(self::TABLE_NAME, 'OXID');
        $this->assertColumnExists(self::TABLE_NAME, 'BASKET_ID');
        $this->assertColumnExists(self::TABLE_NAME, 'OXUSERID');
        $this->assertColumnExists(self::TABLE_NAME, 'BASKET_DATA');
        $this->assertColumnExists(self::TABLE_NAME, 'OXCREATED');
    }

    /** @test */
    public function payment_basket_snapshot_table_has_correct_column_types(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: Columns should have correct types
        $this->assertColumnType(self::TABLE_NAME, 'OXID', 'string');
        $this->assertColumnType(self::TABLE_NAME, 'BASKET_ID', 'string');
        $this->assertColumnType(self::TABLE_NAME, 'OXUSERID', 'string');
        $this->assertColumnType(self::TABLE_NAME, 'BASKET_DATA', 'text');
        $this->assertColumnType(self::TABLE_NAME, 'OXCREATED', 'datetime');
    }

    /** @test */
    public function payment_basket_snapshot_table_has_primary_key(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: Primary key should exist
        $this->assertPrimaryKeyExists(self::TABLE_NAME);
    }

    /** @test */
    public function payment_basket_snapshot_table_has_required_indexes(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: Required indexes should exist
        $this->assertIndexExists(self::TABLE_NAME, 'BASKET_ID_UNIQUE');
        $this->assertIndexExists(self::TABLE_NAME, 'OXUSERID_INDEX');
    }

    /** @test */
    public function payment_basket_snapshot_migration_is_idempotent(): void
    {
        // Given: Fresh schema and migration
        $schema = new Schema();
        $migration = new Version20251024140300($this->connection, new NullLogger());

        // When: Running migration twice
        $migration->up($schema);
        $migration->up($schema); // Should not fail
        $this->refreshSchema();

        // Then: Table should still exist and be correct
        $this->assertTableExists(self::TABLE_NAME);
        $this->assertColumnExists(self::TABLE_NAME, 'OXID');
    }

    /** @test */
    public function basket_id_has_unique_constraint(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: BASKET_ID should have unique index
        $this->assertIndexExists(self::TABLE_NAME, 'BASKET_ID_UNIQUE');
    }

    /** @test */
    public function basket_data_column_can_store_serialized_data(): void
    {
        // Given: Migration has run
        $this->ensureMigrationRan();

        // Then: BASKET_DATA column should be text type for serialized data storage
        $this->assertColumnType(self::TABLE_NAME, 'BASKET_DATA', 'text');
    }

    /**
     * Ensure migration has been run
     */
    private function ensureMigrationRan(): void
    {
        if (!$this->schema->hasTable(self::TABLE_NAME)) {
            $schema = new Schema();
            $migration = new Version20251024140300($this->connection, new NullLogger());
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
