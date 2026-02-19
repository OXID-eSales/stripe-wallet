<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\PciDss;

use OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper;
use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that no card data (PAN, CVV, CVC) appears in contract metadata,
 * basket snapshots, webhook payloads, or serialized contract arrays.
 *
 * @covers \OxidEsales\PaymentComponent\Contract\PaymentContract
 * @group security
 * @group pci-dss
 * @group sprint-58
 */
final class CardDataLeakageTest extends TestCase
{
    private const SENSITIVE_KEYS = [
        'card_number', 'cardNumber', 'pan', 'cvv', 'cvc', 'cvv2',
        'card_cvc', 'card_cvv', 'security_code', 'expiry_date',
    ];

    /**
     * @test
     *
     * Compliance: PCI DSS Req 3.4 — No storage of sensitive authentication data
     */
    public function testNoCardNumberPatternInContractMetadata(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');
        $contract->setMetadata('payment_intent_id', 'pi_test_123');
        $contract->setMetadata('charge_id', 'ch_test_456');
        $contract->setMetadata('session_id', 'cs_test_789');

        /** @var array<string, mixed> $data */
        $data = $contract->toArray();
        /** @var array<string, mixed> $metadata */
        $metadata = $data['metadata'] ?? [];

        foreach ($metadata as $key => $value) {
            $this->assertNotContainsSensitiveKey($key);
            if (is_string($value)) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b(?:4\d{12}(?:\d{3})?|5[1-5]\d{14}|3[47]\d{13})\b/',
                    $value,
                    "Metadata key '{$key}' contains what looks like a card number"
                );
            }
        }
    }

    /**
     * @test
     *
     * Compliance: PCI DSS Req 3.4 — BasketSnapshot must not contain card data
     */
    public function testNoCardDataInBasketSnapshot(): void
    {
        $snapshot = SecurityTestHelper::createMinimalSnapshot();
        $data = $snapshot->toArray();

        $serialized = json_encode($data, JSON_THROW_ON_ERROR);

        foreach (self::SENSITIVE_KEYS as $key) {
            $this->assertStringNotContainsString(
                $key,
                $serialized,
                "BasketSnapshot contains sensitive key: {$key}"
            );
        }
    }

    /**
     * @test
     *
     * Compliance: PCI DSS Req 3.4 — Webhook payloads use tokenized IDs only
     */
    public function testWebhookPayloadContainsOnlyTokenizedIds(): void
    {
        $payload = StripeWebhookTestHelper::createPaymentIntentSucceededPayload('pi_test_pci_001', 5000);
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        // All IDs should use Stripe token format
        $this->assertIsString($data['id']);
        $this->assertStringStartsWith('evt_', $data['id']);

        $dataNode = $data['data'];
        $this->assertIsArray($dataNode);
        $object = $dataNode['object'];
        $this->assertIsArray($object);
        $this->assertIsString($object['id']);
        $this->assertStringStartsWith('pi_', $object['id']);

        $charges = $object['charges'];
        $this->assertIsArray($charges);
        $chargeList = $charges['data'];
        $this->assertIsArray($chargeList);

        foreach ($chargeList as $charge) {
            $this->assertIsArray($charge);
            $this->assertIsString($charge['id']);
            $this->assertStringStartsWith('ch_', $charge['id']);
        }
    }

    /**
     * @test
     *
     * Compliance: PCI DSS Req 3.4 — toArray() excludes sensitive payment data
     */
    public function testContractToArrayExcludesSensitivePaymentData(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $data = $contract->toArray();

        $serialized = json_encode($data, JSON_THROW_ON_ERROR);

        foreach (self::SENSITIVE_KEYS as $key) {
            $this->assertStringNotContainsString(
                $key,
                $serialized,
                "Contract toArray() contains sensitive key: {$key}"
            );
        }
    }

    private function assertNotContainsSensitiveKey(string $key): void
    {
        $lowerKey = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            $this->assertNotSame(
                strtolower($sensitiveKey),
                $lowerKey,
                "Found sensitive key in metadata: {$key}"
            );
        }
    }
}
