<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\SecretManagement;

use OxidEsales\Payments\Stripe\Service\ContractTokenService;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F10: ContractTokenService falls back to hardcoded secret.
 *
 * When neither API key nor webhook secret is configured, the service
 * uses a hardcoded constant as the token secret. This is insecure because
 * all unconfigured installations share the same secret.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ContractTokenService
 * @group security
 * @group finding-f10
 * @group bsi
 * @group sprint-58
 */
final class ConfigurationSecurityTest extends TestCase
{
    /**
     * @test
     *
     * Finding F10: Empty API key + empty webhook secret → hardcoded fallback.
     */
    public function testContractTokenServiceFallsBackToHardcodedSecret(): void
    {
        $config = $this->createConfigMock('', '');

        // Service constructs without error — uses hardcoded fallback
        $service = new ContractTokenService($config);

        $token = $service->generateToken('contract_fallback_test');
        $this->assertNotEmpty($token);

        // Token is valid — hardcoded secret is deterministic
        $this->assertTrue($service->validateToken($token, 'contract_fallback_test'));
    }

    /**
     * @test
     *
     * Finding F10: Two unconfigured installations generate identical tokens.
     * This allows cross-installation token forgery.
     */
    public function testUnconfiguredInstallationsShareSameSecret(): void
    {
        $config1 = $this->createConfigMock('', '');
        $config2 = $this->createConfigMock('', '');

        $service1 = new ContractTokenService($config1);
        $service2 = new ContractTokenService($config2);

        $token1 = $service1->generateToken('shared_contract');
        $token2 = $service2->generateToken('shared_contract');

        // Both produce the same token — shared hardcoded secret
        $this->assertSame($token1, $token2, 'Finding F10: Unconfigured installations share the same secret');
    }

    /**
     * @test
     *
     * When API key is available, it is preferred over hardcoded secret.
     */
    public function testContractTokenServicePrefersApiSecret(): void
    {
        $configWithKey = $this->createConfigMock('sk_test_real_key', 'whsec_test');
        $configNoKey = $this->createConfigMock('', '');

        $serviceWithKey = new ContractTokenService($configWithKey);
        $serviceNoKey = new ContractTokenService($configNoKey);

        $tokenWithKey = $serviceWithKey->generateToken('contract_pref');
        $tokenNoKey = $serviceNoKey->generateToken('contract_pref');

        // Different secrets produce different tokens
        $this->assertNotSame($tokenWithKey, $tokenNoKey);
    }

    /**
     * @test
     *
     * When API key is empty but webhook secret is available, uses webhook secret.
     */
    public function testFallsBackToWebhookSecretBeforeHardcoded(): void
    {
        $configWebhookOnly = $this->createConfigMock('', 'whsec_webhook_only');
        $configNoKeys = $this->createConfigMock('', '');

        $serviceWebhook = new ContractTokenService($configWebhookOnly);
        $serviceNoKeys = new ContractTokenService($configNoKeys);

        $tokenWebhook = $serviceWebhook->generateToken('contract_wb');
        $tokenNoKeys = $serviceNoKeys->generateToken('contract_wb');

        $this->assertNotSame($tokenWebhook, $tokenNoKeys);
    }

    /**
     * @test
     *
     * Documents the webhook secret config key name.
     */
    public function testWebhookSecretConfigKeyName(): void
    {
        $sourceFile = dirname(__DIR__, 3) . '/src/Stripe/Service/ModuleConfigurationServiceInterface.php';
        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('ModuleConfigurationServiceInterface not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        $this->assertStringContainsString('getWebhookSecret', $source);
    }

    private function createConfigMock(
        string $secretKey,
        string $webhookSecret
    ): ModuleConfigurationServiceInterface&MockObject {
        $mock = $this->createMock(ModuleConfigurationServiceInterface::class);
        $mock->method('getSecretKey')->willReturn($secretKey);
        $mock->method('getWebhookSecret')->willReturn($webhookSecret);
        return $mock;
    }
}
