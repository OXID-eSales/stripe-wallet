<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\OAuthMcpAuthGuard;
use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidationResult;
use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidatorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OAuthMcpAuthGuard.
 *
 * Tests the two-tier authentication: static token fallback and OAuth token
 * validation. Uses $_SERVER['HTTP_AUTHORIZATION'] to simulate Bearer tokens.
 *
 * @covers \OxidEsales\PaymentComponent\Mcp\Auth\OAuthMcpAuthGuard
 */
class OAuthMcpAuthGuardTest extends TestCase
{
    private TokenValidatorInterface&MockObject $tokenValidator;

    protected function setUp(): void
    {
        $this->tokenValidator = $this->createMock(TokenValidatorInterface::class);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    private function createGuard(string $staticToken = ''): OAuthMcpAuthGuard
    {
        return new OAuthMcpAuthGuard($this->tokenValidator, $staticToken);
    }

    public function testImplementsInterface(): void
    {
        $guard = $this->createGuard();

        $this->assertInstanceOf(McpAuthGuardInterface::class, $guard);
    }

    // ==========================================
    // Missing Authorization header
    // ==========================================

    public function testMissingAuthorizationHeaderReturnsFailed(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $guard = $this->createGuard('static_secret_token');
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Missing Bearer token', $result->getErrorMessage());
    }

    public function testNonBearerAuthorizationHeaderReturnsFailed(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $guard = $this->createGuard('static_secret_token');
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Missing Bearer token', $result->getErrorMessage());
    }

    // ==========================================
    // Static token fallback
    // ==========================================

    public function testStaticTokenMatchReturnsSuccess(): void
    {
        $staticToken = 'my_static_api_key_123';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $staticToken;

        $this->tokenValidator
            ->expects($this->never())
            ->method('validate');

        $guard = $this->createGuard($staticToken);
        $result = $guard->authenticate();

        $this->assertTrue($result->isAuthenticated());

        $agentContext = $result->getAgentContext();
        $this->assertSame($staticToken, $agentContext->getToken());
        $this->assertSame('bearer_static', $agentContext->getMetadata('auth_method'));
    }

    public function testStaticTokenMatchGeneratesAgentIdFromHash(): void
    {
        $staticToken = 'deterministic_token';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $staticToken;

        $expectedPrefix = 'agent_' . substr(hash('sha256', $staticToken), 0, 8);

        $guard = $this->createGuard($staticToken);
        $result = $guard->authenticate();

        $this->assertTrue($result->isAuthenticated());
        $this->assertSame($expectedPrefix, $result->getAgentContext()->getAgentId());
    }

    public function testStaticTokenMismatchFallsToOAuth(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer oauth_token_xyz';

        $validationResult = TokenValidationResult::valid(
            'oauth_subject',
            'oauth_client',
            ['mcp:tools'],
            time() + 3600
        );

        $this->tokenValidator
            ->expects($this->once())
            ->method('validate')
            ->with('oauth_token_xyz')
            ->willReturn($validationResult);

        $guard = $this->createGuard('different_static_token');
        $result = $guard->authenticate();

        $this->assertTrue($result->isAuthenticated());
        $this->assertSame('oauth', $result->getAgentContext()->getMetadata('auth_method'));
    }

    // ==========================================
    // OAuth token validation
    // ==========================================

    public function testOAuthTokenValidationReturnsSuccess(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer valid_oauth_token';

        $expiresAt = time() + 7200;
        $validationResult = TokenValidationResult::valid(
            'agent_oauth_42',
            'client_mcp_stripe',
            ['mcp:tools', 'mcp:resources'],
            $expiresAt
        );

        $this->tokenValidator
            ->expects($this->once())
            ->method('validate')
            ->with('valid_oauth_token')
            ->willReturn($validationResult);

        $guard = $this->createGuard();
        $result = $guard->authenticate();

        $this->assertTrue($result->isAuthenticated());

        $agentContext = $result->getAgentContext();
        $this->assertSame('agent_oauth_42', $agentContext->getAgentId());
        $this->assertSame('valid_oauth_token', $agentContext->getToken());
        $this->assertSame('oauth', $agentContext->getMetadata('auth_method'));
        $this->assertSame('client_mcp_stripe', $agentContext->getMetadata('client_id'));
        $this->assertSame(['mcp:tools', 'mcp:resources'], $agentContext->getMetadata('scopes'));
        $this->assertSame($expiresAt, $agentContext->getMetadata('expires_at'));
    }

    public function testInvalidOAuthTokenReturnsFailed(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer expired_token';

        $this->tokenValidator
            ->expects($this->once())
            ->method('validate')
            ->with('expired_token')
            ->willReturn(TokenValidationResult::invalid('Token expired'));

        $guard = $this->createGuard();
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Token expired', $result->getErrorMessage());
    }

    // ==========================================
    // Both fail = rejected
    // ==========================================

    public function testBothStaticAndOAuthFailReturnsFailed(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong_token';

        $this->tokenValidator
            ->expects($this->once())
            ->method('validate')
            ->with('wrong_token')
            ->willReturn(TokenValidationResult::invalid('Invalid signature'));

        $guard = $this->createGuard('correct_static_token');
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Invalid signature', $result->getErrorMessage());
    }

    // ==========================================
    // Edge cases
    // ==========================================

    public function testEmptyStaticTokenSkipsStaticCheck(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some_token';

        $this->tokenValidator
            ->expects($this->once())
            ->method('validate')
            ->with('some_token')
            ->willReturn(TokenValidationResult::valid('user_1', 'client_1', [], time() + 3600));

        $guard = $this->createGuard('');
        $result = $guard->authenticate();

        $this->assertTrue($result->isAuthenticated());
        $this->assertSame('oauth', $result->getAgentContext()->getMetadata('auth_method'));
    }

    public function testOAuthValidationWithNullErrorMessageFallsBackToDefault(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer null_error_token';

        $invalidResult = TokenValidationResult::invalid('Invalid token');
        $this->tokenValidator
            ->method('validate')
            ->willReturn($invalidResult);

        $guard = $this->createGuard();
        $result = $guard->authenticate();

        $this->assertFalse($result->isAuthenticated());
        $this->assertSame('Invalid token', $result->getErrorMessage());
    }
}
