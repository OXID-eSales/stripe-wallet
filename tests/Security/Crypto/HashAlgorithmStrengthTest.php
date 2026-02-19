<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Crypto;

use PHPUnit\Framework\TestCase;

/**
 * Validates that security-critical code paths use BSI-approved hash algorithms
 * and do not rely on deprecated algorithms like MD5.
 *
 * @group security
 * @group bsi
 * @group crypto
 * @group sprint-58
 */
final class HashAlgorithmStrengthTest extends TestCase
{
    /**
     * @test
     */
    public function testSha256IsAvailable(): void
    {
        $this->assertContains('sha256', hash_algos(), 'SHA-256 must be available');
    }

    /**
     * @test
     */
    public function testHmacSha256OutputLength(): void
    {
        $hmac = hash_hmac('sha256', 'test', 'secret');

        // 256 bits = 32 bytes = 64 hex characters
        $this->assertSame(64, strlen($hmac), 'HMAC-SHA-256 output must be 64 hex chars');
    }

    /**
     * @test
     *
     * Compliance: BSI TR-03116 — No MD5 in security-critical paths
     */
    public function testNoMd5InContractTokenService(): void
    {
        $sourceFile = dirname(__DIR__, 3) . '/src/Stripe/Service/ContractTokenService.php';
        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('ContractTokenService.php not found at expected path');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        $this->assertStringNotContainsString(
            "hash_hmac('md5'",
            $source,
            'ContractTokenService must not use MD5 HMAC'
        );
        $this->assertStringNotContainsString(
            'hash("md5"',
            $source,
            'ContractTokenService must not use MD5 hash'
        );
        $this->assertStringNotContainsString(
            "md5(",
            $source,
            'ContractTokenService must not use md5() function'
        );
    }

    /**
     * @test
     *
     * Compliance: BSI TR-03116 — No MD5 in MCP auth guard
     */
    public function testNoMd5InMcpAuthGuard(): void
    {
        $sourceFile = dirname(__DIR__, 3) . '/../payment-component/src/Mcp/Auth/OAuthMcpAuthGuard.php';
        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('OAuthMcpAuthGuard.php not found at expected path');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        $this->assertStringNotContainsString(
            "md5(",
            $source,
            'McpAuthGuard must not use md5() function'
        );
    }

    /**
     * @test
     *
     * Validates that SHA-256 produces consistent output across PHP versions.
     */
    public function testSha256CrossVersionConsistency(): void
    {
        // Known test vector: SHA-256 of empty string
        $expected = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $this->assertSame($expected, hash('sha256', ''));
    }
}
