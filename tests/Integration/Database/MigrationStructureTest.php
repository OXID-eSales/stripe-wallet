<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for database migration structure
 *
 * These tests verify that migrations correctly create all tables, columns,
 * indexes, and foreign keys as specified in the architecture documentation.
 *
 * @group database
 * @group migration
 */
class MigrationStructureTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        // Load database configuration from payment-component (tables are created there)
        $dbConfig = require __DIR__ . '/../../../../payment-component/migration/migrations-db.php';

        // Create DBAL connection
        $config = new Configuration();
        $this->connection = DriverManager::getConnection($dbConfig, $config);
    }

    // ==================== TABLE EXISTENCE TESTS ====================

    public function testContractTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('oe_payments_contract'),
            'Table oe_payments_contract should exist'
        );
    }

    public function testTransactionTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('oe_payments_transaction'),
            'Table oe_payments_transaction should exist'
        );
    }

    public function testOrderStateTableDropped(): void
    {
        // Sprint 8: oe_payments_stripe_order_state table was DROPPED
        // Capture/refund tracking now handled by oe_payments_contract fields
        $this->assertFalse(
            $this->tableExists('oe_payments_stripe_order_state'),
            'Table oe_payments_stripe_order_state should NOT exist (dropped in Sprint 8)'
        );
    }

    public function testCustomerTableExists(): void
    {
        // Customer table is provider-agnostic, in payment-component
        $this->assertTrue(
            $this->tableExists('oe_payments_customer'),
            'Table oe_payments_customer should exist (from payment-component)'
        );
    }

    public function testIdempotencyTableExists(): void
    {
        // Idempotency table is provider-agnostic, in payment-component
        $this->assertTrue(
            $this->tableExists('oe_payments_idempotency'),
            'Table oe_payments_idempotency should exist (from payment-component)'
        );
    }

    public function testSessionsTableExists(): void
    {
        // Sessions table is provider-agnostic, in payment-component
        $this->assertTrue(
            $this->tableExists('oe_payments_sessions'),
            'Table oe_payments_sessions should exist (from payment-component)'
        );
    }

    public function testWebhookLogsTableExists(): void
    {
        // Webhooklogs table is provider-agnostic, in payment-component
        $this->assertTrue(
            $this->tableExists('oe_payments_webhooklogs'),
            'Table oe_payments_webhooklogs should exist (from payment-component)'
        );
    }

    // ==================== CONTRACT TABLE STRUCTURE ====================

    public function testContractTableHasRequiredColumns(): void
    {
        $columns = $this->getTableColumns('oe_payments_contract');

        $expectedColumns = [
            'OXID', 'OXSHOPID', 'OXUSERID', 'OXORDERID', 'OXSTATE',
            'OXSTATEREASON', 'OXBASKETDATA', 'OXTERMS', 'OXMETADATA',
            'OXCONDITIONS', 'OXPROVIDER', 'OXPROVIDERORDERID', 'OXPROVIDERDATA',
            'OXCREATED', 'OXUPDATED', 'OXCOMMITTEDAT', 'OXFULFILLEDAT', 'OXEXPIRESAT'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Contract table should have column {$column}"
            );
        }
    }

    public function testContractTableHasPrimaryKey(): void
    {
        $indexes = $this->getTableIndexes('oe_payments_contract');

        $this->assertArrayHasKey('PRIMARY', $indexes, 'Contract table should have PRIMARY key');
        $this->assertContains('OXID', $indexes['PRIMARY']['columns'], 'PRIMARY key should be on OXID');
    }

    public function testContractTableHasStateIndex(): void
    {
        $indexes = $this->getTableIndexes('oe_payments_contract');

        $hasStateIndex = false;
        foreach ($indexes as $indexName => $indexData) {
            if (in_array('OXSTATE', $indexData['columns'])) {
                $hasStateIndex = true;
                break;
            }
        }

        $this->assertTrue($hasStateIndex, 'Contract table should have index on OXSTATE');
    }

    public function testContractTableHasNoForeignKeysToOxidCoreTables(): void
    {
        $foreignKeys = $this->getTableForeignKeys('oe_payments_contract');

        // Verify NO foreign keys to OXID core tables (oxuser, oxorder)
        // This allows TRUNCATE during demodata installation
        foreach ($foreignKeys as $fk) {
            $this->assertNotEquals('oxuser', $fk['referenced_table'],
                'Contract table should NOT have FK to oxuser (blocks TRUNCATE)');
            $this->assertNotEquals('oxorder', $fk['referenced_table'],
                'Contract table should NOT have FK to oxorder (blocks TRUNCATE)');
        }

        // Referential integrity is maintained at application level
        // Indexes on OXUSERID and OXORDERID provide query performance
        $this->assertTrue(true, 'No foreign keys to core tables - correct!');
    }

    // ==================== TRANSACTION TABLE STRUCTURE ====================

    public function testTransactionTableHasRequiredColumns(): void
    {
        $columns = $this->getTableColumns('oe_payments_transaction');

        $expectedColumns = [
            'OXID', 'OXSHOPID', 'OXORDERID', 'OXCONTRACTID', 'OXPROVIDER',
            'OXPROVIDERORDERID', 'OXTRANSACTIONID', 'OXTYPE', 'OXSTATUS',
            'OXAMOUNT', 'OXCURRENCY', 'OXPAYMENTMETHODID', 'OXPAYMENTMETHODTYPE',
            'OXPARENTTRANSACTIONID', 'OXCREATED', 'OXUPDATED'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Transaction table should have column {$column}"
            );
        }
    }

    public function testTransactionTableHasForeignKeyToContract(): void
    {
        $foreignKeys = $this->getTableForeignKeys('oe_payments_transaction');

        $hasContractFK = false;
        foreach ($foreignKeys as $fk) {
            if ($fk['column'] === 'OXCONTRACTID' && $fk['referenced_table'] === 'oe_payments_contract') {
                $hasContractFK = true;
                break;
            }
        }

        $this->assertTrue($hasContractFK, 'Transaction table should have FK to oe_payments_contract');
    }

    public function testTransactionTableHasSelfReferencingFK(): void
    {
        $foreignKeys = $this->getTableForeignKeys('oe_payments_transaction');

        $hasSelfFK = false;
        foreach ($foreignKeys as $fk) {
            if ($fk['column'] === 'OXPARENTTRANSACTIONID' && $fk['referenced_table'] === 'oe_payments_transaction') {
                $hasSelfFK = true;
                break;
            }
        }

        $this->assertTrue($hasSelfFK, 'Transaction table should have self-referencing FK for parent transactions');
    }

    // ==================== SPRINT 8: CONTRACT CAPTURE/REFUND COLUMNS ====================

    public function testContractTableHasCaptureRefundColumns(): void
    {
        // Sprint 8: oe_payments_stripe_order_state table DROPPED
        // Capture/refund tracking now in oe_payments_contract
        $columns = $this->getTableColumns('oe_payments_contract');

        $captureRefundColumns = [
            'OXCAPTUREDAMOUNT', 'OXREFUNDEDAMOUNT', 'OXCAPTUREDAT', 'OXREFUNDEDAT'
        ];

        foreach ($captureRefundColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Contract table should have capture/refund column {$column} (Sprint 8)"
            );
        }
    }

    // ==================== CUSTOMER TABLE STRUCTURE ====================
    // Note: Customer, Idempotency, Sessions tables are provider-agnostic (from payment-component)

    public function testCustomerTableHasRequiredColumns(): void
    {
        $columns = $this->getTableColumns('oe_payments_customer');

        $expectedColumns = [
            'OXID', 'OXUSERID', 'OXPAYMENTCUSTOMERID', 'OXDEFAULTPAYMENTMETHOD',
            'OXSAVEDPAYMENTMETHODS', 'OXBILLINGAGREEMENT', 'OXLASTPAYMENTDATE',
            'OXCREATED', 'OXUPDATED'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Customer table should have column {$column}"
            );
        }
    }

    public function testCustomerTableHasUniqueIndexOnUserId(): void
    {
        $indexes = $this->getTableIndexes('oe_payments_customer');

        $hasUniqueUserIndex = false;
        foreach ($indexes as $indexName => $indexData) {
            if (in_array('OXUSERID', $indexData['columns']) && $indexData['unique']) {
                $hasUniqueUserIndex = true;
                break;
            }
        }

        $this->assertTrue($hasUniqueUserIndex, 'Customer table should have unique index on OXUSERID');
    }

    // ==================== IDEMPOTENCY TABLE STRUCTURE ====================

    public function testIdempotencyTableHasRequiredColumns(): void
    {
        $columns = $this->getTableColumns('oe_payments_idempotency');

        $expectedColumns = [
            'OXID', 'OXKEY', 'OXORDERID', 'OXOPERATION',
            'OXRESULT', 'OXSTATUS', 'OXCREATED', 'OXEXPIRES'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Idempotency table should have column {$column}"
            );
        }
    }

    public function testIdempotencyTableHasUniqueIndexOnKey(): void
    {
        $indexes = $this->getTableIndexes('oe_payments_idempotency');

        $hasUniqueKeyIndex = false;
        foreach ($indexes as $indexName => $indexData) {
            if (in_array('OXKEY', $indexData['columns']) && $indexData['unique']) {
                $hasUniqueKeyIndex = true;
                break;
            }
        }

        $this->assertTrue($hasUniqueKeyIndex, 'Idempotency table should have unique index on OXKEY');
    }

    // ==================== SESSIONS TABLE STRUCTURE ====================

    public function testSessionsTableHasRequiredColumns(): void
    {
        $columns = $this->getTableColumns('oe_payments_sessions');

        $expectedColumns = [
            'OXID', 'OXPROVIDER', 'OXSESSIONID', 'OXUSERID',
            'OXBASKETID', 'OXDATA', 'OXCREATED', 'OXEXPIRES'
        ];

        foreach ($expectedColumns as $column) {
            $this->assertContains(
                $column,
                $columns,
                "Sessions table should have column {$column}"
            );
        }
    }

    // ==================== HELPER METHODS ====================

    private function tableExists(string $tableName): bool
    {
        $sql = "SHOW TABLES LIKE ?";
        $result = $this->connection->fetchOne($sql, [$tableName]);

        return $result !== false;
    }

    /**
     * @return string[]
     */
    private function getTableColumns(string $tableName): array
    {
        $sql = "SHOW COLUMNS FROM {$tableName}";
        $columns = $this->connection->fetchAllAssociative($sql);

        return array_column($columns, 'Field');
    }

    /**
     * @return array<string, array{columns: string[], unique: bool}>
     */
    private function getTableIndexes(string $tableName): array
    {
        $sql = "SHOW INDEX FROM {$tableName}";
        $rows = $this->connection->fetchAllAssociative($sql);

        $indexes = [];
        foreach ($rows as $row) {
            $indexName = $row['Key_name'];
            if (!isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'columns' => [],
                    'unique' => $row['Non_unique'] == 0
                ];
            }
            $indexes[$indexName]['columns'][] = $row['Column_name'];
        }

        return $indexes;
    }

    /**
     * @return array<array{column: string, referenced_table: string, referenced_column: string}>
     */
    private function getTableForeignKeys(string $tableName): array
    {
        $sql = "
            SELECT
                COLUMN_NAME as column_name,
                REFERENCED_TABLE_NAME as referenced_table,
                REFERENCED_COLUMN_NAME as referenced_column
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ";

        $rows = $this->connection->fetchAllAssociative($sql, [$tableName]);

        return array_map(function ($row) {
            return [
                'column' => $row['column_name'],
                'referenced_table' => $row['referenced_table'],
                'referenced_column' => $row['referenced_column']
            ];
        }, $rows);
    }
}
