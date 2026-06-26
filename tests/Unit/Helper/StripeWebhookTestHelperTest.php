<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Helper;

use OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for StripeWebhookTestHelper's public API.
 *
 * T4 fix (Sprint 114.13): removed self-verification tests that called
 * StripeWebhookTestHelper::generateSignature() and then verified with
 * StripeWebhookTestHelper::verifySignature(). Those tests only prove
 * that the helper is internally consistent — they do not exercise any
 * production code and give false confidence in webhook security.
 *
 * The remaining tests lock the observable output shape of the helper's
 * factory methods so refactors can safely modify internals.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-13')]
#[\PHPUnit\Framework\Attributes\Group('webhook')]
#[\PHPUnit\Framework\Attributes\Group('helper')]
final class StripeWebhookTestHelperTest extends TestCase
{
    private const TEST_SECRET = 'whsec_test_secret_key_12345';

    #[\PHPUnit\Framework\Attributes\Test]
    public function generateSignatureCreatesValidFormat(): void
    {
        $payload = '{"id":"evt_123"}';
        $timestamp = 1733400000;

        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET, $timestamp);

        $this->assertStringStartsWith('t=1733400000,v1=', $signature);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createPaymentIntentSucceededPayloadReturnsValidJson(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_123', 5000);

        $data = json_decode($payload, true);

        $this->assertIsArray($data);
        $this->assertSame('payment_intent.succeeded', $data['type']);
        $this->assertSame('pi_test_123', $data['data']['object']['id']);
        $this->assertSame(5000, $data['data']['object']['amount']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createChargeRefundedPayloadReturnsValidJson(): void
    {
        $payload = StripeWebhookTestHelper::createChargeRefundedPayload('pi_test_456', 2500);

        $data = json_decode($payload, true);

        $this->assertIsArray($data);
        $this->assertSame('charge.refunded', $data['type']);
        $this->assertSame('pi_test_456', $data['data']['object']['payment_intent']);
        $this->assertSame(2500, $data['data']['object']['amount_refunded']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createCheckoutSessionCompletedPayloadReturnsValidJson(): void
    {
        $payload = StripeWebhookTestHelper::createCheckoutSessionCompletedPayload('cs_test_789', 'pi_test_789');

        $data = json_decode($payload, true);

        $this->assertIsArray($data);
        $this->assertSame('checkout.session.completed', $data['type']);
        $this->assertSame('cs_test_789', $data['data']['object']['id']);
        $this->assertSame('pi_test_789', $data['data']['object']['payment_intent']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function parseSignatureExtractsComponents(): void
    {
        $signature = 't=1733400000,v1=abc123,v1=def456';

        $result = StripeWebhookTestHelper::parseSignature($signature);

        $this->assertSame(1733400000, $result['timestamp']);
        $this->assertCount(2, $result['signatures']);
        $this->assertContains('abc123', $result['signatures']);
        $this->assertContains('def456', $result['signatures']);
    }
}
