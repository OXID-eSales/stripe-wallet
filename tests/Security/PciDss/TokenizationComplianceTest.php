<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\PciDss;

use OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that all Stripe object IDs follow the expected token format:
 * - Payment Intents: pi_
 * - Charges: ch_
 * - Checkout Sessions: cs_
 * - Customers: cus_
 * - Events: evt_
 *
 * @group security
 * @group pci-dss
 * @group sprint-58
 */
final class TokenizationComplianceTest extends TestCase
{
    /**
     * @test
     *
     * Compliance: PCI DSS 3.4 — Tokenized payment intent IDs
     */
    public function testPaymentIntentIdStartsWithPi(): void
    {
        $piId = $this->extractNestedString(
            StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_tokenize_001'),
            ['data', 'object', 'id']
        );
        $this->assertMatchesRegularExpression('/^pi_/', $piId);
    }

    /**
     * @test
     */
    public function testChargeIdStartsWithCh(): void
    {
        $chargeId = $this->extractNestedString(
            StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_tokenize_002'),
            ['data', 'object', 'charges', 'data', 0, 'id']
        );
        $this->assertMatchesRegularExpression('/^ch_/', $chargeId);
    }

    /**
     * @test
     */
    public function testCheckoutSessionIdStartsWithCs(): void
    {
        $csId = $this->extractNestedString(
            StripeWebhookTestHelper::createCheckoutSessionCompletedPayload(
                'cs_test_tokenize_001',
                'pi_test_tokenize_003'
            ),
            ['data', 'object', 'id']
        );
        $this->assertMatchesRegularExpression('/^cs_/', $csId);
    }

    /**
     * @test
     */
    public function testEventIdStartsWithEvt(): void
    {
        $eventId = $this->extractNestedString(
            StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_tokenize_004'),
            ['id']
        );
        $this->assertMatchesRegularExpression('/^evt_/', $eventId);
    }

    /**
     * @test
     */
    public function testAllPayloadIdsFollowTokenFormat(): void
    {
        $payloads = [
            StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_all_001'),
            StripeWebhookTestHelper::createChargeRefundedPayload('pi_test_all_002'),
            StripeWebhookTestHelper::createCheckoutSessionCompletedPayload('cs_test_all', 'pi_test_all_003'),
        ];

        foreach ($payloads as $payload) {
            $eventId = $this->extractNestedString($payload, ['id']);
            $this->assertMatchesRegularExpression(
                '/^evt_/',
                $eventId,
                "Event ID must start with 'evt_'"
            );
        }
    }

    /**
     * @param list<string|int> $path
     */
    private function extractNestedString(string $json, array $path): string
    {
        $current = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            $this->assertIsArray($current);
            $this->assertArrayHasKey($key, $current);
            $current = $current[$key];
        }
        $this->assertIsString($current);

        return $current;
    }
}
