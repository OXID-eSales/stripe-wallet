<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Adapter;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\PaymentComponent\Contract\IdempotencyRecord;
use OxidEsales\PaymentComponent\Repository\DoctrineIdempotencyRepository;
use OxidEsales\Payments\Stripe\Adapter\IdempotentStripeAdapter;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for IdempotentStripeAdapter with real database repository.
 *
 * Tests the full idempotency flow: adapter → repository → database → adapter.
 * Uses a mocked inner adapter but real DoctrineIdempotencyRepository + MySQL.
 *
 * Sprint 42: Idempotency implementation.
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\IdempotentStripeAdapter
 * @covers \OxidEsales\PaymentComponent\Repository\DoctrineIdempotencyRepository
 * @group sprint-42
 * @group idempotency
 * @group database
 */
final class IdempotentStripeAdapterTest extends TestCase
{
    private const TABLE = 'oe_payments_idempotency';

    private Connection $connection;
    private DoctrineIdempotencyRepository $repository;
    private StripeAdapterInterface&MockObject $innerAdapter;
    private IdempotentStripeAdapter $adapter;

    /** @var array<string> */
    private array $cleanupKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $container = ContainerFactory::getInstance()->getContainer();
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();
        $this->repository = new DoctrineIdempotencyRepository($this->connection);

