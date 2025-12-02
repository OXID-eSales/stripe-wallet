<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sprint 2: Database Architecture Cleanup & Table Consolidation
 *
 * This migration:
 * 1. Adds OXPROVIDER and OXPAYLOAD columns to osc_payment_webhooklogs
 * 2. Adds OXPROCESSEDAT column to osc_payment_webhooklogs
 * 3. Migrates data from osc_stripe_customer_mapping to osc_payment_customer (if exists)
 * 4. Migrates data from osc_payment_webhook_log to osc_payment_webhooklogs (if exists)
 * 5. Drops redundant tables after migration
 *
 * Tables consolidated:
 * - osc_stripe_customer_mapping -> osc_payment_customer (provider-agnostic)
 * - osc_payment_webhook_log -> osc_payment_webhooklogs (provider-agnostic)
 * - osc_stripe_payment_details -> DROPPED (unused, Stripe wallet handles card data)
 */
final class Version20251202_Sprint2TableConsolidation extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 2: Consolidate duplicate tables and add provider fields to webhook logs';
    }

    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');

        // Step 1: Add missing columns to osc_payment_webhooklogs
        $this->addWebhookLogColumns($schema);

        // Step 2: Migrate customer data from Stripe-specific table
        $this->migrateCustomerData();

        // Step 3: Migrate webhook data from old table
        $this->migrateWebhookData();

        // Step 4: Drop redundant tables
        $this->dropRedundantTables();
    }

    public function down(Schema $schema): void
    {
        // Remove added columns from osc_payment_webhooklogs
        if ($schema->hasTable('osc_payment_webhooklogs')) {
            $table = $schema->getTable('osc_payment_webhooklogs');

            if ($table->hasColumn('OXPROVIDER')) {
                $table->dropColumn('OXPROVIDER');
            }

            if ($table->hasColumn('OXPAYLOAD')) {
                $table->dropColumn('OXPAYLOAD');
            }

            if ($table->hasColumn('OXPROCESSEDAT')) {
                $table->dropColumn('OXPROCESSEDAT');
            }
        }

        // Note: Dropped tables cannot be automatically restored
        // A full backup should be taken before running this migration
    }

    /**
     * Add provider and payload columns to the webhook logs table
     */
    private function addWebhookLogColumns(Schema $schema): void
    {
        if (!$schema->hasTable('osc_payment_webhooklogs')) {
            return;
        }

        $table = $schema->getTable('osc_payment_webhooklogs');

        // Add OXPROVIDER column if not exists
        if (!$table->hasColumn('OXPROVIDER')) {
            $table->addColumn('OXPROVIDER', Types::STRING, [
                'columnDefinition' => 'VARCHAR(32) NULL',
                'notnull' => false,
                'comment' => 'Payment provider (stripe, paypal, etc.)'
            ]);
        }

        // Add OXPAYLOAD column if not exists
        if (!$table->hasColumn('OXPAYLOAD')) {
            $table->addColumn('OXPAYLOAD', Types::TEXT, [
                'notnull' => false,
                'comment' => 'Full webhook payload (JSON)'
            ]);
        }

        // Add OXPROCESSEDAT column if not exists
        if (!$table->hasColumn('OXPROCESSEDAT')) {
            $table->addColumn('OXPROCESSEDAT', Types::DATETIME_MUTABLE, [
                'notnull' => false,
                'comment' => 'When webhook was processed'
            ]);
        }
    }

    /**
     * Migrate data from osc_stripe_customer_mapping to osc_payment_customer
     */
    private function migrateCustomerData(): void
    {
        // Check if source table exists
        $sourceExists = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'osc_stripe_customer_mapping'"
        );

        if (!$sourceExists) {
            return;
        }

        // Migrate data: INSERT ... ON DUPLICATE KEY UPDATE
        $this->addSql("
            INSERT INTO osc_payment_customer (OXID, OXUSERID, OXPAYMENTCUSTOMERID, OXCREATED, OXUPDATED)
            SELECT
                OXID,
                OXUSERID,
                OXSTRIPECUSTOMERID,
                COALESCE(OXCREATED, NOW()),
                COALESCE(OXUPDATED, NOW())
            FROM osc_stripe_customer_mapping
            ON DUPLICATE KEY UPDATE
                OXPAYMENTCUSTOMERID = VALUES(OXPAYMENTCUSTOMERID),
                OXUPDATED = VALUES(OXUPDATED)
        ");
    }

    /**
     * Migrate data from osc_payment_webhook_log to osc_payment_webhooklogs
     */
    private function migrateWebhookData(): void
    {
        // Check if source table exists
        $sourceExists = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'osc_payment_webhook_log'"
        );

        if (!$sourceExists) {
            return;
        }

        // Migrate data: INSERT ... ON DUPLICATE KEY UPDATE
        $this->addSql("
            INSERT INTO osc_payment_webhooklogs (OXID, OXEVENTID, OXEVENTTYPE, OXSTATUS, OXRECEIVEDAT, OXERROR, OXPROVIDER, OXPAYLOAD)
            SELECT
                OXID,
                OXEVENTID,
                OXEVENTTYPE,
                OXSTATUS,
                COALESCE(OXCREATED, NOW()),
                OXERRORMESSAGE,
                OXPROVIDER,
                OXPAYLOAD
            FROM osc_payment_webhook_log
            ON DUPLICATE KEY UPDATE
                OXEVENTTYPE = VALUES(OXEVENTTYPE),
                OXSTATUS = VALUES(OXSTATUS),
                OXPROVIDER = VALUES(OXPROVIDER),
                OXPAYLOAD = VALUES(OXPAYLOAD)
        ");
    }

    /**
     * Drop tables that have been consolidated or are unused
     */
    private function dropRedundantTables(): void
    {
        // Drop osc_stripe_customer_mapping (data migrated to osc_payment_customer)
        $this->addSql("DROP TABLE IF EXISTS osc_stripe_customer_mapping");

        // Drop osc_payment_webhook_log (data migrated to osc_payment_webhooklogs)
        $this->addSql("DROP TABLE IF EXISTS osc_payment_webhook_log");

        // Drop osc_stripe_payment_details (unused - Stripe wallet handles card data)
        $this->addSql("DROP TABLE IF EXISTS osc_stripe_payment_details");
    }
}
