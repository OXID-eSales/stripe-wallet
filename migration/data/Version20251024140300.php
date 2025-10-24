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
 * Migration: Create Stripe payment basket snapshot table
 */
final class Version20251024140300 extends AbstractMigration
{
    //The migration done here creates a new table
    //NOTE: write migrations so that they can be run multiple times without breaking anything.
    //      Means: check if changes are already present before actually creating a table
    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');

        //add payment basket snapshot table
        $this->createPaymentBasketSnapshotTable($schema);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }

    /**
     * create a stripe payment basket snapshot table
     * @throws SchemaException
     */
    private function createPaymentBasketSnapshotTable(Schema $schema): void
    {
        $tableName = 'osc_stripe_payment_basket_snapshot';

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

        if (!$table->hasColumn('BASKET_ID')) {
            $table->addColumn(
                'BASKET_ID',
                Types::STRING,
                [
                    'columnDefinition' => 'varchar(255) collate latin1_general_ci',
                    'comment' => 'Basket identifier'
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

        if (!$table->hasColumn('BASKET_DATA')) {
            $table->addColumn(
                'BASKET_DATA',
                Types::TEXT,
                [
                    'columnDefinition' => 'text collate latin1_general_ci',
                    'comment' => 'Serialized basket data'
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

        if (!$table->hasPrimaryKey()) {
            $table->setPrimaryKey(['OXID']);
        }

        if (!$table->hasIndex('BASKET_ID_UNIQUE')) {
            $table->addUniqueIndex(['BASKET_ID'], 'BASKET_ID_UNIQUE');
        }

        if (!$table->hasIndex('OXUSERID_INDEX')) {
            $table->addIndex(['OXUSERID'], 'OXUSERID_INDEX');
        }
    }
}
