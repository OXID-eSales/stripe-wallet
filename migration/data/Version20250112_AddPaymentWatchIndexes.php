<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add database indexes for PaymentWatch performance optimization
 *
 * These indexes optimize the most common PaymentWatch queries:
 * - Contract state lookups by OXID
 * - Transaction status checks by OXID
 * - Provider order ID searches
 * - User contract queries
 *
 * phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
 * @SuppressWarnings(PHPMD)
 */
final class Version20250112_AddPaymentWatchIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for PaymentWatch queries';
    }

    public function up(Schema $schema): void
    {
        // Check if tables exist before creating indexes
        if (!$this->tableExists('osc_payment_contract')) {
            $this->write('Table osc_payment_contract does not exist yet, skipping indexes');
            return;
        }

        if (!$this->tableExists('osc_payment_transaction')) {
            $this->write('Table osc_payment_transaction does not exist yet, skipping indexes');
            return;
        }

        // Payment Contract Indexes
        // Note: Using direct SQL to avoid ENUM type issues with Doctrine schema introspection
        // MySQL doesn't support IF NOT EXISTS for CREATE INDEX, so we check first

        $this->createIndexIfNotExists('osc_payment_contract', 'idx_pw_contract_state', 'OXSTATE');
        $this->createIndexIfNotExists('osc_payment_contract', 'idx_pw_contract_provider_order', 'OXPROVIDERORDERID');
        $this->createIndexIfNotExists('osc_payment_contract', 'idx_pw_contract_order', 'OXORDERID');
        $this->createIndexIfNotExists('osc_payment_contract', 'idx_pw_contract_user', 'OXUSERID');
        $this->createCompositeIndexIfNotExists('osc_payment_contract', 'idx_pw_contract_id_state', 'OXID, OXSTATE');

        // Payment Transaction Indexes
        $this->createIndexIfNotExists('osc_payment_transaction', 'idx_pw_transaction_status', 'OXSTATUS');
        $this->createIndexIfNotExists('osc_payment_transaction', 'idx_pw_transaction_contract', 'OXCONTRACTID');
        $this->createIndexIfNotExists('osc_payment_transaction', 'idx_pw_transaction_provider_order', 'OXPROVIDERORDERID');
        $this->createIndexIfNotExists('osc_payment_transaction', 'idx_pw_transaction_type', 'OXTYPE');
        $this->createCompositeIndexIfNotExists('osc_payment_transaction', 'idx_pw_transaction_contract_status', 'OXCONTRACTID, OXSTATUS');

        $this->write('PaymentWatch performance indexes added successfully');
    }

    public function down(Schema $schema): void
    {
        // Remove indexes on rollback
        $this->dropIndexIfExists('osc_payment_contract', 'idx_pw_contract_state');
        $this->dropIndexIfExists('osc_payment_contract', 'idx_pw_contract_provider_order');
        $this->dropIndexIfExists('osc_payment_contract', 'idx_pw_contract_order');
        $this->dropIndexIfExists('osc_payment_contract', 'idx_pw_contract_user');
        $this->dropIndexIfExists('osc_payment_contract', 'idx_pw_contract_id_state');
        $this->write('Removed PaymentWatch indexes from osc_payment_contract');

        $this->dropIndexIfExists('osc_payment_transaction', 'idx_pw_transaction_status');
        $this->dropIndexIfExists('osc_payment_transaction', 'idx_pw_transaction_contract');
        $this->dropIndexIfExists('osc_payment_transaction', 'idx_pw_transaction_provider_order');
        $this->dropIndexIfExists('osc_payment_transaction', 'idx_pw_transaction_type');
        $this->dropIndexIfExists('osc_payment_transaction', 'idx_pw_transaction_contract_status');
        $this->write('Removed PaymentWatch indexes from osc_payment_transaction');
    }

    private function createIndexIfNotExists(string $table, string $indexName, string $columns): void
    {
        if ($this->indexExists($table, $indexName)) {
            $this->write("Index {$indexName} already exists, skipping");
            return;
        }

        $this->addSql("CREATE INDEX {$indexName} ON {$table}({$columns})");
        $this->write("Added index: {$indexName}");
    }

    private function createCompositeIndexIfNotExists(string $table, string $indexName, string $columns): void
    {
        if ($this->indexExists($table, $indexName)) {
            $this->write("Index {$indexName} already exists, skipping");
            return;
        }

        $this->addSql("CREATE INDEX {$indexName} ON {$table}({$columns})");
        $this->write("Added index: {$indexName}");
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if ($this->indexExists($table, $indexName)) {
            $this->addSql("DROP INDEX {$indexName} ON {$table}");
        }
    }

    private function tableExists(string $table): bool
    {
        $sql = "SELECT COUNT(*) as count
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
                AND table_name = :table";

        $result = $this->connection->fetchAssociative($sql, [
            'table' => $table
        ]);

        return ($result['count'] ?? 0) > 0;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $sql = "SELECT COUNT(*) as count
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                AND table_name = :table
                AND index_name = :index";

        $result = $this->connection->fetchAssociative($sql, [
            'table' => $table,
            'index' => $indexName
        ]);

        return ($result['count'] ?? 0) > 0;
    }
}
