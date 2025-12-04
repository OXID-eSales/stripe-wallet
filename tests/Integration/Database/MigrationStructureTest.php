<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Database;

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

        // Load database configuration
        $dbConfig = require __DIR__ . '/../../../migration/migrations-db.php';

        // Create DBAL connection
        $config = new Configuration();
        $this->connection = DriverManager::getConnection($dbConfig, $config);
    }

    // ==================== TABLE EXISTENCE TESTS ====================

    public function testContractTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('osc_payment_contract'),
            'Table osc_payment_contract should exist'
        );
    }

    public function testTransactionTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('osc_payment_transaction'),
            'Table osc_payment_transaction should exist'
        );
    }

    public function testOrderStateTableDropped(): void
    {
        // Sprint 8: osc_payment_order_state table was DROPPED
        // Capture/refund tracking now handled by osc_payment_contract fields
        $this->assertFalse(
            $this->tableExists('osc_payment_order_state'),
            'Table osc_payment_order_state should NOT exist (dropped in Sprint 8)'
        );
    }

    public function testCustomerTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('osc_payment_customer'),
            'Table osc_payment_customer should exist'
        );
    }

    public function testIdempotencyTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('osc_payment_idempotency'),
            'Table osc_payment_idempotency should exist'
        );
    }

    public function testSessionsTableExists(): void
    {
        $this->assertTrue(
            $this->tableExists('osc_payment_sessions'),
            'Table osc_payment_sessions should exist'
        );
    }

    public function testWebhookLogsTableExists(): void
    {
        $this->markTestSkipped('Webhooklogs table migration not yet implemented - TODO: Add in future migration');

        $this->assertTrue(
            $this->tableExists('osc_payment_webhooklogs'),
            'Table osc_payment_webhooklogs should exist'
        );
    }

    // ==================== CONTRACT TABLE STRUCTURE ====================

    public function testContractTableHasRequiredColumns(): void
    {
        $columns = $this->getTableColumns('osc_payment_contract');

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
        $indexes = $this->getTableIndexes('osc_payment_contract');

        $this->assertArrayHasKey('PRIMARY', $indexes, 'Contract table should have PRIMARY key');
        $this->assertContains('OXID', $indexes['PRIMARY']['columns'], 'PRIMARY key should be on OXID');
    }

    public function testContractTableHasStateIndex(): void
    {
        $indexes = $this->getTableIndexes('osc_payment_contract');

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
        $foreignKeys = $this->getTableForeignKeys('osc_payment_contract');

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
        $columns = $this->getTableColumns('osc_payment_transaction');

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
        $foreignKeys = $this->getTableForeignKeys('osc_payment_transaction');

        $hasContractFK = false;
        foreach ($foreignKeys as $fk) {
            if ($fk['column'] === 'OXCONTRACTID' && $fk['referenced_table'] === 'osc_payment_contract') {
                $hasContractFK = true;
                break;
            }
        }

        $this->assertTrue($hasContractFK, 'Transaction table should have FK to osc_payment_contract');
    }

    public function testTransactionTableHasSelfReferencingFK(): void
    {
        $foreignKeys = $this->getTableForeignKeys('osc_payment_transaction');

        $hasSelfFK = false;
        foreach ($foreignKeys as $fk) {
            if ($fk['column'] === 'OXPARENTTRANSACTIONID' && $fk['referenced_table'] === 'osc_payment_transaction') {
                $hasSelfFK = true;
                break;
            }
        }

        $this->assertTrue($hasSelfFK, 'Transaction table should have self-referencing FK for parent transactions');
    }

    // ==================== SPRINT 8: CONTRACT CAPTURE/REFUND COLUMNS ====================

    public function testContractTableHasCaptureRefundColumns(): void
    {
        // Sprint 8: osc_payment_order_state table DROPPED
        // Capture/refund tracking now in osc_payment_contract
        $columns = $this->getTableColumns('osc_payment_contract');

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

    public function testCustomerTableHasRequiredColumns(): void
    {
        $columns = $this->getTableColumns('osc_payment_customer');

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
        $indexes = $this->getTableIndexes('osc_payment_customer');

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
        $columns = $this->getTableColumns('osc_payment_idempotency');

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
        $indexes = $this->getTableIndexes('osc_payment_idempotency');

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
        $columns = $this->getTableColumns('osc_payment_sessions');

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

    // ==================== PAYMENTWATCH INDEXES (Version20250112) ====================
    // Note: These indexes are planned for PaymentWatch feature (future version)
    // Tests are skipped until the migration is applied

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testContractTableHasPaymentWatchStateIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_contract');

        $this->assertArrayHasKey(
            'idx_pw_contract_state',
            $indexes,
            'Contract table should have PaymentWatch state index (idx_pw_contract_state)'
        );

        $this->assertContains(
            'OXSTATE',
            $indexes['idx_pw_contract_state']['columns'],
            'idx_pw_contract_state should index OXSTATE column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testContractTableHasPaymentWatchProviderOrderIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_contract');

        $this->assertArrayHasKey(
            'idx_pw_contract_provider_order',
            $indexes,
            'Contract table should have PaymentWatch provider order index (idx_pw_contract_provider_order)'
        );

        $this->assertContains(
            'OXPROVIDERORDERID',
            $indexes['idx_pw_contract_provider_order']['columns'],
            'idx_pw_contract_provider_order should index OXPROVIDERORDERID column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testContractTableHasPaymentWatchOrderIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_contract');

        $this->assertArrayHasKey(
            'idx_pw_contract_order',
            $indexes,
            'Contract table should have PaymentWatch order index (idx_pw_contract_order)'
        );

        $this->assertContains(
            'OXORDERID',
            $indexes['idx_pw_contract_order']['columns'],
            'idx_pw_contract_order should index OXORDERID column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testContractTableHasPaymentWatchUserIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_contract');

        $this->assertArrayHasKey(
            'idx_pw_contract_user',
            $indexes,
            'Contract table should have PaymentWatch user index (idx_pw_contract_user)'
        );

        $this->assertContains(
            'OXUSERID',
            $indexes['idx_pw_contract_user']['columns'],
            'idx_pw_contract_user should index OXUSERID column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testContractTableHasPaymentWatchCompositeIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_contract');

        $this->assertArrayHasKey(
            'idx_pw_contract_id_state',
            $indexes,
            'Contract table should have PaymentWatch composite index (idx_pw_contract_id_state)'
        );

        $this->assertContains(
            'OXID',
            $indexes['idx_pw_contract_id_state']['columns'],
            'idx_pw_contract_id_state should include OXID column'
        );

        $this->assertContains(
            'OXSTATE',
            $indexes['idx_pw_contract_id_state']['columns'],
            'idx_pw_contract_id_state should include OXSTATE column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testTransactionTableHasPaymentWatchStatusIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_transaction');

        $this->assertArrayHasKey(
            'idx_pw_transaction_status',
            $indexes,
            'Transaction table should have PaymentWatch status index (idx_pw_transaction_status)'
        );

        $this->assertContains(
            'OXSTATUS',
            $indexes['idx_pw_transaction_status']['columns'],
            'idx_pw_transaction_status should index OXSTATUS column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testTransactionTableHasPaymentWatchContractIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_transaction');

        $this->assertArrayHasKey(
            'idx_pw_transaction_contract',
            $indexes,
            'Transaction table should have PaymentWatch contract index (idx_pw_transaction_contract)'
        );

        $this->assertContains(
            'OXCONTRACTID',
            $indexes['idx_pw_transaction_contract']['columns'],
            'idx_pw_transaction_contract should index OXCONTRACTID column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testTransactionTableHasPaymentWatchProviderOrderIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_transaction');

        $this->assertArrayHasKey(
            'idx_pw_transaction_provider_order',
            $indexes,
            'Transaction table should have PaymentWatch provider order index (idx_pw_transaction_provider_order)'
        );

        $this->assertContains(
            'OXPROVIDERORDERID',
            $indexes['idx_pw_transaction_provider_order']['columns'],
            'idx_pw_transaction_provider_order should index OXPROVIDERORDERID column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testTransactionTableHasPaymentWatchTypeIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_transaction');

        $this->assertArrayHasKey(
            'idx_pw_transaction_type',
            $indexes,
            'Transaction table should have PaymentWatch type index (idx_pw_transaction_type)'
        );

        $this->assertContains(
            'OXTYPE',
            $indexes['idx_pw_transaction_type']['columns'],
            'idx_pw_transaction_type should index OXTYPE column'
        );
    }

    /**
     * @test
     * @group watch
     * @group future
     */
    public function testTransactionTableHasPaymentWatchCompositeIndex(): void
    {
        $this->markTestSkipped('PaymentWatch indexes not yet implemented (planned for Version20250112)');

        $indexes = $this->getTableIndexes('osc_payment_transaction');

        $this->assertArrayHasKey(
            'idx_pw_transaction_contract_status',
            $indexes,
            'Transaction table should have PaymentWatch composite index (idx_pw_transaction_contract_status)'
        );

        $this->assertContains(
            'OXCONTRACTID',
            $indexes['idx_pw_transaction_contract_status']['columns'],
            'idx_pw_transaction_contract_status should include OXCONTRACTID column'
        );

        $this->assertContains(
            'OXSTATUS',
            $indexes['idx_pw_transaction_contract_status']['columns'],
            'idx_pw_transaction_contract_status should include OXSTATUS column'
        );
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
