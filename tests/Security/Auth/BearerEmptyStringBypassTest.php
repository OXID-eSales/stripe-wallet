<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuard;
use PHPUnit\Framework\TestCase;

/**
 * F20: Bearer Token Empty String Bypass
 *
 * HIGH — OWASP A07:2021
 *
 * After substr($header, 7), empty token is possible if header is exactly
 * "Bearer ". If $expectedToken is also empty (misconfigured),
 * hash_equals('', '') returns true — authentication bypassed.
 *
 * @group security
 * @group f20
 * @since Sprint 60
 */
class BearerEmptyStringBypassTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * F20: "Bearer " with empty suffix bypasses auth when expected token is empty.
     *
     * This is the actual bypass scenario: misconfigured server with empty
     * expected token + attacker sends "Bearer " header.
     */
    public function testBearerWithEmptySuffixBypassesWhenExpectedEmpty(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';

        // Misconfigured: empty expected token
        $guard = new McpAuthGuard('');

        $result = $guard->authenticate();

        // The guard checks $this->expectedToken === '' first and rejects,
        // so this is actually protected by the empty-expected-token check.
        // But let's verify the behavior:
        $this->assertFalse(
            $result->isAuthenticated(),
            'Empty expected token should always fail authentication'
        );
    }

    /**
     * F20: Verify that empty expected token is always rejected.
     */
    public function testEmptyExpectedTokenAlwaysRejects(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-valid-looking-token';

        $guard = new McpAuthGuard('');

        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Invalid token', $result->getErrorMessage());
    }

    /**
     * F20: "Bearer " with trailing whitespace — substr yields space.
     */
    public function testBearerWithTrailingSpaceYieldsSpaceToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer  ';

        $guard = new McpAuthGuard('valid-token');

        $result = $guard->authenticate();

        // Space token should not match
        $this->assertFalse($result->isAuthenticated());
    }

    /**
     * F20: hash_equals('', '') returns true — the underlying vulnerability.
     */
    public function testHashEqualsEmptyStringsReturnsTrue(): void
    {
        // Document the PHP behavior that enables this bypass
        $this->assertTrue(
            hash_equals('', ''),
            'F20: hash_equals("", "") returns true — empty string matches empty string'
        );
    }

    /**
     * F20: substr("Bearer ", 7) produces empty string.
     */
    public function testSubstrBearerSpaceProducesEmptyString(): void
    {
        $header = 'Bearer ';
        $token = substr($header, 7);

        $this->assertSame(
            '',
            $token,
            'F20: substr("Bearer ", 7) yields empty string'
        );
    }

    /**
     * F20: The guard does NOT trim the extracted token.
     */
    public function testTokenIsNotTrimmed(): void
    {
        $sourceFile = dirname(__DIR__, 4)
            . '/source/extensions/payment-component/src/Mcp/Auth/McpAuthGuard.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        $this->assertStringNotContainsString(
            'trim($token)',
            $source,
            'F20: Token is not trimmed before comparison'
        );
        $this->assertStringNotContainsString(
            'trim(substr',
            $source,
            'F20: substr result is not trimmed'
        );
    }

    /**
     * Positive: Valid token authentication succeeds.
     */
    public function testValidTokenAuthenticationSucceeds(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer my-secret-token-123';

        $guard = new McpAuthGuard('my-secret-token-123');

        $result = $guard->authenticate();

        $this->assertTrue($result->isAuthenticated());
    }

    /**
     * Positive: Missing Authorization header fails.
     */
    public function testMissingAuthorizationHeaderFails(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $guard = new McpAuthGuard('valid-token');

        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Missing Bearer token', $result->getErrorMessage());
    }

    /**
     * Positive: Wrong token fails.
     */
    public function testWrongTokenFails(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';

        $guard = new McpAuthGuard('correct-token');

        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
    }
}
