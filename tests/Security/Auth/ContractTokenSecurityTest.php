<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Auth;

use OxidEsales\Payments\Stripe\Service\ContractTokenService;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests ContractTokenService security properties:
 * - Token forgery prevention (HMAC integrity)
 * - Token is tied to specific contract ID
 * - Constant-time comparison (hash_equals)
 * - Finding F10: hardcoded secret fallback
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ContractTokenService
 * @group security
 * @group bsi
 * @group auth
 * @group sprint-58
 */
final class ContractTokenSecurityTest extends TestCase
{
    /**
     * @test
     */
    public function testTokenCannotBeForgedWithoutSecret(): void
    {
        $service = $this->createServiceWithKey('sk_test_real_secret');
        $token = $service->generateToken('contract_001');

        // Modify the base64 token
        $chars = str_split($token);
        $midpoint = (int)(count($chars) / 2);
        $chars[$midpoint] = $chars[$midpoint] === 'a' ? 'b' : 'a';
        $forgedToken = implode('', $chars);

        $this->assertFalse($service->validateToken($forgedToken, 'contract_001'));
    }

    /**
     * @test
     */
    public function testTokenIsTiedToSpecificContractId(): void
    {
        $service = $this->createServiceWithKey('sk_test_binding');

        $token = $service->generateToken('contract_001');

        // Token for contract_001 must fail validation for contract_002
        $this->assertFalse($service->validateToken($token, 'contract_002'));
    }

    /**
     * @test
     */
    public function testExtractContractIdRejectsModifiedHmac(): void
    {
        $service = $this->createServiceWithKey('sk_test_extract');
        $token = $service->generateToken('contract_extract');

        // Decode, modify HMAC, re-encode
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $this->assertIsString($decoded);

        $parts = explode(':', $decoded, 2);
        $this->assertCount(2, $parts);

        // Flip a character in the HMAC
        $hmac = $parts[1];
        $modifiedHmac = $hmac[0] === 'a' ? 'b' . substr($hmac, 1) : 'a' . substr($hmac, 1);
        $modifiedPayload = $parts[0] . ':' . $modifiedHmac;
        $modifiedToken = rtrim(strtr(base64_encode($modifiedPayload), '+/', '-_'), '=');

        $this->assertNull($service->extractContractId($modifiedToken));
    }

    /**
     * @test
     *
     * Compliance: BSI TR-03116 — constant-time comparison
     */
    public function testValidateTokenUsesConstantTimeComparison(): void
    {
        // Verify the source code uses hash_equals
        $sourceFile = dirname(__DIR__, 3) . '/src/Stripe/Service/ContractTokenService.php';
        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('ContractTokenService source not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);
        $this->assertStringContainsString('hash_equals', $source);
    }

    /**
     * @test
     *
     * Finding F10: ContractTokenService falls back to hardcoded secret
     * when neither API key nor webhook secret is configured.
     */
    public function testFallbackToHardcodedSecretWhenNoApiKey(): void
    {
        $service = $this->createServiceWithKey('');

        // Service still works — uses hardcoded fallback
        $token = $service->generateToken('contract_fallback');

        $this->assertNotEmpty($token);
        $this->assertTrue($service->validateToken($token, 'contract_fallback'));
    }

    /**
     * @test
     */
    public function testDifferentApiKeysProduceDifferentTokens(): void
    {
        $service1 = $this->createServiceWithKey('sk_test_shop_alpha');
        $service2 = $this->createServiceWithKey('sk_test_shop_beta');

        $token1 = $service1->generateToken('same_contract');
        $token2 = $service2->generateToken('same_contract');

        // Cross-shop forgery: token from shop A must not validate on shop B
        $this->assertNotSame($token1, $token2);
        $this->assertFalse($service2->validateToken($token1, 'same_contract'));
    }

    /**
     * @test
     */
    public function testEmptyTokenReturnsNullOnExtract(): void
    {
        $service = $this->createServiceWithKey('sk_test');

        $this->assertNull($service->extractContractId(''));
    }

    /**
     * @test
     */
    public function testEmptyTokenReturnsFalseOnValidate(): void
    {
        $service = $this->createServiceWithKey('sk_test');

        $this->assertFalse($service->validateToken('', 'any_contract'));
    }

    private function createServiceWithKey(string $secretKey): ContractTokenService
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getSecretKey')->willReturn($secretKey);
        $config->method('getWebhookSecret')->willReturn($secretKey === '' ? '' : 'whsec_test');

        return new ContractTokenService($config);
    }
}