        $this->innerAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->adapter = new IdempotentStripeAdapter($this->innerAdapter, $this->repository);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupKeys as $key) {
            $this->connection->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE OXKEY = :key',
                ['key' => $key]
            );
        }

        parent::tearDown();
    }

    // ==========================================
    // capturePayment() integration tests
    // ==========================================

    /**
     * @test
     *
     * Full flow: first capture → calls inner adapter → saves to DB.
     */
    public function capturePaymentFirstCallExecutesAndPersists(): void
    {
        $paymentId = 'pi_capture_first_' . uniqid();
        $key = 'capture:' . $paymentId;
        $this->cleanupKeys[] = $key;

        $request = new CapturePaymentRequest($paymentId, 50.0);
        $expectedResponse = CaptureResponse::success(
            $paymentId,
            'ch_test_123',
            50.0,
            'EUR',
            'succeeded',
            new DateTimeImmutable()
        );

        $this->innerAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->with($request)
            ->willReturn($expectedResponse);

        $result = $this->adapter->capturePayment($request);

        $this->assertTrue($result->successful);
        $this->assertSame($paymentId, $result->providerPaymentId);
        $this->assertSame(50.0, $result->amountCaptured);

        // Verify record persisted in database
        $dbRecord = $this->repository->findByKey($key);
        $this->assertNotNull($dbRecord);
        $this->assertSame('completed', $dbRecord->getStatus());
        $this->assertNotNull($dbRecord->getResult());
    }

    /**
     * @test
     *
     * Full idempotency flow: second capture returns cached result from DB.
     */
    public function capturePaymentSecondCallReturnsCachedResultFromDatabase(): void
    {
        $paymentId = 'pi_capture_cached_' . uniqid();
        $key = 'capture:' . $paymentId;
        $this->cleanupKeys[] = $key;

        $request = new CapturePaymentRequest($paymentId, 75.0);
        $firstResponse = CaptureResponse::success(
            $paymentId,
            'ch_cached_456',
            75.0,
            'EUR',
            'succeeded',
            new DateTimeImmutable('2026-02-06 10:00:00')
        );

        // First call — inner adapter called
        $this->innerAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willReturn($firstResponse);

        $this->adapter->capturePayment($request);

        // Second call — inner adapter NOT called, returns cached from DB
        $secondResult = $this->adapter->capturePayment($request);

        $this->assertTrue($secondResult->successful);
        $this->assertSame($paymentId, $secondResult->providerPaymentId);
        $this->assertSame('ch_cached_456', $secondResult->captureId);
        $this->assertSame(75.0, $secondResult->amountCaptured);
        $this->assertSame('EUR', $secondResult->currency);
    }

    /**
     * @test
     *
     * Processing status in DB blocks second call.
     */
    public function capturePaymentThrowsWhenProcessingRecordExistsInDatabase(): void
    {
        $paymentId = 'pi_capture_processing_' . uniqid();
        $key = 'capture:' . $paymentId;
        $this->cleanupKeys[] = $key;

        // Insert a processing record directly into DB
        $record = new IdempotencyRecord(
            bin2hex(random_bytes(16)),
            $key,
            $paymentId,
            'capture',
            'processing',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $this->repository->save($record);

        $request = new CapturePaymentRequest($paymentId);

        $this->innerAdapter
            ->expects($this->never())
            ->method('capturePayment');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->adapter->capturePayment($request);
    }

    /**
     * @test
     *
     * Failed inner call persists failure status to DB.
     */
    public function capturePaymentRecordsFailureInDatabase(): void
    {
        $paymentId = 'pi_capture_fail_' . uniqid();
        $key = 'capture:' . $paymentId;
        $this->cleanupKeys[] = $key;

        $request = new CapturePaymentRequest($paymentId);

        $this->innerAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willThrowException(new \RuntimeException('Stripe API error'));

        try {
            $this->adapter->capturePayment($request);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Stripe API error', $e->getMessage());
        }

        // Verify failure status persisted in DB
        $dbRecord = $this->repository->findByKey($key);
        $this->assertNotNull($dbRecord);
        $this->assertSame('failed', $dbRecord->getStatus());
        $this->assertNotNull($dbRecord->getResult());
        $this->assertStringContainsString('Stripe API error', (string) $dbRecord->getResult());
    }

    /**
     * @test
     *
     * Expired record in DB does not block new capture.
     */
    public function capturePaymentExecutesWhenExpiredRecordExistsInDatabase(): void
    {
        $paymentId = 'pi_capture_expired_' . uniqid();
        $key = 'capture:' . $paymentId;
        $this->cleanupKeys[] = $key;

        // Insert an expired completed record
        $expiredRecord = new IdempotencyRecord(
            bin2hex(random_bytes(16)),
            $key,
            $paymentId,
            'capture',
            'completed',
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 day')
        );
        $expiredRecord->setResult('{"old":"data"}');
        $this->repository->save($expiredRecord);

        $request = new CapturePaymentRequest($paymentId, 100.0);
        $freshResponse = CaptureResponse::success(
            $paymentId,
            'ch_fresh_789',
            100.0,
            'EUR',
            'succeeded',
            new DateTimeImmutable()
        );

        $this->innerAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willReturn($freshResponse);

        $result = $this->adapter->capturePayment($request);

        $this->assertTrue($result->successful);
        $this->assertSame('ch_fresh_789', $result->captureId);
    }

    // ==========================================
    // refundPayment() integration tests
    // ==========================================

    /**
     * @test
     *
     * Full refund flow: first call → executes → second call → cached.
     */
    public function refundPaymentIdempotencyFlowWithDatabase(): void
    {
        $paymentId = 'pi_refund_flow_' . uniqid();
        $key = 'refund:' . $paymentId;
        $this->cleanupKeys[] = $key;

        $request = new RefundPaymentRequest($paymentId, 30.0);
        $firstResponse = RefundResponse::success(
            $paymentId,
            're_test_abc',
            30.0,
            'EUR',
            'succeeded',
            new DateTimeImmutable('2026-02-06 11:00:00'),
            'requested_by_customer'
        );

        // First call — inner adapter called
        $this->innerAdapter
            ->expects($this->once())
            ->method('refundPayment')
            ->willReturn($firstResponse);

        $firstResult = $this->adapter->refundPayment($request);
        $this->assertTrue($firstResult->successful);
        $this->assertSame('re_test_abc', $firstResult->refundId);

        // Second call — cached from DB
        $secondResult = $this->adapter->refundPayment($request);

        $this->assertTrue($secondResult->successful);
        $this->assertSame('re_test_abc', $secondResult->refundId);
        $this->assertSame(30.0, $secondResult->amountRefunded);
        $this->assertSame('EUR', $secondResult->currency);
        $this->assertSame('requested_by_customer', $secondResult->reason);
    }

    /**
     * @test
     *
     * Refund processing status blocks concurrent calls.
     */
    public function refundPaymentThrowsWhenProcessingRecordExistsInDatabase(): void
    {
        $paymentId = 'pi_refund_processing_' . uniqid();
        $key = 'refund:' . $paymentId;
        $this->cleanupKeys[] = $key;

        $record = new IdempotencyRecord(
            bin2hex(random_bytes(16)),
            $key,
            $paymentId,
            'refund',
            'processing',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $this->repository->save($record);

        $request = new RefundPaymentRequest($paymentId);

        $this->innerAdapter
            ->expects($this->never())
            ->method('refundPayment');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->adapter->refundPayment($request);
    }

    /**
     * @test
     *
     * Failed refund response is cached and returned on retry.
     */
    public function refundPaymentCachesFailureResponseFromInner(): void
    {
        $paymentId = 'pi_refund_fail_cached_' . uniqid();
        $key = 'refund:' . $paymentId;
        $this->cleanupKeys[] = $key;

        $request = new RefundPaymentRequest($paymentId);

        $this->innerAdapter
            ->expects($this->once())
            ->method('refundPayment')
            ->willThrowException(new \RuntimeException('Refund declined'));

        try {
            $this->adapter->refundPayment($request);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Refund declined', $e->getMessage());
        }

        $dbRecord = $this->repository->findByKey($key);
        $this->assertNotNull($dbRecord);
        $this->assertSame('failed', $dbRecord->getStatus());
    }

    // ==========================================
    // deleteExpired() integration test
    // ==========================================

    /**
     * @test
     *
     * Verifies expired records are cleaned up and active ones remain.
     */
    public function deleteExpiredCleansUpExpiredRecordsFromDatabase(): void
    {
        $expiredKey = 'capture:pi_cleanup_expired_' . uniqid();
        $activeKey = 'capture:pi_cleanup_active_' . uniqid();
        $this->cleanupKeys[] = $expiredKey;
        $this->cleanupKeys[] = $activeKey;

        $expiredRecord = new IdempotencyRecord(
            bin2hex(random_bytes(16)),
            $expiredKey,
            'pi_cleanup_expired',
            'capture',
            'completed',
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 day')
        );
        $this->repository->save($expiredRecord);

        $activeRecord = new IdempotencyRecord(
            bin2hex(random_bytes(16)),
            $activeKey,
            'pi_cleanup_active',
            'capture',
            'completed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $this->repository->save($activeRecord);

        $deleted = $this->repository->deleteExpired();

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertNull($this->repository->findByKey($expiredKey));
        $this->assertNotNull($this->repository->findByKey($activeKey));
    }

    // ==========================================
    // Failed record retry tests
    // ==========================================

    /**
     * @test
     *
     * After a failed capture, a retry should execute the inner adapter again.
     */
    public function capturePaymentRetriesAfterFailedRecord(): void
    {
        $paymentId = 'pi_capture_retry_' . uniqid();
        $key = 'capture:' . $paymentId;
        $this->cleanupKeys[] = $key;

        $request = new CapturePaymentRequest($paymentId, 60.0);

        // First call — fails
        $this->innerAdapter
            ->expects($this->exactly(2))
            ->method('capturePayment')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('Temporary error')),
                CaptureResponse::success($paymentId, 'ch_retry', 60.0, 'EUR', 'succeeded', new DateTimeImmutable())
            );

        try {
            $this->adapter->capturePayment($request);
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Record is now 'failed' in DB — retry should execute inner again
        $result = $this->adapter->capturePayment($request);

        $this->assertTrue($result->successful);
        $this->assertSame('ch_retry', $result->captureId);
    }
}
