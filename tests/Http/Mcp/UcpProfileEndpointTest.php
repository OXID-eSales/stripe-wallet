<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Http\Mcp;

use PHPUnit\Framework\TestCase;

/**
 * Tests UCP profile endpoint via actual HTTP.
 *
 * @group sprint-54
 * @group mcp-http
 * @group ucp
 */
final class UcpProfileEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (empty(getenv('SHOP_URL'))) {
            $this->markTestSkipped('SHOP_URL not set');
        }
    }

    public function testProfileReturnsUcpFormat(): void
    {
        $shopUrl = getenv('SHOP_URL') ?: 'http://localhost';

        $ch = curl_init($shopUrl . '/?cl=stripeucpprofile');
        if ($ch === false) {
            $this->fail('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        $this->assertSame(200, $httpCode);
        $this->assertStringContainsString('application/json', $contentType);

        $body = json_decode($response, true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('ucp_version', $body);
        $this->assertSame('2026-01-11', $body['ucp_version']);
        $this->assertArrayHasKey('services', $body);
        $this->assertArrayHasKey('capabilities', $body);
        $this->assertArrayHasKey('payment', $body);
    }
}
