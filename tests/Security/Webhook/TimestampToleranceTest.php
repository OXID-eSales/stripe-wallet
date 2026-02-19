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
 * Tests webhook timestamp tolerance enforcement.
 *
 * Stripe's default tolerance is 300 seconds (5 minutes).
 * Webhooks outside this window must be rejected.
 *
 * @group security
 * @group webhook
 * @group bsi
 * @group sprint-58
 */
final class TimestampToleranceTest extends TestCase
{
    private const TEST_SECRET = 'whsec_timestamp_test';
    private const PAYLOAD = '{"id":"evt_ts_test","type":"payment_intent.succeeded"}';

    /**
     * @test
     */
    public function testRejectsTimestampOlderThan300Seconds(): void
    {
        $oldTimestamp = time() - 301;
        $signature = StripeWebhookTestHelper::generateSignature(self::PAYLOAD, self::TEST_SECRET, $oldTimestamp);

        $result = StripeWebhookTestHelper::verifySignature(self::PAYLOAD, $signature, self::TEST_SECRET, 300);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testRejectsTimestampInDistantFuture(): void
    {
        $futureTimestamp = time() + 301;
        $signature = StripeWebhookTestHelper::generateSignature(self::PAYLOAD, self::TEST_SECRET, $futureTimestamp);

        $result = StripeWebhookTestHelper::verifySignature(self::PAYLOAD, $signature, self::TEST_SECRET, 300);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testAcceptsTimestampAtExactBoundary(): void
    {
        // At exactly 300 seconds — should be accepted (abs(diff) == tolerance)
        $boundaryTimestamp = time() - 300;
        $signature = StripeWebhookTestHelper::generateSignature(self::PAYLOAD, self::TEST_SECRET, $boundaryTimestamp);

        $result = StripeWebhookTestHelper::verifySignature(self::PAYLOAD, $signature, self::TEST_SECRET, 300);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testRejectsTimestampAt301Seconds(): void
    {
        $expiredTimestamp = time() - 301;
        $signature = StripeWebhookTestHelper::generateSignature(self::PAYLOAD, self::TEST_SECRET, $expiredTimestamp);

        $result = StripeWebhookTestHelper::verifySignature(self::PAYLOAD, $signature, self::TEST_SECRET, 300);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testZeroTimestampIsRejected(): void
    {
        $signature = StripeWebhookTestHelper::generateSignature(self::PAYLOAD, self::TEST_SECRET, 0);

        $result = StripeWebhookTestHelper::verifySignature(self::PAYLOAD, $signature, self::TEST_SECRET, 300);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testNegativeTimestampIsRejected(): void
    {
        $signature = "t=-1,v1=" . hash_hmac('sha256', '-1.' . self::PAYLOAD, self::TEST_SECRET);

        $result = StripeWebhookTestHelper::verifySignature(self::PAYLOAD, $signature, self::TEST_SECRET, 300);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testAcceptsCurrentTimestamp(): void
    {
        $signature = StripeWebhookTestHelper::generateSignature(self::PAYLOAD, self::TEST_SECRET, time());

        $result = StripeWebhookTestHelper::verifySignature(self::PAYLOAD, $signature, self::TEST_SECRET, 300);

        $this->assertTrue($result);
    }
}
