<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Webhook\WebhookResult;

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
     * Log what became of a delivery: one WebhookDeliveryLogRecord line with its ids, its action and the reason
     * it was skipped or failed.
     *
     * @param string $payload Raw webhook payload (only its ids are read)
     * @param int $httpCode HTTP response code
     * @param int $durationMs time since the request started
     * @param ?string $contractId the contract the delivery touched, when it touched one
     */
    public function logResult(
        string $payload,
        WebhookResult $result,
        int $httpCode,
        int $durationMs,
        ?string $contractId
    ): void;
}
