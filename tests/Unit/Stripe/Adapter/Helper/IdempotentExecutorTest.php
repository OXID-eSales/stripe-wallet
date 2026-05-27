<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\IdempotentExecutor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for IdempotentExecutor.
 *
 * Sprint 114.8: Extracted generic idempotency wrapper from PaymentIntentHelper and RefundHelper.
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\Helper\IdempotentExecutor
 * @group sprint-114-8
 * @group idempotency
 */
final class IdempotentExecutorTest extends TestCase
{
    private IdempotencyRepositoryInterface&MockObject $repository;
    private IdempotentExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $this->executor = new IdempotentExecutor($this->repository);
    }

    /**
     * @test
     * First call: no existing record → executes operation and stores COMPLETED result.
     */
    public function executeCallsOperationAndStoresCompletedWhenNoRecord(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('op:key1')
            ->willReturn(null);

        $this->repository
            ->expects($this->exactly(2))
            ->method('save');

        $called = false;
        $result = $this->executor->execute(
            key: 'op:key1',
            referenceId: 'key1',
            operation: 'op',
            callable: static function () use (&$called): string {
                $called = true;
                return 'result-value';
            },
            serialize: static fn (string $r): string => json_encode(['v' => $r]) ?: '',
            deserialize: static fn (string $j): string => json_decode($j, true)['v']
        );

        $this->assertTrue($called);
        $this->assertSame('result-value', $result);
    }

    /**
     * @test
     * PROCESSING status → throws "already in progress".
     */
    public function executeThrowsWhenExistingRecordIsProcessing(): void
    {
        $record = new IdempotencyRecord(
            'id_processing',
            'op:key1',
            'key1',
            'op',
            IdempotentExecutor::STATUS_PROCESSING,
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('op:key1')
            ->willReturn($record);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->executor->execute(
            key: 'op:key1',
            referenceId: 'key1',
            operation: 'op',
            callable: static fn (): string => 'never',
            serialize: static fn (string $r): string => $r,
            deserialize: static fn (string $j): string => $j
        );
    }

    /**
     * @test
     * COMPLETED status with cached result → returns deserialized result WITHOUT re-executing.
     */
    public function executeReturnsCachedResultWithoutCallingOperationWhenCompleted(): void
    {
        $record = new IdempotencyRecord(
            'id_completed',
            'op:key1',
            'key1',
            'op',
            IdempotentExecutor::STATUS_COMPLETED,
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $record->setResult(json_encode(['v' => 'cached-value']));

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('op:key1')
            ->willReturn($record);

        $this->repository
            ->expects($this->never())
            ->method('save');

        $operationCalled = false;
        $result = $this->executor->execute(
            key: 'op:key1',
            referenceId: 'key1',
            operation: 'op',
            callable: static function () use (&$operationCalled): string {
                $operationCalled = true;
                return 'should-not-return';
            },
            serialize: static fn (string $r): string => json_encode(['v' => $r]) ?: '',
            deserialize: static fn (string $j): string => json_decode($j, true)['v']
        );

        $this->assertFalse($operationCalled);
        $this->assertSame('cached-value', $result);
    }

    /**
     * @test
     * Operation throws → status set to FAILED, exception re-thrown.
     */
    public function executeStoresFailedStatusAndRethrowsOnException(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn(null);

        $savedStatuses = [];
        $this->repository
            ->expects($this->exactly(2))
            ->method('save')
            ->with($this->callback(function (IdempotencyRecord $r) use (&$savedStatuses): bool {
                $savedStatuses[] = $r->getStatus();
                return true;
            }));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operation-failed');

        $this->executor->execute(
            key: 'op:key1',
            referenceId: 'key1',
            operation: 'op',
            callable: static function (): never {
                throw new RuntimeException('operation-failed');
            },
            serialize: static fn (string $r): string => $r,
            deserialize: static fn (string $j): string => $j
        );

        $this->assertSame([IdempotentExecutor::STATUS_PROCESSING, IdempotentExecutor::STATUS_FAILED], $savedStatuses);
    }

    /**
     * @test
     * Expired COMPLETED record → operation re-executed (not returned from cache).
     */
    public function executeCallsOperationWhenExistingRecordIsExpired(): void
    {
        $expiredRecord = new IdempotencyRecord(
            'id_expired',
            'op:key1',
            'key1',
            'op',
            IdempotentExecutor::STATUS_COMPLETED,
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 day')
        );
        $expiredRecord->setResult(json_encode(['v' => 'old-value']));

        $this->repository
            ->method('findByKey')
            ->willReturn($expiredRecord);

        $this->repository
            ->expects($this->exactly(2))
            ->method('save');

        $result = $this->executor->execute(
            key: 'op:key1',
            referenceId: 'key1',
            operation: 'op',
            callable: static fn (): string => 'fresh-value',
            serialize: static fn (string $r): string => json_encode(['v' => $r]) ?: '',
            deserialize: static fn (string $j): string => json_decode($j, true)['v']
        );

        $this->assertSame('fresh-value', $result);
    }
}
