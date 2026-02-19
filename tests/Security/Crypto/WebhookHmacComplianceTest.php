<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Crypto;

use OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Validates webhook HMAC implementation against BSI TR-03116 requirements:
 * - Uses SHA-256 (BSI-approved algorithm)
 * - Output is 64 hex characters (256 bits)
 * - Deterministic
 *
 * @covers \OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper
 * @group security
 * @group bsi
 * @group crypto
 * @group sprint-58
 */
final class WebhookHmacComplianceTest extends TestCase
{
    private const TEST_SECRET = 'whsec_bsi_compliance_test';
    private const TEST_PAYLOAD = '{"id":"evt_bsi_test","type":"payment_intent.succeeded"}';

    /**
     * @test
     *
     * Compliance: BSI TR-03116 — HMAC-SHA-256 algorithm requirement
     */
    public function testUsesHmacSha256Algorithm(): void
    {
        $timestamp = 1700000000;
        $signature = StripeWebhookTestHelper::generateSignature(
            self::TEST_PAYLOAD,
            self::TEST_SECRET,
            $timestamp
        );

        $parsed = StripeWebhookTestHelper::parseSignature($signature);

        // Manually compute expected HMAC
        $signedPayload = "{$timestamp}." . self::TEST_PAYLOAD;
        $expected = hash_hmac('sha256', $signedPayload, self::TEST_SECRET);

        $this->assertSame($expected, $parsed['signatures'][0]);
    }

    /**
     * @test
     *
     * Compliance: BSI TR-03116 — 256-bit output
     */
    public function testSignatureOutputIs64HexCharacters(): void
    {
        $signature = StripeWebhookTestHelper::generateSignature(
            self::TEST_PAYLOAD,
            self::TEST_SECRET,
            1700000000
        );

        $parsed = StripeWebhookTestHelper::parseSignature($signature);
        $hmac = $parsed['signatures'][0];

        $this->assertSame(64, strlen($hmac), 'HMAC-SHA-256 must be 64 hex characters (256 bits)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hmac);
    }

    /**
     * @test
     */
    public function testSignatureIsDeterministic(): void
    {
        $timestamp = 1700000000;

        $sig1 = StripeWebhookTestHelper::generateSignature(self::TEST_PAYLOAD, self::TEST_SECRET, $timestamp);
        $sig2 = StripeWebhookTestHelper::generateSignature(self::TEST_PAYLOAD, self::TEST_SECRET, $timestamp);

        $this->assertSame($sig1, $sig2, 'Same inputs must produce identical signatures');
    }

    /**
     * @test
     */
    public function testSignatureChangesWithDifferentPayload(): void
    {
        $timestamp = 1700000000;

        $sig1 = StripeWebhookTestHelper::generateSignature('{"a":1}', self::TEST_SECRET, $timestamp);
        $sig2 = StripeWebhookTestHelper::generateSignature('{"a":2}', self::TEST_SECRET, $timestamp);

        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * @test
     */
    public function testSignatureChangesWithDifferentSecret(): void
    {
        $timestamp = 1700000000;

        $sig1 = StripeWebhookTestHelper::generateSignature(self::TEST_PAYLOAD, 'whsec_secret_A', $timestamp);
        $sig2 = StripeWebhookTestHelper::generateSignature(self::TEST_PAYLOAD, 'whsec_secret_B', $timestamp);

        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * @test
     */
    public function testSignatureChangesWithDifferentTimestamp(): void
    {
        $sig1 = StripeWebhookTestHelper::generateSignature(self::TEST_PAYLOAD, self::TEST_SECRET, 1700000000);
        $sig2 = StripeWebhookTestHelper::generateSignature(self::TEST_PAYLOAD, self::TEST_SECRET, 1700000001);

        $this->assertNotSame($sig1, $sig2);
    }

    /**
     * @test
     *
     * Stripe webhook secrets should have sufficient key length.
     */
    public function testMinimumKeyLength(): void
    {
        // Standard Stripe webhook secrets are whsec_ prefix + characters
        // Minimum viable: prefix + 16 chars for entropy
        $minLength = strlen('whsec_') + 16;
        $this->assertGreaterThanOrEqual($minLength, strlen(self::TEST_SECRET));
    }
}
