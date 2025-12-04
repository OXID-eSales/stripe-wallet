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
 * Sprint 4: Fix OXPAID timestamp for paid Stripe orders
 *
 * This migration fixes existing orders where:
 * - OXTRANSSTATUS = 'OK' (payment was successful)
 * - OXPAID = '0000-00-00 00:00:00' (timestamp never set due to bug)
 * - OXTRANSID starts with 'pi_' (Stripe PaymentIntent)
 *
 * The fix sets OXPAID = OXORDERDATE for these orders.
 *
 * Note: New orders will be fixed by WebhookProcessingService changes.
 * This migration only fixes historical data.
 */
final class Version20251203_FixOxpaidForPaidOrders extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 4: Fix OXPAID timestamp for paid Stripe orders with missing payment date';
    }

    public function up(Schema $schema): void
    {
        // Fix paid orders with missing OXPAID timestamp
        // Set OXPAID to OXORDERDATE as the best available approximation
        $this->addSql("
            UPDATE oxorder
            SET OXPAID = OXORDERDATE
            WHERE OXTRANSSTATUS = 'OK'
              AND (OXPAID = '0000-00-00 00:00:00' OR OXPAID IS NULL)
              AND OXTRANSID LIKE 'pi_%'
        ");

        // Log how many orders were affected (for reporting)
        $this->write('Fixed OXPAID for paid Stripe orders with missing payment timestamp');
    }

    public function down(Schema $schema): void
    {
        // Reverting this migration would set OXPAID back to 0000-00-00
        // which is not desirable, so we make down() a no-op
        $this->write('Down migration is a no-op - OXPAID values should not be reverted to 0000-00-00');
    }
}
