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
 * Sprint 5: Add missing columns to osc_payment_webhooklogs table
 *
 * These columns were supposed to be added by Version20251202_Sprint2TableConsolidation
 * but that migration failed. This migration adds them properly.
 */
final class Version20251203_AddMissingWebhookLogColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing OXPROVIDER, OXPAYLOAD, OXPROCESSEDAT columns to osc_payment_webhooklogs';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('osc_payment_webhooklogs');

        if (!$table->hasColumn('OXPROVIDER')) {
            $table->addColumn('OXPROVIDER', 'string', [
                'length' => 32,
                'notnull' => false,
                'default' => null,
                'comment' => 'Payment provider (stripe, paypal, etc)'
            ]);
        }

        if (!$table->hasColumn('OXPAYLOAD')) {
            $table->addColumn('OXPAYLOAD', 'text', [
                'notnull' => false,
                'default' => null,
                'comment' => 'Webhook payload JSON'
            ]);
        }

        if (!$table->hasColumn('OXPROCESSEDAT')) {
            $table->addColumn('OXPROCESSEDAT', 'datetime', [
                'notnull' => false,
                'default' => null,
                'comment' => 'When webhook was processed'
            ]);
        }
    }

    public function down(Schema $schema): void
    {
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
}
