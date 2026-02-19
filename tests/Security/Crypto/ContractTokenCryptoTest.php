<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Crypto;

use OxidEsales\Payments\Stripe\Service\ContractTokenService;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Validates ContractTokenService cryptographic compliance with BSI TR-03116.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ContractTokenService
 * @group security
 * @group bsi
 * @group crypto
 * @group sprint-58
 */
final class ContractTokenCryptoTest extends TestCase
{
    private ContractTokenService $tokenService;

    protected function setUp(): void
    {
        $configService = $this->createConfigMock('sk_test_12345678901234567890');
        $this->tokenService = new ContractTokenService($configService);
    }

    /**
     * @test
     *
     * Compliance: BSI TR-03116 — HMAC-SHA-256
     */
    public function testTokenUsesHmacSha256(): void
    {
        $token = $this->tokenService->generateToken('contract_001');

        // Decode the base64url token
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $this->assertIsString($decoded);

        // Token format: contractId:hmac
        $parts = explode(':', $decoded, 2);
        $this->assertCount(2, $parts, 'Token must contain contractId:hmac');

        $hmac = $parts[1];
        // SHA-256 HMAC produces 64 hex characters
        $this->assertSame(64, strlen($hmac), 'HMAC must be 64 hex characters (SHA-256)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hmac);
    }

    /**
     * @test
     *
     * Compliance: BSI TR-03116 — URL-safe encoding
     */
    public function testTokenIsUrlSafe(): void
    {
        $token = $this->tokenService->generateToken('contract_url_safe_test');

        // No +, /, or = characters (URL-safe base64)
        $this->assertStringNotContainsString('+', $token);
        $this->assertStringNotContainsString('/', $token);
        $this->assertStringNotContainsString('=', $token);
    }

    /**
     * @test
     */
    public function testSingleBitFlipInTokenInvalidatesExtraction(): void
    {
        $token = $this->tokenService->generateToken('contract_bitflip');

        // Flip one character in the token
        $chars = str_split($token);
        $midpoint = (int)(count($chars) / 2);
        $chars[$midpoint] = $chars[$midpoint] === 'A' ? 'B' : 'A';
        $flippedToken = implode('', $chars);

        $this->assertNull($this->tokenService->extractContractId($flippedToken));
    }

    /**
     * @test
     */
    public function testDifferentContractIdsProduceDifferentTokens(): void
    {
        $token1 = $this->tokenService->generateToken('contract_001');
        $token2 = $this->tokenService->generateToken('contract_002');

        $this->assertNotSame($token1, $token2);
    }

    /**
     * @test
     */
    public function testSecretDerivedFromApiKeyViaHmac(): void
    {
        // Different API keys should produce different tokens for the same contract
        $service1 = new ContractTokenService($this->createConfigMock('sk_test_key_alpha'));
        $service2 = new ContractTokenService($this->createConfigMock('sk_test_key_beta'));

        $token1 = $service1->generateToken('same_contract');
        $token2 = $service2->generateToken('same_contract');

        $this->assertNotSame($token1, $token2, 'Different API keys must produce different tokens');
    }

    /**
     * @test
     */
    public function testTokenIsDeterministic(): void
    {
        $token1 = $this->tokenService->generateToken('contract_deterministic');
        $token2 = $this->tokenService->generateToken('contract_deterministic');

        $this->assertSame($token1, $token2, 'Same inputs must produce identical tokens');
    }

    private function createConfigMock(string $secretKey): ModuleConfigurationServiceInterface&MockObject
    {
        $mock = $this->createMock(ModuleConfigurationServiceInterface::class);
        $mock->method('getSecretKey')->willReturn($secretKey);
        $mock->method('getWebhookSecret')->willReturn('whsec_test_webhook');
        return $mock;
    }
}
