<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

/**
 * Chain of Responsibility: runs guards in order, short-circuits on first rejection.
 *
 * Sprint 64a: Open/Closed — new guards added without modifying existing code.
 * Guard ordering: cheapest check first (payload size → rate limit → IP allowlist).
 *
 * @since 2.1.0
 */
class WebhookGuardChain implements WebhookRequestGuardInterface
{
    /** @param WebhookRequestGuardInterface[] $guards */
    public function __construct(private readonly array $guards)
    {
    }

    public function check(string $payload, string $signature, string $remoteIp): ?WebhookGuardResult
    {
        foreach ($this->guards as $guard) {
            $result = $guard->check($payload, $signature, $remoteIp);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }
}
