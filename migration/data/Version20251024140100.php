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
 * Migration: Create Stripe payment order state table
 */
final class Version20251024140100 extends AbstractMigration
{
    //The migration done here creates a new table
    //NOTE: write migrations so that they can be run multiple times without breaking anything.
    //      Means: check if changes are already present before actually creating a table
    public function up(Schema $schema): void
    {
        $this->platform->registerDoctrineTypeMapping('enum', 'string');

        //add payment order state table
        $this->createPaymentOrderStateTable($schema);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
    }

    /**
     * create a stripe payment order state table
     * @throws SchemaException
     */
    private function createPaymentOrderStateTable(Schema $schema): void
    {
        $tableName = 'osc_stripe_payment_order_state';

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

        if (!$table->hasColumn('STATE')) {
            $table->addColumn(
                'STATE',
                Types::STRING,
                [
                    'columnDefinition' => 'varchar(50) collate latin1_general_ci',
                    'comment' => 'Payment order state'
                ]
            );
        }

        if (!$table->hasColumn('METADATA')) {
            $table->addColumn(
                'METADATA',
                Types::TEXT,
                [
                    'columnDefinition' => 'text collate latin1_general_ci',
                    'comment' => 'Additional metadata (JSON)'
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

        if (!$table->hasIndex('OXORDERID_INDEX')) {
            $table->addIndex(['OXORDERID'], 'OXORDERID_INDEX');
        }
    }
}
