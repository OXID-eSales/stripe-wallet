<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Component\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OxidSolutionCatalysts\Payments\Migrations\Version20251031140000;
use Psr\Log\NullLogger;

class PaymentContractsMigrationTest extends MigrationTestBase
{
    private const TABLE_CONTRACTS = 'osc_payment_contracts';
    private const TABLE_CONDITIONS = 'osc_payment_contract_conditions';
    private const TABLE_WEBHOOK_LOGS = 'osc_payment_webhook_logs';

    /** @test */
    public function migration_creates_payment_contracts_table(): void
    {
        $schema = new Schema();
        $migration = new Version20251031140000($this->connection, new NullLogger());

        $migration->up($schema);
        $this->refreshSchema();

        $this->assertTableExists(self::TABLE_CONTRACTS);
    }

    /** @test */
    public function migration_creates_contract_conditions_table(): void
    {
        $this->ensureMigrationRan();

        $this->assertTableExists(self::TABLE_CONDITIONS);
    }

    /** @test */
    public function migration_creates_webhook_logs_table(): void
    {
        $this->ensureMigrationRan();

        $this->assertTableExists(self::TABLE_WEBHOOK_LOGS);
    }

    /** @test */
    public function contracts_table_has_correct_columns(): void
    {
        $this->ensureMigrationRan();

        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXSHOPID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXUSERID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXORDERID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXSTATE');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXBASKET');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXPROVIDER');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXPROVIDERORDERID');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXPROVIDERREDIRECTURL');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXEXPIRESAT');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXCREATED');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXTIMESTAMP');
        $this->assertColumnExists(self::TABLE_CONTRACTS, 'OXFULFILLEDAT');
    }

    /** @test */
    public function conditions_table_has_correct_columns(): void
    {
        $this->ensureMigrationRan();

        $this->assertColumnExists(self::TABLE_CONDITIONS, 'OXID');
        $this->assertColumnExists(self::TABLE_CONDITIONS, 'OXCONTRACTID');
        $this->assertColumnExists(self::TABLE_CONDITIONS, 'OXTYPE');
        $this->assertColumnExists(self::TABLE_CONDITIONS, 'OXSTATUS');
        $this->assertColumnExists(self::TABLE_CONDITIONS, 'OXDATA');
        $this->assertColumnExists(self::TABLE_CONDITIONS, 'OXFULFILLEDAT');
        $this->assertColumnExists(self::TABLE_CONDITIONS, 'OXFAILUREREASON');
    }

    /** @test */
    public function webhook_logs_table_has_correct_columns(): void
    {
        $this->ensureMigrationRan();

        $this->assertColumnExists(self::TABLE_WEBHOOK_LOGS, 'OXID');
        $this->assertColumnExists(self::TABLE_WEBHOOK_LOGS, 'OXEVENTID');
        $this->assertColumnExists(self::TABLE_WEBHOOK_LOGS, 'OXEVENTTYPE');
        $this->assertColumnExists(self::TABLE_WEBHOOK_LOGS, 'OXCONTRACTID');
        $this->assertColumnExists(self::TABLE_WEBHOOK_LOGS, 'OXSTATUS');
        $this->assertColumnExists(self::TABLE_WEBHOOK_LOGS, 'OXRECEIVEDAT');
        $this->assertColumnExists(self::TABLE_WEBHOOK_LOGS, 'OXERROR');
    }

    /** @test */
    public function contracts_table_has_correct_column_types(): void
    {
        $this->ensureMigrationRan();

        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXID', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXSHOPID', 'integer');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXUSERID', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXORDERID', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXSTATE', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXBASKET', 'text');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXPROVIDER', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXPROVIDERORDERID', 'string');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXCREATED', 'datetime');
        $this->assertColumnType(self::TABLE_CONTRACTS, 'OXTIMESTAMP', 'datetime');
    }

    /** @test */
    public function conditions_table_has_correct_column_types(): void
    {
        $this->ensureMigrationRan();

        $this->assertColumnType(self::TABLE_CONDITIONS, 'OXID', 'string');
        $this->assertColumnType(self::TABLE_CONDITIONS, 'OXCONTRACTID', 'string');
        $this->assertColumnType(self::TABLE_CONDITIONS, 'OXTYPE', 'string');
        $this->assertColumnType(self::TABLE_CONDITIONS, 'OXSTATUS', 'string');
        $this->assertColumnType(self::TABLE_CONDITIONS, 'OXDATA', 'text');
    }

    /** @test */
    public function webhook_logs_table_has_correct_column_types(): void
    {
        $this->ensureMigrationRan();

        $this->assertColumnType(self::TABLE_WEBHOOK_LOGS, 'OXID', 'string');
        $this->assertColumnType(self::TABLE_WEBHOOK_LOGS, 'OXEVENTID', 'string');
        $this->assertColumnType(self::TABLE_WEBHOOK_LOGS, 'OXSTATUS', 'string');
        $this->assertColumnType(self::TABLE_WEBHOOK_LOGS, 'OXRECEIVEDAT', 'datetime');
    }

    /** @test */
    public function all_tables_have_primary_keys(): void
    {
        $this->ensureMigrationRan();

        $this->assertPrimaryKeyExists(self::TABLE_CONTRACTS);
        $this->assertPrimaryKeyExists(self::TABLE_CONDITIONS);
        $this->assertPrimaryKeyExists(self::TABLE_WEBHOOK_LOGS);
    }

    /** @test */
    public function contracts_table_has_required_indexes(): void
    {
        $this->ensureMigrationRan();

        $this->assertIndexExists(self::TABLE_CONTRACTS, 'OXUSERID_INDEX');
        $this->assertIndexExists(self::TABLE_CONTRACTS, 'OXSTATE_INDEX');
        $this->assertIndexExists(self::TABLE_CONTRACTS, 'OXPROVIDERORDERID_INDEX');
        $this->assertIndexExists(self::TABLE_CONTRACTS, 'OXORDERID_INDEX');
    }

    /** @test */
    public function conditions_table_has_required_indexes(): void
    {
        $this->ensureMigrationRan();

        $this->assertIndexExists(self::TABLE_CONDITIONS, 'OXCONTRACTID_INDEX');
        $this->assertIndexExists(self::TABLE_CONDITIONS, 'OXTYPE_INDEX');
        $this->assertIndexExists(self::TABLE_CONDITIONS, 'OXSTATUS_INDEX');
    }

    /** @test */
    public function webhook_logs_table_has_required_indexes(): void
    {
        $this->ensureMigrationRan();

        $this->assertIndexExists(self::TABLE_WEBHOOK_LOGS, 'OXEVENTID_UNIQUE');
        $this->assertIndexExists(self::TABLE_WEBHOOK_LOGS, 'OXCONTRACTID_INDEX');
        $this->assertIndexExists(self::TABLE_WEBHOOK_LOGS, 'OXRECEIVEDAT_INDEX');
    }

    /** @test */
    public function foreign_key_constraint_exists_on_conditions(): void
    {
        $this->ensureMigrationRan();

        $table = $this->schema->getTable(self::TABLE_CONDITIONS);
        $foreignKeys = $table->getForeignKeys();

        $this->assertNotEmpty($foreignKeys, 'Conditions table should have foreign key constraint');

        $hasForeignKeyToContracts = false;
        foreach ($foreignKeys as $fk) {
            if ($fk->getForeignTableName() === self::TABLE_CONTRACTS) {
                $hasForeignKeyToContracts = true;
                $this->assertContains('OXCONTRACTID', $fk->getLocalColumns());
                break;
            }
        }

        $this->assertTrue(
            $hasForeignKeyToContracts,
            'Foreign key from conditions to contracts should exist'
        );
    }

    /** @test */
    public function migration_is_idempotent(): void
    {
        $schema = new Schema();
        $migration = new Version20251031140000($this->connection, new NullLogger());

        $migration->up($schema);
        $migration->up($schema);
        $this->refreshSchema();

        $this->assertTableExists(self::TABLE_CONTRACTS);
        $this->assertTableExists(self::TABLE_CONDITIONS);
        $this->assertTableExists(self::TABLE_WEBHOOK_LOGS);
    }

    /** @test */
    public function basket_column_has_correct_comment(): void
    {
        $this->ensureMigrationRan();

        $columnDef = $this->getColumnDefinition(self::TABLE_CONTRACTS, 'OXBASKET');
        $this->assertStringContainsString('JSON', $columnDef['comment'] ?? '');
    }

    private function ensureMigrationRan(): void
    {
        if (!$this->schema->hasTable(self::TABLE_CONTRACTS)) {
            $schema = new Schema();
            $migration = new Version20251031140000($this->connection, new NullLogger());
            $migration->up($schema);
            $this->refreshSchema();
        }
    }

    public function tearDown(): void
    {
        if ($this->schema->hasTable(self::TABLE_CONDITIONS)) {
            $this->connection->executeStatement("DROP TABLE IF EXISTS " . self::TABLE_CONDITIONS);
        }
        if ($this->schema->hasTable(self::TABLE_WEBHOOK_LOGS)) {
            $this->connection->executeStatement("DROP TABLE IF EXISTS " . self::TABLE_WEBHOOK_LOGS);
        }
        if ($this->schema->hasTable(self::TABLE_CONTRACTS)) {
            $this->connection->executeStatement("DROP TABLE IF EXISTS " . self::TABLE_CONTRACTS);
        }

        parent::tearDown();
    }
}
