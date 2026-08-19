<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use RuntimeException;

/**
 * Generic idempotency executor.
 *
 * Sprint 114.8: Extracted from PaymentIntentHelper::captureWithIdempotency and
 * RefundHelper::refundWithIdempotency (D5) to eliminate the duplicated
 * PROCESSING/COMPLETED/FAILED status flow.
 *
 * Usage:
 *   $executor->execute(key, referenceId, operation, callable, serialize, deserialize)
 *
 * - key: idempotency key (e.g. 'capture:pi_xxx')
 * - referenceId: order/payment id stored in the record
 * - operation: operation name string (e.g. 'capture')
 * - callable: the actual operation; returns the result
 * - serialize: result → JSON string
 * - deserialize: JSON string → result
 *
 * @since 2.0.0
 */
class IdempotentExecutor
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    private const DEFAULT_TTL_SECONDS = 86400;

    /**
     * How long a PROCESSING record may block a retry.
     *
     * Sprint 133 · Story 3 (F8): a lock is not a cache. The result TTL is 24h,
     * but if the process died mid-operation the record stays PROCESSING, and
     * before this split every retry threw "already in progress" for a full day
     * while nothing was running.
     */
    private const DEFAULT_LOCK_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly IdempotencyRepositoryInterface $repository,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
        private readonly int $lockTimeoutSeconds = self::DEFAULT_LOCK_TIMEOUT_SECONDS
    ) {
    }

    /**
     * A PROCESSING record whose lock has outlived the timeout belongs to a
     * process that died; the retry reclaims it instead of being locked out
     * until the result TTL expires.
     */
    private function isAbandoned(IdempotencyRecord $record): bool
    {
        $lockExpiresAt = $record->getCreatedAt()->modify('+' . $this->lockTimeoutSeconds . ' seconds');

        return $lockExpiresAt < new \DateTimeImmutable();
    }

    /**
     * Execute an operation with idempotency protection.
     *
     * @param callable $callable The operation to execute; returns the result value
     * @param callable $serialize Convert the result to a JSON string for storage
     * @param callable $deserialize Convert the stored JSON string back to the result
     * @return mixed The result (same type as what $callable returns)
     */
    public function execute(
        string $key,
        string $referenceId,
        string $operation,
        callable $callable,
        callable $serialize,
        callable $deserialize
    ): mixed {
        $existing = $this->repository->findByKey($key);

        if ($existing !== null && !$existing->isExpired()) {
            if ($existing->getStatus() === self::STATUS_COMPLETED && $existing->getResult() !== null) {
                return $deserialize($existing->getResult());
            }
            if ($existing->getStatus() === self::STATUS_PROCESSING && !$this->isAbandoned($existing)) {
                throw new RuntimeException(
                    sprintf('Idempotency: %s operation already in progress for: %s', $operation, $referenceId)
                );
            }
        }

        $record = IdempotencyHelper::reuseOrCreate($existing, $key, $referenceId, $operation, $this->ttlSeconds);
        $this->repository->save($record);

        try {
            $result = $callable();
            $serialized = $serialize($result);
            $record->setStatus(self::STATUS_COMPLETED);
            $record->setResult(is_string($serialized) ? $serialized : null);
            $this->repository->save($record);
            return $result;
        } catch (\Throwable $e) {
            $record->setStatus(self::STATUS_FAILED);
            $record->setResult(json_encode(['error' => $e->getMessage()]) ?: null);
            $this->repository->save($record);
            throw $e;
        }
    }
}
