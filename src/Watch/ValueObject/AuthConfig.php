<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\ValueObject;

/**
 * Immutable value object representing authentication configuration
 *
 * This object encapsulates the list of allowed hosts with their IP addresses
 * and API keys for PaymentWatch authentication.
 *
 * @example
 * ```php
 * $config = new AuthConfig([
 *     [
 *         'ip' => '192.168.1.100',
 *         'api_key' => 'a1b2c3d4...',  // 64 hex chars
 *         'description' => 'CI Server'
 *     ],
 *     [
 *         'ip' => '10.0.0.0/24',  // CIDR notation supported
 *         'api_key' => 'e5f6g7h8...',
 *         'description' => 'Internal Network'
 *     ]
 * ]);
 * ```
 */
final class AuthConfig
{
    /**
     * @param array<int, array{ip: string, api_key: string, description: string}> $allowedHosts
     * @throws \InvalidArgumentException If configuration is invalid
     */
    public function __construct(
        private readonly array $allowedHosts
    ) {
        $this->validate();
    }

    /**
     * Get all allowed hosts
     *
     * @return array<int, array{ip: string, api_key: string, description: string}>
     */
    public function getAllowedHosts(): array
    {
        return $this->allowedHosts;
    }

    /**
     * Find host configuration by IP address
     *
     * @param string $ip IP address to search for
     * @return array{ip: string, api_key: string, description: string}|null Host config or null if not found
     */
    public function findHostByIp(string $ip): ?array
    {
        foreach ($this->allowedHosts as $host) {
            if ($this->ipMatches($ip, $host['ip'])) {
                return $host;
            }
        }

        return null;
    }

    /**
     * Check if an IP matches a configured IP/CIDR range
     *
     * @param string $ip IP address to check
     * @param string $allowedIp Allowed IP or CIDR range
     * @return bool True if IP matches
     */
    private function ipMatches(string $ip, string $allowedIp): bool
    {
        // Exact match
        if ($ip === $allowedIp) {
            return true;
        }

        // CIDR notation check
        if (str_contains($allowedIp, '/')) {
            return $this->ipInCidrRange($ip, $allowedIp);
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range
     *
     * @param string $ip IP address to check
     * @param string $cidr CIDR notation (e.g., 192.168.1.0/24)
     * @return bool True if IP is in range
     */
    private function ipInCidrRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        // Convert to binary for comparison
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - (int)$mask);
        $subnetLong &= $maskLong;

        return ($ipLong & $maskLong) === $subnetLong;
    }

    /**
     * Validate configuration
     *
     * @throws \InvalidArgumentException If validation fails
     */
    private function validate(): void
    {
        if (empty($this->allowedHosts)) {
            throw new \InvalidArgumentException('At least one allowed host must be configured');
        }

        foreach ($this->allowedHosts as $host) {
            $this->validateHost($host);
        }
    }

    /**
     * Validate a single host configuration
     *
     * @param mixed $host Host configuration to validate
     * @throws \InvalidArgumentException If host configuration is invalid
     */
    private function validateHost(mixed $host): void
    {
        // Check required fields
        if (!is_array($host) || !isset($host['ip'], $host['api_key'], $host['description'])) {
            throw new \InvalidArgumentException(
                'Each host must have ip, api_key, and description fields'
            );
        }

        // Validate IP address
        $this->validateIpAddress($host['ip']);

        // Validate API key
        $this->validateApiKey($host['api_key']);
    }

    /**
     * Validate IP address format (IPv4, IPv6, or CIDR)
     *
     * @param string $ip IP address to validate
     * @throws \InvalidArgumentException If IP format is invalid
     */
    private function validateIpAddress(string $ip): void
    {
        // Handle CIDR notation
        if (str_contains($ip, '/')) {
            [$address, $mask] = explode('/', $ip);

            // Validate mask range
            $maskInt = (int)$mask;
            if ($maskInt < 0 || $maskInt > 32) {
                throw new \InvalidArgumentException(
                    'Invalid IP address format: CIDR mask must be between 0 and 32'
                );
            }

            $ip = $address;
        }

        // Validate IPv4 or IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException(
                sprintf('Invalid IP address format: %s', $ip)
            );
        }
    }

    /**
     * Validate API key format (64 hexadecimal characters)
     *
     * @param string $apiKey API key to validate
     * @throws \InvalidArgumentException If API key format is invalid
     */
    private function validateApiKey(string $apiKey): void
    {
        if (!preg_match('/^[a-fA-F0-9]{64}$/', $apiKey)) {
            throw new \InvalidArgumentException(
                'API key must be 64 hexadecimal characters'
            );
        }
    }
}
