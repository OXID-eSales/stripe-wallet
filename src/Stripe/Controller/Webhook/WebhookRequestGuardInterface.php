<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

/**
 * Guard interface for webhook request validation.
 *
 * Returns null if the request is allowed, or a WebhookGuardResult if rejected.
 * Guards are composed via WebhookGuardChain (Chain of Responsibility pattern).
 *
 * Sprint 64a: Interface Segregation — single method, single responsibility.
 *
 * @since 2.1.0
 */
interface WebhookRequestGuardInterface
{
    public function check(string $payload, string $signature, string $remoteIp): ?WebhookGuardResult;
}
