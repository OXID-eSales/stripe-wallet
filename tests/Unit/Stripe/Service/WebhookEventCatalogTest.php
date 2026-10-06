<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\WebhookEventCatalog;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\WebhookEventCatalog::class)]
class WebhookEventCatalogTest extends TestCase
{
    public function testCatalogIncludesPaymentIntentSucceeded(): void
    {
        $this->assertContains('payment_intent.succeeded', (new WebhookEventCatalog())->all());
    }

    /**
     * GRAPH-QL / PS2: a headless client may never come back from Stripe, so the
     * contract is committed from `checkout.session.completed` (payment-base
     * ContractCommitService). The endpoint must be subscribed to it.
     */
    public function testCatalogIncludesCheckoutSessionCompleted(): void
    {
        $this->assertContains('checkout.session.completed', (new WebhookEventCatalog())->all());
    }

    public function testCatalogIncludesChargeRefunded(): void
    {
        $this->assertContains('charge.refunded', (new WebhookEventCatalog())->all());
    }

    public function testCatalogDoesNotIncludeChargeCaptured(): void
    {
        // Sprint 112 / G5: charge.captured is structurally dominated by
        // payment_intent.succeeded — removed from the subscription list.
        $this->assertNotContains('charge.captured', (new WebhookEventCatalog())->all());
    }
}
