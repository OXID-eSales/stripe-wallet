<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\ProtectedResourceMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProtectedResourceMetadata value object.
 *
 * Tests RFC 9470 protected resource metadata serialization via
 * toArray() and toJson() methods.
 *
 * @covers \OxidEsales\PaymentComponent\Mcp\Auth\ProtectedResourceMetadata
 */
class ProtectedResourceMetadataTest extends TestCase
{
    // ==========================================
    // toArray() tests
    // ==========================================

    public function testToArrayContainsResourceKey(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            ['https://auth.example.com']
        );

        $array = $metadata->toArray();

        $this->assertArrayHasKey('resource', $array);
        $this->assertSame('https://mcp.shop.example.com', $array['resource']);
    }

    public function testToArrayContainsAuthorizationServers(): void
    {
        $servers = ['https://auth1.example.com', 'https://auth2.example.com'];
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            $servers
        );

        $array = $metadata->toArray();

        $this->assertArrayHasKey('authorization_servers', $array);
        $this->assertSame($servers, $array['authorization_servers']);
    }

    public function testToArrayContainsDefaultScopesSupported(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            ['https://auth.example.com']
        );

        $array = $metadata->toArray();

        $this->assertArrayHasKey('scopes_supported', $array);
        $this->assertSame(['mcp:tools', 'mcp:resources'], $array['scopes_supported']);
    }

    public function testToArrayContainsDefaultBearerMethodsSupported(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            ['https://auth.example.com']
        );

        $array = $metadata->toArray();

        $this->assertArrayHasKey('bearer_methods_supported', $array);
        $this->assertSame(['header'], $array['bearer_methods_supported']);
    }

    public function testToArrayWithCustomScopes(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            ['https://auth.example.com'],
            ['mcp:tools', 'mcp:resources', 'payments:read', 'payments:write']
        );

        $array = $metadata->toArray();

        $this->assertSame(
            ['mcp:tools', 'mcp:resources', 'payments:read', 'payments:write'],
            $array['scopes_supported']
        );
    }

    public function testToArrayWithCustomBearerMethods(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            ['https://auth.example.com'],
            ['mcp:tools'],
            ['header', 'body']
        );

        $array = $metadata->toArray();

        $this->assertSame(['header', 'body'], $array['bearer_methods_supported']);
    }

    public function testToArrayHasExactlyFourKeys(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            ['https://auth.example.com']
        );

        $array = $metadata->toArray();

        $this->assertCount(4, $array);
        $this->assertSame(
            ['resource', 'authorization_servers', 'scopes_supported', 'bearer_methods_supported'],
            array_keys($array)
        );
    }

    // ==========================================
    // toJson() tests
    // ==========================================

    public function testToJsonReturnsValidJsonString(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            ['https://auth.example.com']
        );

        $json = $metadata->toJson();
        $decoded = json_decode($json, true);

        $this->assertNotNull($decoded);
        $this->assertIsArray($decoded);
    }

    public function testToJsonMatchesToArrayData(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            ['https://auth.example.com'],
            ['mcp:tools'],
            ['header']
        );

        $json = $metadata->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame($metadata->toArray(), $decoded);
    }

    public function testToJsonDoesNotEscapeSlashesInUrls(): void
    {
        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com/api/v1',
            ['https://auth.example.com/oauth']
        );

        $json = $metadata->toJson();

        $this->assertStringContainsString('https://mcp.shop.example.com/api/v1', $json);
        $this->assertStringContainsString('https://auth.example.com/oauth', $json);
        $this->assertStringNotContainsString('\\/', $json);
    }

    public function testToJsonWithMultipleAuthorizationServers(): void
    {
        $servers = [
            'https://auth-primary.example.com',
            'https://auth-secondary.example.com',
        ];

        $metadata = new ProtectedResourceMetadata(
            'https://mcp.shop.example.com',
            $servers
        );

        $json = $metadata->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame($servers, $decoded['authorization_servers']);
    }
}
