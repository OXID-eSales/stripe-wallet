<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

/**
 * Webhook guard that rejects non-HTTPS requests.
 *
 * Sprint 67b (M6): Enforces TLS transport for webhook endpoints.
 * Supports X-Forwarded-Proto header for reverse proxy setups.
 * Optional loopback exemption for development environments.
 *
 * @since 2.1.0
 */
class WebhookHttpsGuard implements WebhookRequestGuardInterface
{
    public function __construct(
        private readonly bool $allowInsecureLoopback = false,
    ) {
    }

    public function check(string $payload, string $signature, string $remoteIp): ?WebhookGuardResult
    {
        if ($this->isSecureConnection()) {
            return null;
        }

        if ($this->allowInsecureLoopback && $this->isLoopback($remoteIp)) {
            return null;
        }

        return new WebhookGuardResult('insecure_connection', 400, 'HTTPS required for webhook endpoints');
    }

    private function isSecureConnection(): bool
    {
        if (($this->getServerVar('HTTPS') ?? '') === 'on') {
            return true;
        }

        $forwardedProto = $this->getServerVar('HTTP_X_FORWARDED_PROTO');
        if ($forwardedProto === 'https') {
            return true;
        }

        return false;
    }

    private function isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1';
    }

    protected function getServerVar(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : null;
    }
}
