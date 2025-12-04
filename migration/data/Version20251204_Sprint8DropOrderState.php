<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 8: Drop osc_payment_order_state and add capture/refund tracking to contract
 *
 * This migration:
 * 1. Adds OXCAPTUREDAMOUNT, OXREFUNDEDAMOUNT, OXCAPTUREDAT, OXREFUNDEDAT to osc_payment_contract
 * 2. Removes the foreign key constraint FK_ORDER_STATE_CONTRACT
 * 3. Drops the redundant osc_payment_order_state table
 *
 * Rationale:
 * - osc_payment_order_state was redundant with osc_payment_contract
 * - OXPAYMENTSTATE duplicated contract.OXSTATE
 * - OXPROVIDERORDERID was duplicated
 * - PaymentOrderStateRepository was dead code (never instantiated)
 * - Consolidating all payment state into contract simplifies architecture
 */
final class Version20251204_Sprint8DropOrderState extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 8: Add capture/refund fields to contract and drop redundant osc_payment_order_state table';
    }

    public function up(Schema $schema): void
    {
        // Step 1: Add capture/refund tracking columns to contract table
        $this->addSql("
            ALTER TABLE osc_payment_contract
            ADD COLUMN OXCAPTUREDAMOUNT DECIMAL(10,2) DEFAULT NULL
                COMMENT 'Captured payment amount' AFTER OXFULFILLEDAT,
            ADD COLUMN OXREFUNDEDAMOUNT DECIMAL(10,2) DEFAULT NULL
                COMMENT 'Total refunded amount (accumulates)' AFTER OXCAPTUREDAMOUNT,
            ADD COLUMN OXCAPTUREDAT DATETIME DEFAULT NULL
                COMMENT 'When payment was captured' AFTER OXREFUNDEDAMOUNT,
            ADD COLUMN OXREFUNDEDAT DATETIME DEFAULT NULL
                COMMENT 'When last refund was processed' AFTER OXCAPTUREDAT
        ");

        $this->write('Added capture/refund tracking columns to osc_payment_contract');

        // Step 2: Drop foreign key from order_state to contract (if exists)
        $this->dropForeignKeyIfExists('osc_payment_order_state', 'FK_ORDER_STATE_CONTRACT');

        // Step 3: Drop the redundant order_state table
        $this->addSql("DROP TABLE IF EXISTS osc_payment_order_state");

        $this->write('Dropped redundant osc_payment_order_state table');
    }

    public function down(Schema $schema): void
    {
        // Step 1: Recreate the order_state table
        $this->addSql("
            CREATE TABLE IF NOT EXISTS osc_payment_order_state (
                OXID CHAR(32) COLLATE latin1_general_ci NOT NULL,
                OXORDERID CHAR(32) COLLATE latin1_general_ci NOT NULL,
                OXCONTRACTID CHAR(32) COLLATE latin1_general_ci NULL,
                OXPAYMENTSTATE VARCHAR(32) NOT NULL,
                OXPROVIDERORDERID VARCHAR(128) NULL,
                OXWEBHOOKWAITSINCE DATETIME NULL,
                OXWEBHOOKTIMEOUT INT NULL,
                OXLASTPAYMENTATTEMPT DATETIME NULL,
                OXPAYMENTATTEMPTCOUNT INT NOT NULL DEFAULT 0,
                OXCREATED DATETIME NOT NULL,
                OXUPDATED DATETIME NOT NULL,
                PRIMARY KEY (OXID),
                UNIQUE KEY UK_ORDER (OXORDERID),
                KEY IDX_PAYMENT_STATE (OXPAYMENTSTATE),
                KEY IDX_PROVIDER_ORDER (OXPROVIDERORDERID),
                KEY IDX_CONTRACT (OXCONTRACTID),
                CONSTRAINT FK_ORDER_STATE_CONTRACT FOREIGN KEY (OXCONTRACTID)
                    REFERENCES osc_payment_contract (OXID) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->write('Recreated osc_payment_order_state table');

        // Step 2: Remove capture/refund columns from contract
        $this->addSql("
            ALTER TABLE osc_payment_contract
            DROP COLUMN IF EXISTS OXCAPTUREDAMOUNT,
            DROP COLUMN IF EXISTS OXREFUNDEDAMOUNT,
            DROP COLUMN IF EXISTS OXCAPTUREDAT,
            DROP COLUMN IF EXISTS OXREFUNDEDAT
        ");

        $this->write('Removed capture/refund columns from osc_payment_contract');
    }

    /**
     * Drop foreign key if it exists
     */
    private function dropForeignKeyIfExists(string $tableName, string $fkName): void
    {
        $sql = "
            SELECT COUNT(*) as fk_count
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
            AND CONSTRAINT_NAME = :fkName
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            AND TABLE_NAME = :tableName
        ";

        $result = $this->connection->fetchOne($sql, [
            'fkName' => $fkName,
            'tableName' => $tableName
        ]);

        if ($result > 0) {
            $this->addSql("ALTER TABLE {$tableName} DROP FOREIGN KEY {$fkName}");
            $this->write("Dropped foreign key {$fkName} from {$tableName}");
        }
    }
}
