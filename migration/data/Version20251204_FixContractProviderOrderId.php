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
 * Sprint 7: Fix contract providerOrderId for Stripe Checkout orders
 *
 * This migration fixes existing contracts where:
 * - OXPROVIDERORDERID starts with 'cs_' (Checkout Session ID - wrong!)
 * - The linked order has OXTRANSID starting with 'pi_' (PaymentIntent ID - correct!)
 *
 * The bug was in StripeCheckoutReturnHandler which passed sessionId instead of
 * paymentIntentId when dispatching PaymentAuthorizedEvent.
 *
 * This caused WebhookContractFulfillmentHandler to fail lookups because:
 * - Contract stores: cs_test_... (checkout session ID)
 * - Webhook sends: pi_... (payment intent ID)
 * - Lookup fails → Contract never transitioned to FULFILLED
 *
 * The fix:
 * 1. Code fix in StripeCheckoutReturnHandler (Sprint 7)
 * 2. This migration fixes historical data by copying OXTRANSID to OXPROVIDERORDERID
 */
final class Version20251204_FixContractProviderOrderId extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 7: Fix contract providerOrderId (cs_test_... → pi_...) for Stripe Checkout orders';
    }

    public function up(Schema $schema): void
    {
        // Fix contracts with checkout session ID by using PaymentIntent ID from order
        // Only update contracts where:
        // - providerOrderId starts with 'cs_' (wrong checkout session ID)
        // - linked order has OXTRANSID starting with 'pi_' (correct payment intent ID)
        $this->addSql("
            UPDATE osc_payment_contract c
            JOIN oxorder o ON c.OXORDERID = o.OXID
            SET c.OXPROVIDERORDERID = o.OXTRANSID,
                c.OXUPDATED = NOW()
            WHERE c.OXPROVIDERORDERID LIKE 'cs\\_%'
              AND o.OXTRANSID LIKE 'pi\\_%'
        ");

        $this->write('Fixed contract providerOrderId: updated cs_... to pi_... from order.OXTRANSID');
    }

    public function down(Schema $schema): void
    {
        // Reverting would require storing the original cs_... values
        // which we don't have, so down() is a no-op
        // Also, the fixed pi_... values are correct and should not be reverted
        $this->write('Down migration is a no-op - providerOrderId should remain as pi_...');
    }
}
