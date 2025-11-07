<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch\ValueObject;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 * @group watch
 * @group value-object
 */
class AuthConfigTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_auth_config_with_single_host(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'CI Server'
            ]
        ]);

        $this->assertInstanceOf(AuthConfig::class, $config);
    }

    /**
     * @test
     */
    public function it_creates_auth_config_with_multiple_hosts(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'CI Server'
            ],
            [
                'ip' => '10.0.0.50',
                'api_key' => str_repeat('b', 64),
                'description' => 'Test Server'
            ]
        ]);

        $this->assertCount(2, $config->getAllowedHosts());
    }

    /**
     * @test
     */
    public function it_validates_api_key_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key must be 64 hexadecimal characters');

        new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => 'invalid_key',
                'description' => 'Test'
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_validates_api_key_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key must be 64 hexadecimal characters');

        new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 32), // Too short
                'description' => 'Test'
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_validates_api_key_is_hexadecimal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key must be 64 hexadecimal characters');

        new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('z', 64), // Not hex
                'description' => 'Test'
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_accepts_uppercase_hex_api_key(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('A', 64),
                'description' => 'Test'
            ]
        ]);

        $this->assertInstanceOf(AuthConfig::class, $config);
    }

    /**
     * @test
     */
    public function it_accepts_mixed_case_hex_api_key(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => 'a1b2c3d4' . str_repeat('A1B2C3D4', 7),
                'description' => 'Test'
            ]
        ]);

        $this->assertInstanceOf(AuthConfig::class, $config);
    }

    /**
     * @test
     */
    public function it_supports_cidr_notation(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.0/24',
                'api_key' => str_repeat('a', 64),
                'description' => 'Internal Network'
            ]
        ]);

        $hosts = $config->getAllowedHosts();
        $this->assertEquals('192.168.1.0/24', $hosts[0]['ip']);
    }

    /**
     * @test
     */
    public function it_validates_ipv4_address_format(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'Test'
            ]
        ]);

        $this->assertInstanceOf(AuthConfig::class, $config);
    }

    /**
     * @test
     */
    public function it_rejects_invalid_ip_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IP address format');

        new AuthConfig([
            [
                'ip' => '999.999.999.999',
                'api_key' => str_repeat('a', 64),
                'description' => 'Test'
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_rejects_invalid_cidr_notation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IP address format');

        new AuthConfig([
            [
                'ip' => '192.168.1.0/33', // CIDR must be 0-32
                'api_key' => str_repeat('a', 64),
                'description' => 'Test'
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_supports_ipv6_addresses(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                'api_key' => str_repeat('a', 64),
                'description' => 'IPv6 Test'
            ]
        ]);

        $this->assertInstanceOf(AuthConfig::class, $config);
    }

    /**
     * @test
     */
    public function it_supports_compressed_ipv6_addresses(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '2001:db8::1',
                'api_key' => str_repeat('a', 64),
                'description' => 'IPv6 Test'
            ]
        ]);

        $this->assertInstanceOf(AuthConfig::class, $config);
    }

    /**
     * @test
     */
    public function it_returns_all_allowed_hosts(): void
    {
        $hosts = [
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'CI Server'
            ],
            [
                'ip' => '10.0.0.50',
                'api_key' => str_repeat('b', 64),
                'description' => 'Test Server'
            ]
        ];

        $config = new AuthConfig($hosts);

        $this->assertEquals($hosts, $config->getAllowedHosts());
    }

    /**
     * @test
     */
    public function it_finds_host_by_ip(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'CI Server'
            ]
        ]);

        $host = $config->findHostByIp('192.168.1.100');

        $this->assertNotNull($host);
        $this->assertEquals('192.168.1.100', $host['ip']);
        $this->assertEquals(str_repeat('a', 64), $host['api_key']);
    }

    /**
     * @test
     */
    public function it_returns_null_for_unknown_ip(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'CI Server'
            ]
        ]);

        $host = $config->findHostByIp('10.0.0.1');

        $this->assertNull($host);
    }

    /**
     * @test
     */
    public function it_rejects_empty_hosts_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one allowed host must be configured');

        new AuthConfig([]);
    }

    /**
     * @test
     */
    public function it_rejects_missing_ip_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each host must have ip, api_key, and description fields');

        new AuthConfig([
            [
                'api_key' => str_repeat('a', 64),
                'description' => 'Test'
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_rejects_missing_api_key_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each host must have ip, api_key, and description fields');

        new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'description' => 'Test'
            ]
        ]);
    }

    /**
     * @test
     */
    public function it_is_immutable(): void
    {
        $config = new AuthConfig([
            [
                'ip' => '192.168.1.100',
                'api_key' => str_repeat('a', 64),
                'description' => 'Test'
            ]
        ]);

        $reflection = new \ReflectionClass($config);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }
}
