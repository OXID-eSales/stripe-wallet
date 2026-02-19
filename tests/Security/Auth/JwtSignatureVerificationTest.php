<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\JwtTokenValidator;
use PHPUnit\Framework\TestCase;

/**
 * F12: JWT Token Signature Not Verified
 *
 * CRITICAL — OWASP A07:2021, PCI DSS 6.5.10, RFC 7518
 *
 * JwtTokenValidator decodes JWT payload via base64_decode() and validates
 * claims (issuer, audience, expiration) but never verifies the cryptographic
 * signature. An attacker can forge arbitrary JWTs with any claims.
 *
 * @group security
 * @group f12
 * @since Sprint 59
 */
class JwtSignatureVerificationTest extends TestCase
{
    private const ISSUER = 'https://auth.example.com';
    private const AUDIENCE = 'stripe-payment-module';

    private JwtTokenValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new JwtTokenValidator(self::ISSUER, self::AUDIENCE);
    }

    /**
     * Build a JWT string from header, payload, and signature parts.
     *
     * @param array<string, mixed> $payload
     */
    private function buildJwt(array $payload, string $signature = 'fake-signature'): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $headerB64 = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signatureB64 = $this->base64UrlEncode($signature);

        return $headerB64 . '.' . $payloadB64 . '.' . $signatureB64;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => 'user-123',
            'client_id' => 'client-abc',
            'exp' => time() + 3600,
            'scope' => 'payment:read payment:write',
        ];
    }

    /**
     * F12: Forged JWT with valid claims but completely fake signature is accepted.
     *
     * This is the core vulnerability — signature verification is missing.
     */
    public function testForgedJwtWithFakeSignatureIsAccepted(): void
    {
        $jwt = $this->buildJwt($this->validPayload(), 'completely-forged-signature');

        $result = $this->validator->validate($jwt);

        // VULNERABILITY: This should be invalid, but passes because signature is never checked
        $this->assertTrue(
            $result->isValid(),
            'F12: JWT with forged signature should be rejected but is accepted'
        );
    }

    /**
     * F12: Empty signature part is accepted.
     */
    public function testEmptySignatureIsAccepted(): void
    {
        $jwt = $this->buildJwt($this->validPayload(), '');

        $result = $this->validator->validate($jwt);

        $this->assertTrue(
            $result->isValid(),
            'F12: JWT with empty signature should be rejected but is accepted'
        );
    }

    /**
     * F12: Attacker can impersonate any subject by forging claims.
     */
    public function testAttackerCanForgeArbitrarySubjectClaim(): void
    {
        $payload = $this->validPayload();
        $payload['sub'] = 'admin-superuser';

        $jwt = $this->buildJwt($payload, 'attacker-crafted-signature');

        $result = $this->validator->validate($jwt);

        $this->assertTrue($result->isValid());
        $this->assertSame('admin-superuser', $result->getSubject());
    }

    /**
     * F12: Attacker can inject arbitrary scopes.
     */
    public function testAttackerCanForgeArbitraryScopes(): void
    {
        $payload = $this->validPayload();
        $payload['scope'] = 'admin:full payment:write user:delete';

        $jwt = $this->buildJwt($payload, 'forged');

        $result = $this->validator->validate($jwt);

        $this->assertTrue($result->isValid());
        $this->assertContains('admin:full', $result->getScopes());
    }

    /**
     * F12: Algorithm "none" attack — JWT signed with "none" algorithm.
     */
    public function testAlgorithmNoneAttackIsAccepted(): void
    {
        $header = ['alg' => 'none', 'typ' => 'JWT'];
        $headerB64 = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadB64 = $this->base64UrlEncode(json_encode($this->validPayload(), JSON_THROW_ON_ERROR));

        // Algorithm "none" tokens typically have empty signature
        $jwt = $headerB64 . '.' . $payloadB64 . '.';

        $result = $this->validator->validate($jwt);

        // VULNERABILITY: "alg: none" should be explicitly rejected
        $this->assertTrue(
            $result->isValid(),
            'F12: JWT with alg=none should be rejected but is accepted'
        );
    }

    /**
     * Positive: Validator correctly rejects tokens with wrong issuer.
     */
    public function testRejectsWrongIssuer(): void
    {
        $payload = $this->validPayload();
        $payload['iss'] = 'https://evil.com';

        $jwt = $this->buildJwt($payload);

        $result = $this->validator->validate($jwt);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid issuer', $result->getErrorMessage());
    }

    /**
     * Positive: Validator correctly rejects tokens with wrong audience.
     */
    public function testRejectsWrongAudience(): void
    {
        $payload = $this->validPayload();
        $payload['aud'] = 'wrong-audience';

        $jwt = $this->buildJwt($payload);

        $result = $this->validator->validate($jwt);

        $this->assertFalse($result->isValid());
        $this->assertSame('Invalid audience', $result->getErrorMessage());
    }

    /**
     * Positive: Validator correctly rejects expired tokens.
     */
    public function testRejectsExpiredToken(): void
    {
        $payload = $this->validPayload();
        $payload['exp'] = time() - 3600;

        $jwt = $this->buildJwt($payload);

        $result = $this->validator->validate($jwt);

        $this->assertFalse($result->isValid());
        $this->assertSame('Token expired', $result->getErrorMessage());
    }

    /**
     * Positive: Validator rejects malformed JWT (not 3 parts).
     */
    public function testRejectsMalformedJwt(): void
    {
        $result = $this->validator->validate('not.a.valid.jwt.token');

        $this->assertFalse($result->isValid());
    }

    /**
     * Positive: Validator rejects JWT with only 2 parts.
     */
    public function testRejectsJwtWithOnlyTwoParts(): void
    {
        $result = $this->validator->validate('header.payload');

        $this->assertFalse($result->isValid());
        $this->assertSame('Not a valid JWT format', $result->getErrorMessage());
    }

    /**
     * F12: Different forged signatures on same payload all succeed.
     */
    public function testDifferentForgedSignaturesAllSucceed(): void
    {
        $payload = $this->validPayload();
        $signatures = ['sig-a', 'sig-b', 'completely-different', str_repeat('x', 256)];

        foreach ($signatures as $signature) {
            $jwt = $this->buildJwt($payload, $signature);
            $result = $this->validator->validate($jwt);

            $this->assertTrue(
                $result->isValid(),
                "F12: JWT with signature '{$signature}' should be rejected"
            );
        }
    }

    /**
     * F12: Signature part is completely ignored — payload extraction succeeds.
     */
    public function testSignaturePartIsNeverInspected(): void
    {
        $payload = $this->validPayload();

        // Use binary garbage as signature
        $binarySignature = random_bytes(64);
        $jwt = $this->buildJwt($payload, $binarySignature);

        $result = $this->validator->validate($jwt);

        $this->assertTrue($result->isValid());
        $this->assertSame('user-123', $result->getSubject());
        $this->assertSame('client-abc', $result->getClientId());
    }

    /**
     * Positive: Audience as array is supported.
     */
    public function testAcceptsAudienceAsArray(): void
    {
        $payload = $this->validPayload();
        $payload['aud'] = ['other-service', self::AUDIENCE, 'another-service'];

        $jwt = $this->buildJwt($payload);

        $result = $this->validator->validate($jwt);

        $this->assertTrue($result->isValid());
    }
}
