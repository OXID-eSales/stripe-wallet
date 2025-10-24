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
 * Migration: Create Stripe payment transaction table
 */
final class Version20251024140000 extends AbstractMigration
{
    //The migration done here creates a new table
    //NOTE: write migrations so that they can be run multiple times without breaking anything.
    //      Means: check if changes are already present before actually creating a table
    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');

        //add payment transaction table
        $this->createPaymentTransactionTable($schema);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }

    /**
     * create a stripe payment transaction table
     * @throws SchemaException
     */
    private function createPaymentTransactionTable(Schema $schema): void
    {
        $tableName = 'osc_stripe_payment_transaction';

        if (!$schema->hasTable($tableName)) {
            $table = $schema->createTable($tableName);
        } else {
            $table = $schema->getTable($tableName);
        }

        if (!$table->hasColumn('OXID')) {
            $table->addColumn(
                'OXID',
                Types::STRING,
                ['columnDefinition' => 'char(32) collate latin1_general_ci']
            );
        }

        if (!$table->hasColumn('TRANSACTION_ID')) {
            $table->addColumn(
                'TRANSACTION_ID',
                Types::STRING,
                [
                    'columnDefinition' => 'varchar(255) collate latin1_general_ci',
                    'comment' => 'Stripe Transaction ID'
                ]
            );
        }

        if (!$table->hasColumn('OXORDERID')) {
            $table->addColumn(
                'OXORDERID',
                Types::STRING,
                [
                    'columnDefinition' => 'char(32) collate latin1_general_ci',
                    'comment' => 'OXID Order id (oxorder)'
                ]
            );
        }

        if (!$table->hasColumn('AMOUNT')) {
            $table->addColumn(
                'AMOUNT',
                Types::FLOAT,
                [
                    'columnDefinition' => 'DOUBLE NOT NULL DEFAULT 0',
                    'comment' => 'Transaction amount'
                ]
            );
        }

        if (!$table->hasColumn('CURRENCY')) {
            $table->addColumn(
                'CURRENCY',
                Types::STRING,
                [
                    'columnDefinition' => 'char(3) collate latin1_general_ci',
                    'comment' => 'Currency code (ISO 4217)'
                ]
            );
        }

        if (!$table->hasColumn('STATUS')) {
            $table->addColumn(
                'STATUS',
                Types::STRING,
                [
                    'columnDefinition' => 'varchar(50) collate latin1_general_ci',
                    'comment' => 'Transaction status'
                ]
            );
        }

        if (!$table->hasColumn('OXCREATED')) {
            $table->addColumn(
                'OXCREATED',
                Types::DATETIME_MUTABLE,
                ['columnDefinition' => 'timestamp default current_timestamp']
            );
        }

        if (!$table->hasColumn('OXTIMESTAMP')) {
            $table->addColumn(
                'OXTIMESTAMP',
                Types::DATETIME_MUTABLE,
                ['columnDefinition' => 'timestamp default current_timestamp on update current_timestamp']
            );
        }

        if (!$table->hasPrimaryKey()) {
            $table->setPrimaryKey(['OXID']);
        }

        if (!$table->hasIndex('TRANSACTION_ID_UNIQUE')) {
            $table->addUniqueIndex(['TRANSACTION_ID'], 'TRANSACTION_ID_UNIQUE');
        }

        if (!$table->hasIndex('OXORDERID_INDEX')) {
            $table->addIndex(['OXORDERID'], 'OXORDERID_INDEX');
        }
    }
}
