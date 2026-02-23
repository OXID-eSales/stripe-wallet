<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

/**
 * Rejects webhook payloads exceeding the configured maximum byte size.
 *
 * Defense-in-depth: prevents memory exhaustion attacks before any JSON parsing.
 * This is the cheapest guard (O(1) strlen) so it runs FIRST in the chain.
 *
 * Sprint 64a: Addresses finding M8 (Webhook Payload Size Unlimited).
 *
 * @since 2.1.0
 */
final class WebhookPayloadSizeGuard implements WebhookRequestGuardInterface
{
    public function __construct(private readonly int $maxBytes = 65536)
    {
    }

    public function check(string $payload, string $signature, string $remoteIp): ?WebhookGuardResult
    {
        if (strlen($payload) > $this->maxBytes) {
            return new WebhookGuardResult(
                'payload_too_large',
                413,
                sprintf('Payload exceeds maximum size of %d bytes', $this->maxBytes)
            );
        }

        return null;
    }
}
