<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\OAuthMcpAuthGuard;
use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidatorInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests MCP auth guard security: rejects invalid tokens, timing-safe comparison.
 *
 * @covers \OxidEsales\PaymentComponent\Mcp\Auth\OAuthMcpAuthGuard
 * @group security
 * @group auth
 * @group sprint-58
 */
final class McpAuthGuardSecurityTest extends TestCase
{
    private const VALID_TOKEN = 'test_valid_token_12345678';
    private TokenValidatorInterface&MockObject $tokenValidator;

    protected function setUp(): void
    {
        $this->tokenValidator = $this->createMock(TokenValidatorInterface::class);
        $this->tokenValidator->method('validate')
            ->willReturn(TokenValidationResult::invalid('Not a valid OAuth token'));
    }

    /**
     * @test
     */
    public function testRejectsMissingBearerPrefix(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Token ' . self::VALID_TOKEN;
        $guard = new OAuthMcpAuthGuard($this->tokenValidator, self::VALID_TOKEN);

        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * @test
     */
    public function testRejectsEmptyToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';
        $guard = new OAuthMcpAuthGuard($this->tokenValidator, self::VALID_TOKEN);

        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * @test
     */
    public function testRejectsNullAuthHeader(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $guard = new OAuthMcpAuthGuard($this->tokenValidator, self::VALID_TOKEN);

        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
    }

    /**
     * @test
     */
    public function testRejectsWrongToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong_token_value';
        $guard = new OAuthMcpAuthGuard($this->tokenValidator, self::VALID_TOKEN);

        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * @test
     */
    public function testRejectsEmptyExpectedToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some_token';

        // Empty static token — falls through to OAuth validation which also fails
        $guard = new OAuthMcpAuthGuard($this->tokenValidator, '');

        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * @test
     */
    public function testAcceptsValidToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::VALID_TOKEN;
        $guard = new OAuthMcpAuthGuard($this->tokenValidator, self::VALID_TOKEN);

        $result = $guard->authenticate();

        $this->assertTrue($result->isAuthenticated());
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * @test
     *
     * Agent ID should not leak the full token.
     */
    public function testAgentIdDoesNotLeakFullToken(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::VALID_TOKEN;
        $guard = new OAuthMcpAuthGuard($this->tokenValidator, self::VALID_TOKEN);

        $result = $guard->authenticate();
        $agentId = $result->getAgentContext()->getAgentId();

        $this->assertStringNotContainsString(self::VALID_TOKEN, $agentId);
        $this->assertStringStartsWith('agent_', $agentId);
        // agent_ prefix + 8 chars of SHA-256 hash
        $this->assertSame(14, strlen($agentId));
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    /**
     * @test
     */
    public function testAgentIdIsDeterministic(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::VALID_TOKEN;
        $guard = new OAuthMcpAuthGuard($this->tokenValidator, self::VALID_TOKEN);

        $result1 = $guard->authenticate();
        $result2 = $guard->authenticate();

        $this->assertSame(
            $result1->getAgentContext()->getAgentId(),
            $result2->getAgentContext()->getAgentId()
        );
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }
}
