<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

/**
 * Defense-in-depth: reject requests from non-Stripe IPs before signature verification.
 *
 * Stripe publishes webhook IP ranges. Empty allowlist = disabled (all IPs allowed).
 * When enabled, blocks non-Stripe IPs before HMAC computation to save CPU.
 *
 * Sprint 64c: Addresses finding H9 (Webhook IP not validated).
 *
 * @since 2.1.0
 */
class WebhookIpAllowlistGuard implements WebhookRequestGuardInterface
{
    /**
     * @param string[] $allowedCidrs CIDR ranges (e.g., ['54.187.174.169/32'])
     * @param bool $allowLoopback Allow 127.0.0.1 and ::1 (for development)
     */
    public function __construct(
        private readonly array $allowedCidrs,
        private readonly bool $allowLoopback = false,
    ) {
    }

    public function check(string $payload, string $signature, string $remoteIp): ?WebhookGuardResult
    {
        if ($this->allowedCidrs === []) {
            return null;
        }

        if ($this->allowLoopback && $this->isLoopback($remoteIp)) {
            return null;
        }

        foreach ($this->allowedCidrs as $cidr) {
            if ($this->ipInCidr($remoteIp, $cidr)) {
                return null;
            }
        }

        return new WebhookGuardResult('ip_not_allowed', 403, 'Forbidden');
    }

    private function isLoopback(string $ip): bool
    {
        return in_array($ip, ['127.0.0.1', '::1'], true);
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
