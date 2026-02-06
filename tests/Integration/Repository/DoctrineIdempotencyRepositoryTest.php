<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentComponent\Contract\IdempotencyRecord;
use OxidEsales\PaymentComponent\Repository\DoctrineIdempotencyRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DoctrineIdempotencyRepository against real database.
 *
 * Sprint 42: Idempotency implementation.
 *
 * @covers \OxidEsales\PaymentComponent\Repository\DoctrineIdempotencyRepository
 * @group sprint-42
 * @group idempotency
 * @group database
 */
final class DoctrineIdempotencyRepositoryTest extends TestCase
{
    private const TABLE = 'oe_payments_idempotency';

    private Connection $connection;
    private DoctrineIdempotencyRepository $repository;

    /** @var array<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = ContainerFactory::getInstance()->getContainer();
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();
        $this->repository = new DoctrineIdempotencyRepository($this->connection);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $this->connection->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE OXID = :id',
                ['id' => $id]
            );
        }

        parent::tearDown();
    }

    /**
     * @test
     */
    public function saveInsertsRecordAndFindByKeyRetrievesIt(): void
    {
        $record = $this->createRecord('test_save_find_' . uniqid(), 'capture:pi_test1', 'pi_test1', 'capture');
        $this->repository->save($record);

        $found = $this->repository->findByKey('capture:pi_test1');

        $this->assertNotNull($found);
        $this->assertSame($record->getId(), $found->getId());
        $this->assertSame('capture:pi_test1', $found->getKey());
        $this->assertSame('pi_test1', $found->getOrderId());
        $this->assertSame('capture', $found->getOperation());
        $this->assertSame('processing', $found->getStatus());
        $this->assertNull($found->getResult());
    }

    /**
     * @test
     */
    public function saveUpdatesExistingRecord(): void
    {
        $record = $this->createRecord('test_update_' . uniqid(), 'capture:pi_update', 'pi_update', 'capture');
        $this->repository->save($record);

        $record->setStatus('completed');
        $record->setResult('{"successful":true}');
        $this->repository->save($record);

        $found = $this->repository->findByKey('capture:pi_update');

        $this->assertNotNull($found);
        $this->assertSame('completed', $found->getStatus());
        $this->assertSame('{"successful":true}', $found->getResult());
    }

    /**
     * @test
     */
    public function findByKeyReturnsNullForNonExistentKey(): void
    {
        $found = $this->repository->findByKey('nonexistent_key_' . uniqid());

        $this->assertNull($found);
    }

    /**
     * @test
     */
    public function savePreservesAllFieldsOnRoundTrip(): void
    {
        $now = new DateTimeImmutable('2026-02-06 12:00:00');
        $expires = new DateTimeImmutable('2026-02-07 12:00:00');

        $id = 'test_roundtrip_' . uniqid();
        $this->createdIds[] = $id;

        $record = new IdempotencyRecord(
            $id,
            'refund:pi_roundtrip',
            'pi_roundtrip',
            'refund',
            'completed',
            $now,
            $expires
        );
        $record->setResult('{"refundId":"re_123","amountRefunded":25.0}');

        $this->repository->save($record);
        $found = $this->repository->findByKey('refund:pi_roundtrip');

        $this->assertNotNull($found);
        $this->assertSame($id, $found->getId());
        $this->assertSame('refund:pi_roundtrip', $found->getKey());
        $this->assertSame('pi_roundtrip', $found->getOrderId());
        $this->assertSame('refund', $found->getOperation());
        $this->assertSame('completed', $found->getStatus());
        $this->assertSame('{"refundId":"re_123","amountRefunded":25.0}', $found->getResult());
        $this->assertSame('2026-02-06 12:00:00', $found->getCreatedAt()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-07 12:00:00', $found->getExpiresAt()->format('Y-m-d H:i:s'));
    }

    /**
     * @test
     */
    public function saveWithNullResultStoresNull(): void
    {
        $record = $this->createRecord('test_null_result_' . uniqid(), 'capture:pi_null', 'pi_null', 'capture');
        $this->repository->save($record);

        $found = $this->repository->findByKey('capture:pi_null');

        $this->assertNotNull($found);
        $this->assertNull($found->getResult());
    }

    /**
     * @test
     */
    public function deleteExpiredRemovesOnlyExpiredRecords(): void
    {
        $expiredId = 'test_expired_' . uniqid();
        $activeId = 'test_active_' . uniqid();
        $this->createdIds[] = $expiredId;
        $this->createdIds[] = $activeId;

        $expiredRecord = new IdempotencyRecord(
            $expiredId,
            'capture:pi_expired_' . uniqid(),
            'pi_expired',
            'capture',
            'completed',
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 day')
        );
        $this->repository->save($expiredRecord);

        $activeRecord = new IdempotencyRecord(
            $activeId,
            'capture:pi_active_' . uniqid(),
            'pi_active',
            'capture',
            'completed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $this->repository->save($activeRecord);

        $deleted = $this->repository->deleteExpired();

        $this->assertGreaterThanOrEqual(1, $deleted);

        $foundExpired = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE OXID = :id',
            ['id' => $expiredId]
        );
        $this->assertEquals(0, $foundExpired, 'Expired record should be deleted');

        $foundActive = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE OXID = :id',
            ['id' => $activeId]
        );
        $this->assertEquals(1, $foundActive, 'Active record should still exist');
    }

    /**
     * @test
     */
    public function uniqueKeyConstraintPreventsDirectDuplicateInsert(): void
    {
        $key = 'capture:pi_unique_' . uniqid();
        $record1 = $this->createRecord('test_dup1_' . uniqid(), $key, 'pi_unique', 'capture');
        $this->repository->save($record1);

        // Second record with same key but different ID — raw insert should fail on unique constraint
        $this->expectException(\Exception::class);

        $this->connection->insert(self::TABLE, [
            'OXID' => 'test_dup2_' . uniqid(),
            'OXKEY' => $key,
            'OXORDERID' => 'pi_unique',
            'OXOPERATION' => 'capture',
            'OXRESULT' => null,
            'OXSTATUS' => 'processing',
            'OXCREATED' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'OXEXPIRES' => (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @test
     */
    public function hydratedRecordIsExpiredWhenExpiresAtIsInPast(): void
    {
        $id = 'test_expired_check_' . uniqid();
        $this->createdIds[] = $id;

        $record = new IdempotencyRecord(
            $id,
            'capture:pi_exp_check_' . uniqid(),
            'pi_exp_check',
            'capture',
            'completed',
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 second')
        );
        $this->repository->save($record);

        $found = $this->repository->findByKey($record->getKey());

        $this->assertNotNull($found);
        $this->assertTrue($found->isExpired());
    }

    /**
     * @test
     */
    public function hydratedRecordIsNotExpiredWhenExpiresAtIsInFuture(): void
    {
        $record = $this->createRecord(
            'test_not_expired_' . uniqid(),
            'capture:pi_not_exp_' . uniqid(),
            'pi_not_exp',
            'capture'
        );
        $this->repository->save($record);

        $found = $this->repository->findByKey($record->getKey());

        $this->assertNotNull($found);
        $this->assertFalse($found->isExpired());
    }

    private function createRecord(string $id, string $key, string $orderId, string $operation): IdempotencyRecord
    {
        $this->createdIds[] = $id;

        return new IdempotencyRecord(
            $id,
            $key,
            $orderId,
            $operation,
            'processing',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
    }
}
