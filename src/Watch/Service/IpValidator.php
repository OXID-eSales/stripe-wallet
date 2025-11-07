<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

/**
 * IP address validator with CIDR range support
 *
 * Validates IP addresses against allowed IPs or CIDR ranges.
 * Supports both IPv4 and IPv6 addresses.
 *
 * @example
 * ```php
 * $validator = new IpValidator();
 * $validator->isAllowed('192.168.1.100', ['192.168.1.0/24']); // true
 * $validator->isAllowed('10.0.0.1', ['192.168.1.0/24']);      // false
 * ```
 */
final class IpValidator
{
    /**
     * Check if IP address is in the allowed list
     *
     * @param string $clientIp The client IP address to check
     * @param array<string> $allowedIps List of allowed IPs or CIDR ranges
     * @return bool True if IP is allowed
     */
    public function isAllowed(string $clientIp, array $allowedIps): bool
    {
        foreach ($allowedIps as $allowedIp) {
            if ($this->ipMatches($clientIp, $allowedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches allowed IP or CIDR range
     *
     * @param string $ip IP address to check
     * @param string $allowed Allowed IP or CIDR range
     * @return bool True if IP matches
     */
    private function ipMatches(string $ip, string $allowed): bool
    {
        // Exact match
        if ($ip === $allowed) {
            return true;
        }

        // CIDR range check
        if (str_contains($allowed, '/')) {
            return $this->ipInCidrRange($ip, $allowed);
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range
     *
     * Supports both IPv4 and IPv6 CIDR notation.
     *
     * @param string $ip IP address to check
     * @param string $cidr CIDR notation (e.g., 192.168.1.0/24)
     * @return bool True if IP is in range
     */
    private function ipInCidrRange(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        // Try IPv4 first
        if ($this->isIPv4($ip) && $this->isIPv4($subnet)) {
            return $this->ipv4InCidr($ip, $subnet, (int)$mask);
        }

        // Try IPv6
        if ($this->isIPv6($ip) && $this->isIPv6($subnet)) {
            return $this->ipv6InCidr($ip, $subnet, (int)$mask);
        }

        return false;
    }

    /**
     * Check if IPv4 address is in CIDR range
     *
     * @param string $ip IPv4 address
     * @param string $subnet Subnet address
     * @param int $mask Subnet mask (0-32)
     * @return bool True if IP is in range
     */
    private function ipv4InCidr(string $ip, string $subnet, int $mask): bool
    {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskLong = -1 << (32 - $mask);
        $subnetLong &= $maskLong;

        return ($ipLong & $maskLong) === $subnetLong;
    }

    /**
     * Check if IPv6 address is in CIDR range
     *
     * @param string $ip IPv6 address
     * @param string $subnet Subnet address
     * @param int $mask Subnet mask (0-128)
     * @return bool True if IP is in range
     */
    private function ipv6InCidr(string $ip, string $subnet, int $mask): bool
    {
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        $bitsToCheck = $mask;
        $bytes = min(16, (int)ceil($bitsToCheck / 8));

        for ($i = 0; $i < $bytes; $i++) {
            $bitsInByte = min(8, $bitsToCheck);
            $maskByte = (0xFF << (8 - $bitsInByte)) & 0xFF;

            if ((ord($ipBinary[$i]) & $maskByte) !== (ord($subnetBinary[$i]) & $maskByte)) {
                return false;
            }

            $bitsToCheck -= 8;
        }

        return true;
    }

    /**
     * Check if string is valid IPv4 address
     *
     * @param string $ip IP address
     * @return bool True if valid IPv4
     */
    private function isIPv4(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Check if string is valid IPv6 address
     *
     * @param string $ip IP address
     * @return bool True if valid IPv6
     */
    private function isIPv6(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
}
