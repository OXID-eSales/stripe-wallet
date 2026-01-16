<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Webhook;

use OxidEsales\Payments\Stripe\WebhookSignatureVerifier;
use OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Integration tests for webhook signature verification.
 *
 * These tests verify that our signature generation is compatible
 * with the Stripe SDK's verification logic.
 *
 * @group integration
 * @group sprint-13
 * @group webhook
 */
final class WebhookSignatureIntegrationTest extends TestCase
{
    private const TEST_SECRET = 'whsec_test_integration_secret';

    /**
     * @test
     */
    public function stripeWebhookConstructEventAcceptsHelperGeneratedSignature(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload(
            'pi_integration_test_' . uniqid()
        );
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

        // Use Stripe SDK to verify
        $event = Webhook::constructEvent($payload, $signature, self::TEST_SECRET);

        $this->assertSame('payment_intent.succeeded', $event->type);
        $this->assertNotEmpty($event->id);
    }

    /**
     * @test
     */
    public function stripeWebhookRejectsInvalidSignature(): void
    {
        $payload = '{"id":"evt_test"}';
        $signature = 't=123,v1=invalid';

        $this->expectException(SignatureVerificationException::class);

        Webhook::constructEvent($payload, $signature, self::TEST_SECRET);
    }

    /**
     * @test
     */
    public function stripeWebhookRejectsModifiedPayload(): void
    {
        $originalPayload = '{"id":"evt_original"}';
        $signature = StripeWebhookTestHelper::generateSignature($originalPayload, self::TEST_SECRET);

        $modifiedPayload = '{"id":"evt_modified"}';

        $this->expectException(SignatureVerificationException::class);

        Webhook::constructEvent($modifiedPayload, $signature, self::TEST_SECRET);
    }

    /**
     * @test
     */
    public function webhookSignatureVerifierAcceptsValidSignature(): void
    {
        $verifier = new WebhookSignatureVerifier(self::TEST_SECRET);

        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload(
            'pi_verifier_test_' . uniqid()
        );
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

        $result = $verifier->verify($payload, $signature);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function webhookSignatureVerifierRejectsInvalidSignature(): void
    {
        $verifier = new WebhookSignatureVerifier(self::TEST_SECRET);

        $payload = '{"id":"evt_test"}';
        $signature = 't=123,v1=invalid_signature';

        $result = $verifier->verify($payload, $signature);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function webhookSignatureVerifierParsesEventCorrectly(): void
    {
        $verifier = new WebhookSignatureVerifier(self::TEST_SECRET);

        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload(
            'pi_parse_test_' . uniqid()
        );
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

        $eventData = $verifier->parseEvent($payload, $signature);

        $this->assertArrayHasKey('id', $eventData);
        $this->assertArrayHasKey('type', $eventData);
        $this->assertSame('payment_intent.succeeded', $eventData['type']);
    }

    /**
     * @test
     */
    public function endToEndWebhookFlowWithAllEventTypes(): void
    {
        $verifier = new WebhookSignatureVerifier(self::TEST_SECRET);

        $eventTypes = [
            'payment_intent.succeeded' => fn() => StripeWebhookTestHelper::createPaymentIntentSucceededPayload(
                'pi_e2e_' . uniqid()
            ),
            'charge.refunded' => fn() => StripeWebhookTestHelper::createChargeRefundedPayload(
                'pi_e2e_refund_' . uniqid()
            ),
            'checkout.session.completed' => fn() => StripeWebhookTestHelper::createCheckoutSessionCompletedPayload(
                'cs_e2e_' . uniqid(),
                'pi_e2e_checkout_' . uniqid()
            ),
        ];

        foreach ($eventTypes as $expectedType => $createPayload) {
            $payload = $createPayload();
            $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET);

            $this->assertTrue(
                $verifier->verify($payload, $signature),
                "Signature verification failed for {$expectedType}"
            );

            $eventData = $verifier->parseEvent($payload, $signature);
            $this->assertSame(
                $expectedType,
                $eventData['type'],
                "Event type mismatch for {$expectedType}"
            );
        }
    }

    /**
     * @test
     */
    public function toleranceParameterRejectsOldSignatures(): void
    {
        // Create signature with old timestamp
        $oldTimestamp = time() - 400; // 400 seconds ago
        $payload = '{"id":"evt_old_test"}';
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET, $oldTimestamp);

        // Default tolerance is 300 seconds
        $verifier = new WebhookSignatureVerifier(self::TEST_SECRET, 300);

        $result = $verifier->verify($payload, $signature);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function toleranceParameterAcceptsRecentSignatures(): void
    {
        // Create signature with recent timestamp (within tolerance)
        $recentTimestamp = time() - 100; // 100 seconds ago
        $payload = '{"id":"evt_recent_test"}';
        $signature = StripeWebhookTestHelper::generateSignature($payload, self::TEST_SECRET, $recentTimestamp);

        // 300 second tolerance should accept this
        $verifier = new WebhookSignatureVerifier(self::TEST_SECRET, 300);

        $result = $verifier->verify($payload, $signature);

        $this->assertTrue($result);
    }
}
