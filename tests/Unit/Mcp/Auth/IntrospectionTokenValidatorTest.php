<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\IntrospectionTokenValidator;
use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidatorInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IntrospectionTokenValidator.
 *
 * Tests OAuth 2.0 Token Introspection (RFC 7662) via mocked HTTP client,
 * including active tokens, inactive tokens, and endpoint failures.
 *
 * @covers \OxidEsales\PaymentComponent\Mcp\Auth\IntrospectionTokenValidator
 */
class IntrospectionTokenValidatorTest extends TestCase
{
    private const INTROSPECTION_ENDPOINT = 'https://auth.example.com/oauth/introspect';
    private const CLIENT_ID = 'mcp_client_id';
    private const CLIENT_SECRET = 'mcp_client_secret';

    private HttpClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    private function createValidator(): IntrospectionTokenValidator
    {
        return new IntrospectionTokenValidator(
            $this->httpClient,
            self::INTROSPECTION_ENDPOINT,
            self::CLIENT_ID,
            self::CLIENT_SECRET
        );
    }

    public function testImplementsInterface(): void
    {
        $validator = $this->createValidator();

        $this->assertInstanceOf(TokenValidatorInterface::class, $validator);
    }

    // ==========================================
    // Active token
    // ==========================================

    public function testActiveTokenReturnsValidResult(): void
    {
        $responseBody = json_encode([
            'active' => true,
            'sub' => 'agent_introspect_1',
            'client_id' => 'client_xyz',
            'scope' => 'mcp:tools mcp:resources',
            'exp' => 1700099999,
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::INTROSPECTION_ENDPOINT,
                $this->callback(function (string $body) {
                    parse_str($body, $params);
                    return ($params['token'] ?? '') === 'opaque_access_token_abc';
                }),
                $this->callback(function (array $headers) {
                    $expectedAuth = 'Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET);
                    return ($headers['Content-Type'] ?? '') === 'application/x-www-form-urlencoded'
                        && ($headers['Authorization'] ?? '') === $expectedAuth;
                }),
                5
            )
            ->willReturn(new HttpClientResponse(200, $responseBody));

        $validator = $this->createValidator();
        $result = $validator->validate('opaque_access_token_abc');

        $this->assertTrue($result->isValid());
        $this->assertSame('agent_introspect_1', $result->getSubject());
        $this->assertSame('client_xyz', $result->getClientId());
        $this->assertSame(['mcp:tools', 'mcp:resources'], $result->getScopes());
        $this->assertSame(1700099999, $result->getExpiresAt());
    }

    public function testActiveTokenWithMissingOptionalFieldsDefaultsGracefully(): void
    {
        $responseBody = json_encode([
            'active' => true,
        ]);

        $this->httpClient
            ->method('post')
            ->willReturn(new HttpClientResponse(200, $responseBody));

        $validator = $this->createValidator();
        $result = $validator->validate('minimal_active_token');

        $this->assertTrue($result->isValid());
        $this->assertSame('unknown', $result->getSubject());
        $this->assertSame('', $result->getClientId());
        $this->assertSame([], $result->getScopes());
        $this->assertSame(0, $result->getExpiresAt());
    }

    // ==========================================
    // Inactive token
    // ==========================================

    public function testInactiveTokenReturnsInvalidResult(): void
    {
        $responseBody = json_encode([
            'active' => false,
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(new HttpClientResponse(200, $responseBody));

        $validator = $this->createValidator();
        $result = $validator->validate('revoked_token_xyz');

        $this->assertFalse($result->isValid());
        $this->assertSame('Token is not active', $result->getErrorMessage());
    }

    public function testMissingActiveFieldTreatedAsInactive(): void
    {
        $responseBody = json_encode([
            'sub' => 'some_user',
        ]);

        $this->httpClient
            ->method('post')
            ->willReturn(new HttpClientResponse(200, $responseBody));

        $validator = $this->createValidator();
        $result = $validator->validate('no_active_field_token');

        $this->assertFalse($result->isValid());
        $this->assertSame('Token is not active', $result->getErrorMessage());
    }

    // ==========================================
    // Endpoint failure
    // ==========================================

    public function testHttpErrorReturnsInvalidResult(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(new HttpClientResponse(500, '', 'Internal Server Error'));

        $validator = $this->createValidator();
        $result = $validator->validate('token_during_outage');

        $this->assertFalse($result->isValid());
        $this->assertSame(
            'Introspection request failed: Internal Server Error',
            $result->getErrorMessage()
        );
    }

    public function testHttpErrorWithoutMessageFallsBackToStatusCode(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(new HttpClientResponse(503, ''));

        $validator = $this->createValidator();
        $result = $validator->validate('token_503');

        $this->assertFalse($result->isValid());
        $this->assertSame(
            'Introspection request failed: HTTP 503',
            $result->getErrorMessage()
        );
    }

    public function testConnectionFailureReturnsInvalidResult(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(HttpClientResponse::failed('Connection refused'));

        $validator = $this->createValidator();
        $result = $validator->validate('token_no_connection');

        $this->assertFalse($result->isValid());
        $this->assertSame(
            'Introspection request failed: Connection refused',
            $result->getErrorMessage()
        );
    }

    public function testMalformedJsonResponseReturnsInvalid(): void
    {
        $this->httpClient
            ->method('post')
            ->willReturn(new HttpClientResponse(200, 'not-json'));

        $validator = $this->createValidator();
        $result = $validator->validate('token_bad_json');

        $this->assertFalse($result->isValid());
        $this->assertSame('Token is not active', $result->getErrorMessage());
    }
}
