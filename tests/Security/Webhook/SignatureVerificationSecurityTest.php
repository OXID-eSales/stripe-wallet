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
 * Verifies that webhook signature verification correctly rejects
 * invalid, tampered, and malformed signatures.
 *
 * @covers \OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper
 * @group security
 * @group webhook
 * @group pci-dss
 * @group sprint-58
 */
final class SignatureVerificationSecurityTest extends TestCase
{
    private const TEST_SECRET = 'whsec_test_secret_key_for_security_tests';
    private const TEST_PAYLOAD = '{"id":"evt_test_sec","type":"payment_intent.succeeded"}';

    /**
     * @test
     */
    public function testRejectsEmptySignature(): void
    {
        $result = StripeWebhookTestHelper::verifySignature(
            self::TEST_PAYLOAD,
            '',
            self::TEST_SECRET
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testRejectsMalformedSignatureFormat(): void
    {
        $result = StripeWebhookTestHelper::verifySignature(
            self::TEST_PAYLOAD,
            'not-a-valid-signature-format',
            self::TEST_SECRET
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testRejectsWrongSecret(): void
    {
        $signature = StripeWebhookTestHelper::generateSignature(
            self::TEST_PAYLOAD,
            self::TEST_SECRET
        );

        $result = StripeWebhookTestHelper::verifySignature(
            self::TEST_PAYLOAD,
            $signature,
            'whsec_wrong_secret'
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     *
     * Compliance: PCI DSS 4.1 — Data integrity in transit
     */
    public function testRejectsTamperedPayloadWithValidSignature(): void
    {
        $originalPayload = '{"id":"evt_test_original","amount":1000}';
        $signature = StripeWebhookTestHelper::generateSignature(
            $originalPayload,
            self::TEST_SECRET
        );

        $tamperedPayload = '{"id":"evt_test_original","amount":9999}';
        $result = StripeWebhookTestHelper::verifySignature(
            $tamperedPayload,
            $signature,
            self::TEST_SECRET
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testRejectsNullBytesInPayload(): void
    {
        $payloadWithNull = "{\x00\"id\":\"evt_test\"}";
        $signature = StripeWebhookTestHelper::generateSignature(
            self::TEST_PAYLOAD,
            self::TEST_SECRET
        );

        $result = StripeWebhookTestHelper::verifySignature(
            $payloadWithNull,
            $signature,
            self::TEST_SECRET
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testRejectsModifiedTimestamp(): void
    {
        $timestamp = time();
        $signature = StripeWebhookTestHelper::generateSignature(
            self::TEST_PAYLOAD,
            self::TEST_SECRET,
            $timestamp
        );

        // Modify the timestamp in the signature header
        $modifiedSignature = str_replace(
            "t={$timestamp}",
            't=' . ($timestamp + 1),
            $signature
        );

        $result = StripeWebhookTestHelper::verifySignature(
            self::TEST_PAYLOAD,
            $modifiedSignature,
            self::TEST_SECRET
        );

        $this->assertFalse($result);
    }

    /**
     * @test
     *
     * Stripe supports multiple v1= entries during key rotation.
     */
    public function testAcceptsValidSignatureWithMultipleV1Entries(): void
    {
        $timestamp = time();
        $signedPayload = "{$timestamp}." . self::TEST_PAYLOAD;
        $validSig = hash_hmac('sha256', $signedPayload, self::TEST_SECRET);

        // Simulate Stripe key rotation: two v1= entries, second one is valid
        $signature = "t={$timestamp},v1=invalid_old_signature,v1={$validSig}";

        $result = StripeWebhookTestHelper::verifySignature(
            self::TEST_PAYLOAD,
            $signature,
            self::TEST_SECRET
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testAcceptsValidSignature(): void
    {
        $signature = StripeWebhookTestHelper::generateSignature(
            self::TEST_PAYLOAD,
            self::TEST_SECRET
        );

        $result = StripeWebhookTestHelper::verifySignature(
            self::TEST_PAYLOAD,
            $signature,
            self::TEST_SECRET
        );

        $this->assertTrue($result);
    }
}
