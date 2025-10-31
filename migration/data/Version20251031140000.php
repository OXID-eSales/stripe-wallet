<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Create osc_payment_contract table (PRIMARY - Provider-Agnostic)
 *
 * This is the main contract table that tracks payment lifecycle from intent to fulfillment.
 * Provider-agnostic design supports Stripe, PayPal, Amazon Pay, Unzer, Adyen, Klarna, etc.
 */
final class Version20251031140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create osc_payment_contract table - primary contract lifecycle management (provider-agnostic)';
    }

    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');
        $this->createPaymentContractTable($schema);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('osc_payment_contract')) {
            $schema->dropTable('osc_payment_contract');
        }
    }

    /**
     * @throws SchemaException
     */
    private function createPaymentContractTable(Schema $schema): void
    {
        $tableName = 'osc_payment_contract';

        if ($schema->hasTable($tableName)) {
            return;
        }

        $table = $schema->createTable($tableName);

        // Primary key
        $table->addColumn('OXID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'Contract ID (UUID)'
        ]);

        // Shop & user references
        $table->addColumn('OXSHOPID', Types::INTEGER, [
            'notnull' => true,
            'comment' => 'Shop ID (multi-shop support)'
        ]);

        $table->addColumn('OXUSERID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NOT NULL',
            'comment' => 'FK to oxuser.OXID'
        ]);

        $table->addColumn('OXORDERID', Types::STRING, [
            'columnDefinition' => 'CHAR(32) COLLATE latin1_general_ci NULL',
            'notnull' => false,
            'comment' => 'FK to oxorder.OXID (NULL until committed!)'
        ]);

        // Contract state machine
        $table->addColumn('OXSTATE', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NOT NULL',
            'comment' => 'draft, pending, ready_to_commit, committed, fulfilled, cancelled, expired, failed'
        ]);

        $table->addColumn('OXSTATEREASON', Types::STRING, [
            'columnDefinition' => 'VARCHAR(255) NULL',
            'notnull' => false,
            'comment' => 'Reason for state (if failed/cancelled)'
        ]);

        // Snapshot data (immutable)
        $table->addColumn('OXBASKETDATA', Types::TEXT, [
            'notnull' => true,
            'comment' => 'Complete basket snapshot (JSON: items, discounts, totals)'
        ]);

        $table->addColumn('OXTERMS', Types::TEXT, [
            'notnull' => false,
            'comment' => 'Terms & conditions agreed by customer (JSON)'
        ]);

        $table->addColumn('OXMETADATA', Types::TEXT, [
            'notnull' => false,
            'comment' => 'Additional metadata (JSON: IP, user agent, session ID)'
        ]);

        // Fulfillment conditions
        $table->addColumn('OXCONDITIONS', Types::TEXT, [
            'notnull' => true,
            'comment' => 'Array of conditions with status (JSON: payment_authorized, fraud_check, etc.)'
        ]);

        // Provider data (provider-agnostic)
        $table->addColumn('OXPROVIDER', Types::STRING, [
            'columnDefinition' => 'VARCHAR(32) NULL',
            'notnull' => false,
            'comment' => 'Payment provider: stripe, paypal, unzer, adyen, klarna, amazonpay'
        ]);

        $table->addColumn('OXPROVIDERORDERID', Types::STRING, [
            'columnDefinition' => 'VARCHAR(128) NULL',
            'notnull' => false,
            'comment' => 'Provider contract ID (PaymentIntent ID, Order ID, ChargePermission ID)'
        ]);

        $table->addColumn('OXPROVIDERDATA', Types::TEXT, [
            'notnull' => false,
            'comment' => 'Provider-specific data (JSON)'
        ]);

        // Timestamps
        $table->addColumn('OXCREATED', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL',
            'comment' => 'Contract creation timestamp'
        ]);

        $table->addColumn('OXUPDATED', Types::DATETIME_MUTABLE, [
            'columnDefinition' => 'DATETIME NOT NULL',
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

        // Primary key
        $table->setPrimaryKey(['OXID']);

        // Indexes for performance
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

        $table->addOption('engine', 'InnoDB');
        $table->addOption('charset', 'latin1');
        $table->addOption('collate', 'latin1_general_ci');
        $table->addOption('comment', 'Payment contract lifecycle - Provider-agnostic v4.0');
    }
}
