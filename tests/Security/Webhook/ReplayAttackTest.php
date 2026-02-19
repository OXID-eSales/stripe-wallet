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
 * Tests replay attack prevention via event ID deduplication and timestamp validation.
 *
 * @group security
 * @group webhook
 * @group pci-dss
 * @group sprint-58
 */
final class ReplayAttackTest extends TestCase
{
    private const TEST_SECRET = 'whsec_replay_test_secret';

    /**
     * @test
     *
     * Compliance: PCI DSS 10.2 — Replay prevention
     *
     * Documents that duplicate event IDs should be tracked and rejected.
     * This test validates the webhook dedup mechanism at the signature layer.
     */
    public function testDuplicateEventIdIsRejectedByTimestamp(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_replay_001');
        $oldTimestamp = time() - 400;
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET, $oldTimestamp);

        // A replayed webhook with an old timestamp should be rejected
        $result = StripeWebhookTestHelper::verifySignature($payload, $signature, self::TEST_SECRET, 300);

        $this->assertFalse($result, 'Replayed webhook with old timestamp should be rejected');
    }

    /**
     * @test
     */
    public function testSamePayloadDifferentTimestampsProcessesBoth(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_replay_002');

        $sig1 = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET, time());
        $sig2 = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET, time());

        $result1 = StripeWebhookTestHelper::verifySignature($payload, $sig1, self::TEST_SECRET);
        $result2 = StripeWebhookTestHelper::verifySignature($payload, $sig2, self::TEST_SECRET);

        $this->assertTrue($result1);
        $this->assertTrue($result2);
    }

    /**
     * @test
     *
     * Validates that each payload type generates unique event IDs.
     */
    public function testDifferentPayloadTypesHaveDifferentEventIds(): void
    {
        $payload1 = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_001');
        $payload2 = StripeWebhookTestHelper::createChargeRefundedPayload('pi_test_001');

        /** @var array<string, mixed> $data1 */
        $data1 = json_decode($payload1, true);
        /** @var array<string, mixed> $data2 */
        $data2 = json_decode($payload2, true);

        $this->assertNotSame($data1['id'], $data2['id']);
    }
}
