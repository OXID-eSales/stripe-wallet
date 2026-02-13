<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\JwtTokenValidator;
use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JwtTokenValidator.
 *
 * Tests JWT parsing, claim validation (issuer, audience, expiration),
 * and handling of malformed tokens.
 *
 * @covers \OxidEsales\PaymentComponent\Mcp\Auth\JwtTokenValidator
 */
class JwtTokenValidatorTest extends TestCase
{
    private const ISSUER = 'https://auth.example.com';
    private const AUDIENCE = 'https://mcp.shop.example.com';
    private const JWKS_URI = 'https://auth.example.com/.well-known/jwks.json';

    private function createValidator(
        string $issuer = self::ISSUER,
        string $audience = self::AUDIENCE
    ): JwtTokenValidator {
        return new JwtTokenValidator($issuer, $audience, self::JWKS_URI);
    }

    private function buildJwt(array $payloadClaims): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode($payloadClaims));

        return $header . '.' . $payload . '.fake-signature';
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public function testImplementsInterface(): void
    {
        $validator = $this->createValidator();

        $this->assertInstanceOf(TokenValidatorInterface::class, $validator);
    }

    // ==========================================
    // Valid JWT
    // ==========================================

    public function testValidJwtWithCorrectClaimsReturnsValid(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => time() + 3600,
            'sub' => 'agent_42',
            'client_id' => 'client_stripe_test',
            'scope' => 'mcp:tools mcp:resources',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertTrue($result->isValid());
        $this->assertSame('agent_42', $result->getSubject());
        $this->assertSame('client_stripe_test', $result->getClientId());
        $this->assertSame(['mcp:tools', 'mcp:resources'], $result->getScopes());
    }

    public function testValidJwtPreservesExpiresAt(): void
    {
        $expiresAt = time() + 7200;
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => $expiresAt,
            'sub' => 'user_1',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertTrue($result->isValid());
        $this->assertSame($expiresAt, $result->getExpiresAt());
    }

    public function testValidJwtWithAudienceArrayReturnsValid(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => ['https://other.api.com', self::AUDIENCE],
            'exp' => time() + 3600,
            'sub' => 'multi_aud_user',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertTrue($result->isValid());
    }

    public function testValidJwtWithAzpClaimUsedAsClientId(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => time() + 3600,
            'sub' => 'user_azp',
            'azp' => 'authorized_party_123',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertTrue($result->isValid());
        $this->assertSame('authorized_party_123', $result->getClientId());
    }

    public function testValidJwtWithMissingSubDefaultsToUnknown(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => time() + 3600,
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertTrue($result->isValid());
        $this->assertSame('unknown', $result->getSubject());
    }

    public function testValidJwtWithNoScopeReturnsEmptyScopes(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => time() + 3600,
            'sub' => 'user_no_scope',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->getScopes());
    }

    // ==========================================
    // Expired JWT
    // ==========================================

    public function testExpiredJwtReturnsInvalid(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => time() - 3600,
            'sub' => 'expired_user',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Token expired', $result->getErrorMessage());
    }

    public function testJwtWithZeroExpirationReturnsInvalid(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'exp' => 0,
            'sub' => 'zero_exp_user',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Token expired', $result->getErrorMessage());
    }

    // ==========================================
    // Wrong issuer
    // ==========================================

    public function testWrongIssuerReturnsInvalid(): void
    {
        $token = $this->buildJwt([
            'iss' => 'https://evil.example.com',
            'aud' => self::AUDIENCE,
            'exp' => time() + 3600,
            'sub' => 'bad_issuer_user',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid issuer', $result->getErrorMessage());
    }

    public function testMissingIssuerReturnsInvalid(): void
    {
        $token = $this->buildJwt([
            'aud' => self::AUDIENCE,
            'exp' => time() + 3600,
            'sub' => 'no_issuer_user',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid issuer', $result->getErrorMessage());
    }

    // ==========================================
    // Wrong audience
    // ==========================================

    public function testWrongAudienceReturnsInvalid(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => 'https://wrong-api.example.com',
            'exp' => time() + 3600,
            'sub' => 'bad_aud_user',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid audience', $result->getErrorMessage());
    }

    public function testMissingAudienceReturnsInvalid(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'exp' => time() + 3600,
            'sub' => 'no_aud_user',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid audience', $result->getErrorMessage());
    }

    public function testAudienceArrayNotContainingExpectedReturnsInvalid(): void
    {
        $token = $this->buildJwt([
            'iss' => self::ISSUER,
            'aud' => ['https://api1.example.com', 'https://api2.example.com'],
            'exp' => time() + 3600,
            'sub' => 'wrong_aud_array_user',
        ]);

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid audience', $result->getErrorMessage());
    }

    // ==========================================
    // Malformed JWT (not 3 parts)
    // ==========================================

    public function testMalformedJwtWithOnePartReturnsInvalid(): void
    {
        $validator = $this->createValidator();
        $result = $validator->validate('single-part-token');

        $this->assertFalse($result->isValid());
        $this->assertSame('Not a valid JWT format', $result->getErrorMessage());
    }

    public function testMalformedJwtWithTwoPartsReturnsInvalid(): void
    {
        $validator = $this->createValidator();
        $result = $validator->validate('two.parts');

        $this->assertFalse($result->isValid());
        $this->assertSame('Not a valid JWT format', $result->getErrorMessage());
    }

    public function testMalformedJwtWithFourPartsReturnsInvalid(): void
    {
        $validator = $this->createValidator();
        $result = $validator->validate('one.two.three.four');

        $this->assertFalse($result->isValid());
        $this->assertSame('Not a valid JWT format', $result->getErrorMessage());
    }

    public function testEmptyStringReturnsInvalid(): void
    {
        $validator = $this->createValidator();
        $result = $validator->validate('');

        $this->assertFalse($result->isValid());
        $this->assertSame('Not a valid JWT format', $result->getErrorMessage());
    }

    // ==========================================
    // Invalid payload
    // ==========================================

    public function testInvalidBase64PayloadReturnsInvalid(): void
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $token = $header . '.!!!invalid-base64!!!.fake-signature';

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid JWT payload', $result->getErrorMessage());
    }

    public function testNonJsonPayloadReturnsInvalid(): void
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode('this is not json');
        $token = $header . '.' . $payload . '.fake-signature';

        $validator = $this->createValidator();
        $result = $validator->validate($token);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid JWT payload', $result->getErrorMessage());
    }
}
