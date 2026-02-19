<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Webhook;

use OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests that any modification to the webhook payload invalidates the HMAC signature.
 *
 * @group security
 * @group webhook
 * @group pci-dss
 * @group sprint-58
 */
final class PayloadTamperingTest extends TestCase
{
    private const TEST_SECRET = 'whsec_tampering_test_secret';

    /**
     * @test
     *
     * Compliance: PCI DSS 4.1 — Data integrity verification
     */
    public function testModifyingAmountInvalidatesSignature(): void
    {
        $originalPayload = '{"id":"evt_tamper_1","type":"payment_intent.succeeded","data":{"object":{"amount":1000}}}';
        $signature = StripeWebhookTestHelper::generateSignature($originalPayload, self::TEST_SECRET);

        $tamperedPayload = '{"id":"evt_tamper_1","type":"payment_intent.succeeded","data":{"object":{"amount":9999}}}';

        $this->assertFalse(
            StripeWebhookTestHelper::verifySignature($tamperedPayload, $signature, self::TEST_SECRET)
        );
    }

    /**
     * @test
     */
    public function testModifyingEventTypeInvalidatesSignature(): void
    {
        $originalPayload = '{"id":"evt_tamper_2","type":"payment_intent.succeeded"}';
        $signature = StripeWebhookTestHelper::generateSignature($originalPayload, self::TEST_SECRET);

        $tamperedPayload = '{"id":"evt_tamper_2","type":"charge.refunded"}';

        $this->assertFalse(
            StripeWebhookTestHelper::verifySignature($tamperedPayload, $signature, self::TEST_SECRET)
        );
    }

    /**
     * @test
     */
    public function testAddingExtraFieldsInvalidatesSignature(): void
    {
        $originalPayload = '{"id":"evt_tamper_3","type":"payment_intent.succeeded"}';
        $signature = StripeWebhookTestHelper::generateSignature($originalPayload, self::TEST_SECRET);

        $tamperedPayload = '{"id":"evt_tamper_3","type":"payment_intent.succeeded","injected":"data"}';

        $this->assertFalse(
            StripeWebhookTestHelper::verifySignature($tamperedPayload, $signature, self::TEST_SECRET)
        );
    }

    /**
     * @test
     *
     * Signature is computed on raw string, not parsed JSON.
     * Therefore key order in the source matters.
     */
    public function testJsonKeyOrderDoesNotAffectSignatureIfRawStringUnchanged(): void
    {
        $payload = '{"id":"evt_order_test","type":"test"}';
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

        // Same raw string verifies
        $this->assertTrue(
            StripeWebhookTestHelper::verifySignature($payload, $signature, self::TEST_SECRET)
        );

        // Different key order = different raw string = different HMAC
        $reorderedPayload = '{"type":"test","id":"evt_order_test"}';
        $this->assertFalse(
            StripeWebhookTestHelper::verifySignature($reorderedPayload, $signature, self::TEST_SECRET)
        );
    }

    /**
     * @test
     */
    public function testSingleBitFlipInPayloadInvalidatesSignature(): void
    {
        $originalPayload = '{"id":"evt_bitflip","type":"test","amount":1000}';
        $signature = StripeWebhookTestHelper::generateSignature($originalPayload, self::TEST_SECRET);

        // Flip one character: 1000 → 1001
        $flippedPayload = '{"id":"evt_bitflip","type":"test","amount":1001}';

        $this->assertFalse(
            StripeWebhookTestHelper::verifySignature($flippedPayload, $signature, self::TEST_SECRET)
        );
    }

    /**
     * @test
     */
    public function testEmptyPayloadHasValidSignatureWhenSigned(): void
    {
        $emptyPayload = '';
        $signature = StripeWebhookTestHelper::generateSignature($emptyPayload, self::TEST_SECRET);

        $this->assertTrue(
            StripeWebhookTestHelper::verifySignature($emptyPayload, $signature, self::TEST_SECRET)
        );
    }
}
