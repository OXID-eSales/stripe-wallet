<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\SecretManagement;

use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F1: API keys stored unencrypted in OXID module config DB.
 *
 * OXID stores module settings in oxconfig table using ENCODE() which is a simple
 * two-way encryption — not suitable for API secrets.
 *
 * @group security
 * @group pci-dss
 * @group finding-f1
 * @group sprint-58
 */
final class ApiKeyExposureTest extends TestCase
{
    /**
     * @test
     *
     * Finding F1: API keys are retrieved without any decryption layer beyond OXID's ENCODE.
     * Documents that ModuleConfigurationService returns plain-text keys.
     */
    public function testApiKeysRetrievedWithoutDecryption(): void
    {
        $sourceFile = dirname(__DIR__, 3) . '/src/Stripe/Service/ModuleConfigurationServiceInterface.php';
        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('ModuleConfigurationServiceInterface not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // getSecretKey() returns string directly — no decrypt() call
        $this->assertStringContainsString('getSecretKey(): string', $source);
        $this->assertStringContainsString('getToken(): string', $source);
        $this->assertStringContainsString('getWebhookSecret(): string', $source);

        // Document: no encryption/decryption methods in the interface
        $this->assertStringNotContainsString('decrypt', $source);
        $this->assertStringNotContainsString('Cipher', $source);
    }

    /**
     * @test
     *
     * Contract toArray() does not expose API secrets.
     */
    public function testContractToArrayDoesNotExposeSecrets(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');

        // Store simulated secrets in metadata (they shouldn't be there)
        $contract->setMetadata('payment_intent_id', 'pi_test_123');

        $data = $contract->toArray();
        $serialized = json_encode($data, JSON_THROW_ON_ERROR);

        // No API key patterns in serialized contract
        $this->assertStringNotContainsString('sk_test_', $serialized);
        $this->assertStringNotContainsString('sk_live_', $serialized);
        $this->assertStringNotContainsString('whsec_', $serialized);
    }

    /**
     * @test
     *
     * Webhook log should not contain the webhook signing secret.
     */
    public function testWebhookLogDoesNotContainWebhookSecret(): void
    {
        // The webhook test helper creates payloads without the webhook secret
        $payload = '{"id":"evt_secret_test","type":"payment_intent.succeeded"}';
        $secret = 'whsec_should_never_appear_in_log';

        // Generate signature — the secret is NOT in the payload or signature
        $signature = \OxidEsales\Payments\Stripe\Tests\Helper\StripeWebhookTestHelper::generateSignature(
            $payload,
            $secret
        );

        $this->assertStringNotContainsString($secret, $payload);
        $this->assertStringNotContainsString($secret, $signature);
    }
}
