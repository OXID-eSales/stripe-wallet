<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Interface for webhook logging service.
 *
 * Sprint 16: Centralized webhook logging.
 *
 * @since 2.0.0
 */
interface WebhookLogServiceInterface
{
    /**
     * Log received webhook request.
     *
     * @param string $payload Raw webhook payload
     * @param string $signature Stripe signature header
     * @param string $remoteIp Remote IP address
     */
    public function logReceived(string $payload, string $signature, string $remoteIp): void;

    /**
     * Log webhook processing result.
     *
     * @param string $payload Raw webhook payload
     * @param string $result Result description (e.g., "SUCCESS: payment_intent.succeeded")
     * @param int $httpCode HTTP response code
     */
    public function logResult(string $payload, string $result, int $httpCode): void;
}
