<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\DataProtection;

use OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F2: Webhook payloads contain PII (email, billing details)
 * and are logged in full without redaction.
 *
 * These tests document the current (vulnerable) behavior.
 * They will become regression tests when redaction is implemented.
 *
 * @group security
 * @group gdpr
 * @group finding-f2
 * @group sprint-58
 */
final class WebhookPiiLeakageTest extends TestCase
{
    /**
     * @test
     *
     * Finding F2: Checkout session payload contains customer_email field.
     * Compliance: GDPR Art.5 — Data minimization
     */
    public function testCheckoutSessionPayloadContainsCustomerEmail(): void
    {
        // Build a checkout.session.completed payload that mimics real Stripe data
        $payload = json_encode([
            'id' => 'evt_pii_test_001',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_pii',
                    'customer_email' => 'customer@example.com',
                    'payment_intent' => 'pi_test_pii',
                    'billing_address_collection' => 'auto',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $dataNode = $data['data'];
        $this->assertIsArray($dataNode);
        $object = $dataNode['object'];
        $this->assertIsArray($object);

        // Documents: PII exists in webhook payloads
        $this->assertArrayHasKey('customer_email', $object);
        $this->assertSame('customer@example.com', $object['customer_email']);
    }

    /**
     * @test
     *
     * Finding F2: Payment intent payload may contain billing details.
     */
    public function testPaymentIntentPayloadMayContainBillingDetails(): void
    {
        // Real Stripe payment_intent.succeeded payloads include billing_details
        $payload = json_encode([
            'id' => 'evt_pii_test_002',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_billing',
                    'charges' => [
                        'data' => [[
                            'id' => 'ch_test_billing',
                            'billing_details' => [
                                'name' => 'John Doe',
                                'email' => 'john@example.com',
                                'address' => [
                                    'city' => 'Berlin',
                                    'country' => 'DE',
                                    'line1' => 'Friedrichstr. 1',
                                    'postal_code' => '10117',
                                ],
                            ],
                        ]],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $dataNode = $data['data'];
        $this->assertIsArray($dataNode);
        $object = $dataNode['object'];
        $this->assertIsArray($object);
        $charges = $object['charges'];
        $this->assertIsArray($charges);
        $chargeList = $charges['data'];
        $this->assertIsArray($chargeList);
        $firstCharge = $chargeList[0];
        $this->assertIsArray($firstCharge);
        $billingDetails = $firstCharge['billing_details'];
        $this->assertIsArray($billingDetails);

        // Documents: billing details (PII) present in webhook payloads
        $this->assertSame('John Doe', $billingDetails['name']);
        $this->assertSame('john@example.com', $billingDetails['email']);
    }

    /**
     * @test
     *
     * Finding F2: Webhook log stores full unredacted payload.
     * Validates that the test helper payloads don't include redaction.
     */
    public function testWebhookTestPayloadsContainNoRedaction(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_pii_003');

        // The test helper creates payloads with full data, no redaction
        $this->assertStringNotContainsString('[REDACTED]', $payload);
        $this->assertStringNotContainsString('***', $payload);
    }
}
