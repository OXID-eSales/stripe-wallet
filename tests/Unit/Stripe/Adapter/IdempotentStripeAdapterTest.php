<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentComponent\Adapter\Response\RefundResponse;
use OxidEsales\PaymentComponent\Contract\IdempotencyRecord;
use OxidEsales\PaymentComponent\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\IdempotentStripeAdapter;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Refund;

/**
 * Unit tests for IdempotentStripeAdapter decorator.
 *
 * Sprint 42: Idempotency implementation.
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\IdempotentStripeAdapter
 * @group sprint-42
 * @group idempotency
 */
final class IdempotentStripeAdapterTest extends TestCase
{
    private StripeAdapterInterface&MockObject $innerAdapter;
    private IdempotencyRepositoryInterface&MockObject $repository;
    private IdempotentStripeAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->innerAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $this->adapter = new IdempotentStripeAdapter($this->innerAdapter, $this->repository);
    }

    // ==========================================
    // capturePayment() tests
    // ==========================================

    /**
     * @test
     */
    public function capturePaymentCallsInnerWhenNoExistingRecord(): void
    {
        $request = new CapturePaymentRequest('pi_abc123', 50.0);
        $expectedResponse = CaptureResponse::success(
            'pi_abc123',
            'ch_xyz',
            50.0,
            'EUR',
            'succeeded',
            new DateTimeImmutable()
        );

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('capture:pi_abc123')
            ->willReturn(null);

        $this->repository
            ->expects($this->exactly(2))
            ->method('save');

        $this->innerAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->with($request)
            ->willReturn($expectedResponse);

        $result = $this->adapter->capturePayment($request);

        $this->assertTrue($result->successful);
        $this->assertSame('pi_abc123', $result->providerPaymentId);
        $this->assertSame(50.0, $result->amountCaptured);
    }

    /**
     * @test
     */
    public function capturePaymentReturnsCachedResultWhenCompleted(): void
    {
        $request = new CapturePaymentRequest('pi_abc123', 50.0);

        $cachedResult = json_encode([
            'successful' => true,
            'providerPaymentId' => 'pi_abc123',
            'captureId' => 'ch_cached',
            'amountCaptured' => 50.0,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'capturedAt' => '2026-02-06 10:00:00',
            'errorMessage' => null,
            'errorCode' => null,
        ]);

        $existingRecord = new IdempotencyRecord(
            'id_existing',
            'capture:pi_abc123',
            'pi_abc123',
            'capture',
            'completed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $existingRecord->setResult($cachedResult);

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('capture:pi_abc123')
            ->willReturn($existingRecord);

        $this->innerAdapter
            ->expects($this->never())
            ->method('capturePayment');

        $result = $this->adapter->capturePayment($request);

        $this->assertTrue($result->successful);
        $this->assertSame('pi_abc123', $result->providerPaymentId);
        $this->assertSame('ch_cached', $result->captureId);
        $this->assertSame(50.0, $result->amountCaptured);
    }

    /**
     * @test
     */
    public function capturePaymentThrowsWhenProcessing(): void
    {
        $request = new CapturePaymentRequest('pi_abc123');

        $existingRecord = new IdempotencyRecord(
            'id_processing',
            'capture:pi_abc123',
            'pi_abc123',
            'capture',
            'processing',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn($existingRecord);

        $this->innerAdapter
            ->expects($this->never())
            ->method('capturePayment');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->adapter->capturePayment($request);
    }

    /**
     * @test
     */
    public function capturePaymentCallsInnerWhenExpiredRecord(): void
    {
        $request = new CapturePaymentRequest('pi_abc123', 50.0);
        $expectedResponse = CaptureResponse::success(
            'pi_abc123',
            'ch_new',
            50.0,
            'EUR',
            'succeeded',
            new DateTimeImmutable()
        );

        $expiredRecord = new IdempotencyRecord(
            'id_expired',
            'capture:pi_abc123',
            'pi_abc123',
            'capture',
            'completed',
            new DateTimeImmutable('-2 days'),
            new DateTimeImmutable('-1 day')
        );
        $expiredRecord->setResult('{"old":"data"}');

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn($expiredRecord);

        $this->innerAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willReturn($expectedResponse);

        $result = $this->adapter->capturePayment($request);

        $this->assertTrue($result->successful);
        $this->assertSame('ch_new', $result->captureId);
    }

    /**
     * @test
     */
    public function capturePaymentRecordsFailureOnException(): void
    {
        $request = new CapturePaymentRequest('pi_abc123');

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn(null);

        $this->repository
            ->expects($this->exactly(2))
            ->method('save');

        $this->innerAdapter
            ->expects($this->once())
            ->method('capturePayment')
            ->willThrowException(new \RuntimeException('Stripe API error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stripe API error');

        $this->adapter->capturePayment($request);
    }

    // ==========================================
    // refundPayment() tests
    // ==========================================

    /**
     * @test
     */
    public function refundPaymentCallsInnerWhenNoExistingRecord(): void
    {
        $request = new RefundPaymentRequest('pi_abc123', 25.0);
        $expectedResponse = RefundResponse::success(
            'pi_abc123',
            're_xyz',
            25.0,
            'EUR',
            'succeeded',
            new DateTimeImmutable()
        );

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('refund:pi_abc123')
            ->willReturn(null);

        $this->innerAdapter
            ->expects($this->once())
            ->method('refundPayment')
            ->willReturn($expectedResponse);

        $result = $this->adapter->refundPayment($request);

        $this->assertTrue($result->successful);
        $this->assertSame('re_xyz', $result->refundId);
    }

    /**
     * @test
     */
    public function refundPaymentReturnsCachedResultWhenCompleted(): void
    {
        $request = new RefundPaymentRequest('pi_abc123', 25.0);

        $cachedResult = json_encode([
            'successful' => true,
            'providerPaymentId' => 'pi_abc123',
            'refundId' => 're_cached',
            'amountRefunded' => 25.0,
            'currency' => 'EUR',
            'status' => 'succeeded',
            'refundedAt' => '2026-02-06 10:00:00',
            'reason' => null,
            'errorMessage' => null,
            'errorCode' => null,
        ]);

        $existingRecord = new IdempotencyRecord(
            'id_existing',
            'refund:pi_abc123',
            'pi_abc123',
            'refund',
            'completed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $existingRecord->setResult($cachedResult);

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn($existingRecord);

        $this->innerAdapter
            ->expects($this->never())
            ->method('refundPayment');

        $result = $this->adapter->refundPayment($request);

        $this->assertTrue($result->successful);
        $this->assertSame('re_cached', $result->refundId);
    }

    /**
     * @test
     */
    public function refundPaymentThrowsWhenProcessing(): void
    {
        $request = new RefundPaymentRequest('pi_abc123');

        $existingRecord = new IdempotencyRecord(
            'id_processing',
            'refund:pi_abc123',
            'pi_abc123',
            'refund',
            'processing',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn($existingRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->adapter->refundPayment($request);
    }

    // ==========================================
    // createRefundByCharge() tests
    // ==========================================

    /**
     * @test
     */
    public function createRefundByChargeCallsInnerWhenNoExistingRecord(): void
    {
        $refund = $this->createMock(Refund::class);

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('refund_charge:ch_abc')
            ->willReturn(null);

        $this->innerAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with('ch_abc', 5000, 'duplicate', null)
            ->willReturn($refund);

        $result = $this->adapter->createRefundByCharge('ch_abc', 5000, 'duplicate');

        $this->assertSame($refund, $result);
    }

    /**
     * @test
     */
    public function createRefundByChargeThrowsWhenProcessing(): void
    {
        $existingRecord = new IdempotencyRecord(
            'id_processing',
            'refund_charge:ch_abc',
            'ch_abc',
            'refund_charge',
            'processing',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn($existingRecord);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->adapter->createRefundByCharge('ch_abc');
    }

    // ==========================================
    // Delegation tests
    // ==========================================

    /**
     * @test
     */
    public function delegatesGetProviderNameToInner(): void
    {
        $this->innerAdapter
            ->expects($this->once())
            ->method('getProviderName')
            ->willReturn('stripe');

        $this->assertSame('stripe', $this->adapter->getProviderName());
    }

    /**
     * @test
     */
    public function delegatesTestConnectionToInner(): void
    {
        $this->innerAdapter
            ->expects($this->once())
            ->method('testConnection')
            ->willReturn(true);

        $this->assertTrue($this->adapter->testConnection());
    }

    /**
     * @test
     */
    public function delegatesSupportsFeatureToInner(): void
    {
        $this->innerAdapter
            ->expects($this->once())
            ->method('supportsFeature')
            ->with('partial_refund')
            ->willReturn(true);

        $this->assertTrue($this->adapter->supportsFeature('partial_refund'));
    }

    /**
     * @test
     */
    public function implementsStripeAdapterInterface(): void
    {
        $this->assertInstanceOf(StripeAdapterInterface::class, $this->adapter);
    }

    // ==========================================
    // Failure response deserialization tests
    // ==========================================

    /**
     * @test
     */
    public function capturePaymentDeserializesFailedCachedResponse(): void
    {
        $request = new CapturePaymentRequest('pi_abc123');

        $cachedResult = json_encode([
            'successful' => false,
            'errorMessage' => 'Insufficient funds',
            'errorCode' => 'card_declined',
        ]);

        $existingRecord = new IdempotencyRecord(
            'id_failed_cache',
            'capture:pi_abc123',
            'pi_abc123',
            'capture',
            'completed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $existingRecord->setResult($cachedResult);

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn($existingRecord);

        $result = $this->adapter->capturePayment($request);

        $this->assertFalse($result->successful);
        $this->assertSame('Insufficient funds', $result->errorMessage);
        $this->assertSame('card_declined', $result->errorCode);
    }

    /**
     * @test
     */
    public function refundPaymentDeserializesFailedCachedResponse(): void
    {
        $request = new RefundPaymentRequest('pi_abc123');

        $cachedResult = json_encode([
            'successful' => false,
            'errorMessage' => 'Refund declined',
            'errorCode' => 'refund_error',
        ]);

        $existingRecord = new IdempotencyRecord(
            'id_failed_cache',
            'refund:pi_abc123',
            'pi_abc123',
            'refund',
            'completed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $existingRecord->setResult($cachedResult);

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn($existingRecord);

        $result = $this->adapter->refundPayment($request);

        $this->assertFalse($result->successful);
        $this->assertSame('Refund declined', $result->errorMessage);
    }
}
