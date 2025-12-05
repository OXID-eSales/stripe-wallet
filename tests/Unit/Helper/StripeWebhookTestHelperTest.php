<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Helper;

use OxidSolutionCatalysts\Payments\Tests\Helper\StripeWebhookTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Tests\Helper\StripeWebhookTestHelper
 * @group sprint-13
 * @group webhook
 * @group helper
 */
final class StripeWebhookTestHelperTest extends TestCase
{
    private const TEST_SECRET = 'whsec_test_secret_key_12345';

    /**
     * @test
     */
    public function generateSignatureCreatesValidFormat(): void
    {
        $payload = '{"id":"evt_123"}';
        $timestamp = 1733400000;

        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET, $timestamp);

        $this->assertStringStartsWith('t=1733400000,v1=', $signature);
    }

    /**
     * @test
     */
    public function verifySignatureAcceptsValidSignature(): void
    {
        $payload = '{"id":"evt_123","type":"payment_intent.succeeded"}';
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

        $result = StripeWebhookTestHelper::verifySignature($payload, $signature, self::TEST_SECRET);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function verifySignatureRejectsInvalidSignature(): void
    {
        $payload = '{"id":"evt_123"}';
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

        // Modify payload after signing
        $modifiedPayload = '{"id":"evt_456"}';

        $result = StripeWebhookTestHelper::verifySignature($modifiedPayload, $signature, self::TEST_SECRET);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function verifySignatureRejectsWrongSecret(): void
    {
        $payload = '{"id":"evt_123"}';
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

        $result = StripeWebhookTestHelper::verifySignature($payload, $signature, 'wrong_secret');

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function verifySignatureRejectsExpiredTimestamp(): void
    {
        $payload = '{"id":"evt_123"}';
        $oldTimestamp = time() - 400; // 400 seconds ago
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET, $oldTimestamp);

        $result = StripeWebhookTestHelper::verifySignature($payload, $signature, self::TEST_SECRET, 300);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function createPaymentIntentSucceededPayloadReturnsValidJson(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_123', 5000);

        $data = json_decode($payload, true);

        $this->assertIsArray($data);
        $this->assertSame('payment_intent.succeeded', $data['type']);
        $this->assertSame('pi_test_123', $data['data']['object']['id']);
        $this->assertSame(5000, $data['data']['object']['amount']);
    }

    /**
     * @test
     */
    public function createChargeRefundedPayloadReturnsValidJson(): void
    {
        $payload = StripeWebhookTestHelper::createChargeRefundedPayload('pi_test_456', 2500);

        $data = json_decode($payload, true);

        $this->assertIsArray($data);
        $this->assertSame('charge.refunded', $data['type']);
        $this->assertSame('pi_test_456', $data['data']['object']['payment_intent']);
        $this->assertSame(2500, $data['data']['object']['amount_refunded']);
    }

    /**
     * @test
     */
    public function createCheckoutSessionCompletedPayloadReturnsValidJson(): void
    {
        $payload = StripeWebhookTestHelper::createCheckoutSessionCompletedPayload('cs_test_789', 'pi_test_789');

        $data = json_decode($payload, true);

        $this->assertIsArray($data);
        $this->assertSame('checkout.session.completed', $data['type']);
        $this->assertSame('cs_test_789', $data['data']['object']['id']);
        $this->assertSame('pi_test_789', $data['data']['object']['payment_intent']);
    }

    /**
     * @test
     */
    public function parseSignatureExtractsComponents(): void
    {
        $signature = 't=1733400000,v1=abc123,v1=def456';

        $result = StripeWebhookTestHelper::parseSignature($signature);

        $this->assertSame(1733400000, $result['timestamp']);
        $this->assertCount(2, $result['signatures']);
        $this->assertContains('abc123', $result['signatures']);
        $this->assertContains('def456', $result['signatures']);
    }

    /**
     * @test
     */
    public function generatedSignatureWorksWithStripeVerifier(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_integration_test');
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

        // Verify our helper can verify its own signatures
        $this->assertTrue(
            StripeWebhookTestHelper::verifySignature($payload, $signature, self::TEST_SECRET)
        );
    }
}
