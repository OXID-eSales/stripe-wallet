<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\ContractTokenService;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\Payments\Stripe\Service\ContractTokenService
 */
class ContractTokenServiceTest extends TestCase
{
    private ContractTokenService $service;
    private string $testSecretKey = 'sk_test_51ABC123XYZ';

    protected function setUp(): void
    {
        $configService = $this->createConfigServiceMock($this->testSecretKey);
        $this->service = new ContractTokenService($configService);
    }

    /**
     * Create a mock ModuleConfigurationServiceInterface with specified secret key
     */
    private function createConfigServiceMock(string $secretKey, string $webhookSecret = ''): ModuleConfigurationServiceInterface
    {
        $configService = $this->createMock(ModuleConfigurationServiceInterface::class);
        $configService->method('getSecretKey')->willReturn($secretKey);
        $configService->method('getWebhookSecret')->willReturn($webhookSecret);
        return $configService;
    }

    // =========================================================================
    // Token Generation Tests
    // =========================================================================

    public function testGenerateTokenReturnsNonEmptyString(): void
    {
        $token = $this->service->generateToken('contract_123');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testGenerateTokenIsDeterministic(): void
    {
        $token1 = $this->service->generateToken('contract_123');
        $token2 = $this->service->generateToken('contract_123');

        $this->assertEquals($token1, $token2);
    }

    public function testGenerateTokenDiffersForDifferentContracts(): void
    {
        $token1 = $this->service->generateToken('contract_123');
        $token2 = $this->service->generateToken('contract_456');

        $this->assertNotEquals($token1, $token2);
    }

    public function testGenerateTokenIncludesContractId(): void
    {
        $token = $this->service->generateToken('contract_abc123');

        // Token format: base64url(contractId:hmac)
        // URL-safe base64 uses -_ instead of +/
        $decoded = base64_decode(strtr($token, '-_', '+/'));
        $this->assertStringContainsString('contract_abc123', $decoded);
    }

    public function testGenerateTokenIsUrlSafe(): void
    {
        $token = $this->service->generateToken('contract_123');

        // Should be URL-safe base64
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
    }

    // =========================================================================
    // Token Validation Tests
    // =========================================================================

    public function testValidateTokenReturnsTrueForValidToken(): void
    {
        $contractId = 'contract_xyz';
        $token = $this->service->generateToken($contractId);

        $this->assertTrue($this->service->validateToken($token, $contractId));
    }

    public function testValidateTokenReturnsFalseForTamperedToken(): void
    {
        $contractId = 'contract_xyz';
        $token = $this->service->generateToken($contractId);
        $tamperedToken = $token . 'x';

        $this->assertFalse($this->service->validateToken($tamperedToken, $contractId));
    }

    public function testValidateTokenReturnsFalseForWrongContractId(): void
    {
        $token = $this->service->generateToken('contract_123');

        $this->assertFalse($this->service->validateToken($token, 'contract_456'));
    }

    public function testValidateTokenReturnsFalseForDifferentSecret(): void
    {
        $service1 = new ContractTokenService($this->createConfigServiceMock('secret_1'));
        $service2 = new ContractTokenService($this->createConfigServiceMock('secret_2'));

        $token = $service1->generateToken('contract_123');

        $this->assertFalse($service2->validateToken($token, 'contract_123'));
    }

    public function testValidateTokenReturnsFalseForEmptyToken(): void
    {
        $this->assertFalse($this->service->validateToken('', 'contract_123'));
    }

    public function testValidateTokenReturnsFalseForMalformedToken(): void
    {
        $this->assertFalse($this->service->validateToken('not_valid_base64!!!', 'contract_123'));
    }

    // =========================================================================
    // Contract ID Extraction Tests
    // =========================================================================

    public function testExtractContractIdFromValidToken(): void
    {
        $contractId = 'contract_abc123';
        $token = $this->service->generateToken($contractId);

        $extracted = $this->service->extractContractId($token);

        $this->assertEquals($contractId, $extracted);
    }

    public function testExtractContractIdReturnsNullForInvalidToken(): void
    {
        $this->assertNull($this->service->extractContractId('invalid_token'));
    }

    public function testExtractContractIdReturnsNullForEmptyToken(): void
    {
        $this->assertNull($this->service->extractContractId(''));
    }

    // =========================================================================
    // Secret Key Derivation Tests
    // =========================================================================

    public function testUsesWebhookSecretAsFallback(): void
    {
        // When secretKey is empty, should fall back to webhookSecret
        $service = new ContractTokenService(
            $this->createConfigServiceMock('', 'whsec_test123')
        );

        $token = $service->generateToken('contract_123');

        $this->assertNotEmpty($token);
        $this->assertTrue($service->validateToken($token, 'contract_123'));
    }

    /**
     * S1: ContractTokenService must throw when no secret is configured.
     *
     * A hardcoded fallback secret is predictable — tokens become forgeable.
     * The service must refuse to operate without a real secret.
     */
    public function testThrowsExceptionWhenNoSecretConfigured(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('secret');

        $service = new ContractTokenService(
            $this->createConfigServiceMock('', '')
        );
        $service->generateToken('contract_123');
    }

    public function testConstructionSucceedsWithoutConfiguredKeys(): void
    {
        $service = new ContractTokenService(
            $this->createConfigServiceMock('', '')
        );

        $this->assertInstanceOf(ContractTokenService::class, $service);
    }
}
