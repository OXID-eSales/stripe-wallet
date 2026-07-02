<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

use OxidEsales\PaymentBase\Mcp\Http\RateLimiterInterface;

/**
 * Rejects webhook requests from IPs exceeding the rate limit.
 *
 * Uses RateLimiterInterface (DIP) — concrete implementation is ApcuRateLimiter
 * injected via services.yaml. Atomic apcu_inc() — no DB, no file I/O.
 *
 * Sprint 64b: Addresses finding M7 (No Rate Limiting on Webhook).
 *
 * @since 2.1.0
 */
class WebhookRateLimitGuard implements WebhookRequestGuardInterface
{
    public function __construct(private readonly RateLimiterInterface $rateLimiter)
    {
    }

    public function check(string $payload, string $signature, string $remoteIp): ?WebhookGuardResult
    {
        if (!$this->rateLimiter->isAllowed($remoteIp)) {
            return new WebhookGuardResult('rate_limited', 429, 'Too many requests');
        }

        return null;
    }
}
