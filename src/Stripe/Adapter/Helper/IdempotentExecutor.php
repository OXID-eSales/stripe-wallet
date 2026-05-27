<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter\Helper;

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
 * - callable: the actual operation; must return T
 * - serialize: T → string (JSON)
 * - deserialize: string → T
 *
 * @template T
 * @since 2.0.0
 */
final class IdempotentExecutor
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    private const DEFAULT_TTL_SECONDS = 86400;

    public function __construct(
        private readonly IdempotencyRepositoryInterface $repository,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
    }

    /**
     * Execute an operation with idempotency protection.
     *
     * @template T
     * @param string $key Idempotency key
     * @param string $referenceId Reference ID stored on the record
     * @param string $operation Operation name
     * @param callable(): T $callable The operation to execute
     * @param callable(T): string $serialize Serialize result to JSON string
     * @param callable(string): T $deserialize Deserialize JSON string to result
     * @return T
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
            if ($existing->getStatus() === self::STATUS_PROCESSING) {
                throw new RuntimeException(
                    sprintf('Idempotency: %s operation already in progress for: %s', $operation, $referenceId)
                );
            }
        }

        $record = IdempotencyHelper::reuseOrCreate($existing, $key, $referenceId, $operation, $this->ttlSeconds);
        $this->repository->save($record);

        try {
            $result = $callable();
            $record->setStatus(self::STATUS_COMPLETED);
            $record->setResult($serialize($result));
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
