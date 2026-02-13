<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TokenValidationResult value object.
 *
 * Tests the valid() and invalid() factory methods and claim accessors.
 *
 * @covers \OxidEsales\PaymentComponent\Mcp\Auth\TokenValidationResult
 */
class TokenValidationResultTest extends TestCase
{
    // ==========================================
    // valid() factory
    // ==========================================

    public function testValidFactoryReturnsValidResult(): void
    {
        $result = TokenValidationResult::valid('user_123', 'client_abc', ['read', 'write'], 1700000000);

        $this->assertTrue($result->isValid());
    }

    public function testValidFactoryPreservesSubject(): void
    {
        $result = TokenValidationResult::valid('agent_007', 'client_x', [], 1700000000);

        $this->assertSame('agent_007', $result->getSubject());
    }

    public function testValidFactoryPreservesClientId(): void
    {
        $result = TokenValidationResult::valid('user_1', 'client_stripe_live', [], 1700000000);

        $this->assertSame('client_stripe_live', $result->getClientId());
    }

    public function testValidFactoryPreservesScopes(): void
    {
        $scopes = ['mcp:tools', 'mcp:resources', 'payments:write'];
        $result = TokenValidationResult::valid('user_1', 'client_1', $scopes, 1700000000);

        $this->assertSame($scopes, $result->getScopes());
    }

    public function testValidFactoryPreservesExpiresAt(): void
    {
        $expiresAt = 1700099999;
        $result = TokenValidationResult::valid('user_1', 'client_1', [], $expiresAt);

        $this->assertSame($expiresAt, $result->getExpiresAt());
    }

    public function testValidFactoryHasNullErrorMessage(): void
    {
        $result = TokenValidationResult::valid('user_1', 'client_1', [], 1700000000);

        $this->assertNull($result->getErrorMessage());
    }

    // ==========================================
    // invalid() factory
    // ==========================================

    public function testInvalidFactoryReturnsInvalidResult(): void
    {
        $result = TokenValidationResult::invalid('Token expired');

        $this->assertFalse($result->isValid());
    }

    public function testInvalidFactoryPreservesErrorMessage(): void
    {
        $result = TokenValidationResult::invalid('Invalid issuer');

        $this->assertSame('Invalid issuer', $result->getErrorMessage());
    }

    public function testInvalidFactoryHasNullSubject(): void
    {
        $result = TokenValidationResult::invalid('Bad token');

        $this->assertNull($result->getSubject());
    }

    public function testInvalidFactoryHasNullClientId(): void
    {
        $result = TokenValidationResult::invalid('Bad token');

        $this->assertNull($result->getClientId());
    }

    public function testInvalidFactoryHasEmptyScopes(): void
    {
        $result = TokenValidationResult::invalid('Bad token');

        $this->assertSame([], $result->getScopes());
    }

    public function testInvalidFactoryHasNullExpiresAt(): void
    {
        $result = TokenValidationResult::invalid('Bad token');

        $this->assertNull($result->getExpiresAt());
    }

    // ==========================================
    // Edge cases
    // ==========================================

    public function testValidWithEmptyScopes(): void
    {
        $result = TokenValidationResult::valid('user_1', 'client_1', [], 1700000000);

        $this->assertSame([], $result->getScopes());
        $this->assertTrue($result->isValid());
    }

    public function testValidWithEmptySubjectAndClientId(): void
    {
        $result = TokenValidationResult::valid('', '', ['scope1'], 0);

        $this->assertTrue($result->isValid());
        $this->assertSame('', $result->getSubject());
        $this->assertSame('', $result->getClientId());
    }
}
