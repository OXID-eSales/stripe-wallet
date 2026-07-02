<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\Webhook;

/**
 * Immutable value object representing a webhook guard rejection.
 *
 * Sprint 64a: Part of the Chain of Responsibility guard pattern for webhook security.
 *
 * @since 2.1.0
 */
readonly class WebhookGuardResult
{
    public function __construct(
        public string $reason,
        public int $httpStatusCode,
        public string $message,
    ) {
    }
}
