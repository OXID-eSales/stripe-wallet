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
 * Migration: Create Stripe payment customer table
 */
final class Version20251024140200 extends AbstractMigration
{
    //The migration done here creates a new table
    //NOTE: write migrations so that they can be run multiple times without breaking anything.
    //      Means: check if changes are already present before actually creating a table
    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');

        //add payment customer table
        $this->createPaymentCustomerTable($schema);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }

    /**
     * create a stripe payment customer table
     * @throws SchemaException
     */
    private function createPaymentCustomerTable(Schema $schema): void
    {
        $tableName = 'osc_stripe_payment_customer';

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

        if (!$table->hasColumn('CUSTOMER_ID')) {
            $table->addColumn(
                'CUSTOMER_ID',
                Types::STRING,
                [
                    'columnDefinition' => 'varchar(255) collate latin1_general_ci',
                    'comment' => 'Internal customer identifier'
                ]
            );
        }

        if (!$table->hasColumn('OXUSERID')) {
            $table->addColumn(
                'OXUSERID',
                Types::STRING,
                [
                    'columnDefinition' => 'char(32) collate latin1_general_ci',
                    'comment' => 'OXID User id (oxuser)'
                ]
            );
        }

        if (!$table->hasColumn('STRIPE_CUSTOMER_ID')) {
            $table->addColumn(
                'STRIPE_CUSTOMER_ID',
                Types::STRING,
                [
                    'columnDefinition' => 'varchar(255) collate latin1_general_ci',
                    'comment' => 'Stripe customer ID'
                ]
            );
        }

        if (!$table->hasColumn('DEFAULT_PAYMENT_METHOD')) {
            $table->addColumn(
                'DEFAULT_PAYMENT_METHOD',
                Types::STRING,
                [
                    'columnDefinition' => 'varchar(255) collate latin1_general_ci',
                    'comment' => 'Default payment method ID'
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

        if (!$table->hasIndex('CUSTOMER_ID_UNIQUE')) {
            $table->addUniqueIndex(['CUSTOMER_ID'], 'CUSTOMER_ID_UNIQUE');
        }

        if (!$table->hasIndex('OXUSERID_INDEX')) {
            $table->addIndex(['OXUSERID'], 'OXUSERID_INDEX');
        }

        if (!$table->hasIndex('STRIPE_CUSTOMER_ID_INDEX')) {
            $table->addIndex(['STRIPE_CUSTOMER_ID'], 'STRIPE_CUSTOMER_ID_INDEX');
        }
    }
}
