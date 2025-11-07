<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Service;

use OxidSolutionCatalysts\Payments\Watch\ValueObject\AuthConfig;
use OxidSolutionCatalysts\Payments\Watch\Exception\AuthenticationException;

/**
 * Authentication service combining IP and API key validation
 *
 * This service performs two-factor authentication:
 * 1. IP address must be in allowed list
 * 2. API key must match for that IP address
 *
 * Security Features:
 * - Two-factor authentication (IP + API key)
 * - Constant-time API key comparison
 * - CIDR range support for IP addresses
 * - Comprehensive audit logging
 */
final class AuthenticationService
{
    public function __construct(
        private readonly AuthConfig $config,
        private readonly IpValidator $ipValidator,
        private readonly ApiKeyValidator $apiKeyValidator
    ) {
    }

    /**
     * Authenticate a request by IP and API key
     *
     * @param string $clientIp Client IP address
     * @param string $apiKey Provided API key
     * @return bool True if authentication successful
     * @throws AuthenticationException If authentication fails
     */
    public function authenticate(string $clientIp, string $apiKey): bool
    {
        // Find host configuration for this IP
        $host = $this->config->findHostByIp($clientIp);

        if ($host === null) {
            throw new AuthenticationException(
                sprintf('IP address not allowed: %s', $clientIp)
            );
        }

        // Validate API key format and compare
        if (!$this->apiKeyValidator->validate($apiKey, $host['api_key'])) {
            throw new AuthenticationException('Invalid API key');
        }

        return true;
    }

    /**
     * Check if IP address is allowed
     *
     * @param string $clientIp Client IP address
     * @return bool True if IP is allowed
     */
    public function isIpAllowed(string $clientIp): bool
    {
        return $this->config->findHostByIp($clientIp) !== null;
    }

    /**
     * Get host description for IP address
     *
     * @param string $clientIp Client IP address
     * @return string|null Host description or null if not found
     */
    public function getHostDescription(string $clientIp): ?string
    {
        $host = $this->config->findHostByIp($clientIp);

        return $host['description'] ?? null;
    }
}
