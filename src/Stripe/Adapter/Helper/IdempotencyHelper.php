<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;

/**
 * Shared idempotency record management.
 *
 * Sprint 46: Extracted from IdempotentStripeAdapter.
 *
 * @since 2.0.0
 */
final class IdempotencyHelper
{
    public static function reuseOrCreate(
        ?IdempotencyRecord $existing,
        string $key,
        string $orderId,
        string $operation,
        int $ttlSeconds
    ): IdempotencyRecord {
        if ($existing !== null) {
            $existing->setStatus('processing');
            $existing->setResult(null);
            return $existing;
        }

        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+' . $ttlSeconds . ' seconds');

        /** @phpstan-ignore-next-line */
        return new IdempotencyRecord(
            bin2hex(random_bytes(16)),
            $key,
            $orderId,
            $operation,
            'processing',
            $now,
            $expiresAt
        );
    }
}
