<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Refund;
use Stripe\Service\RefundService;
use Stripe\StripeClient;

/**
 * Unit tests for RefundHelper idempotency logic.
 *
 * Sprint 46: Idempotency moved from IdempotentStripeAdapter into helper.
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper
 * @group sprint-46
 * @group idempotency
 */
final class RefundHelperIdempotencyTest extends TestCase
{
    private StripeClient&MockObject $stripeClient;
    private IdempotencyRepositoryInterface&MockObject $repository;
    private RefundHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $this->helper = new RefundHelper($this->repository);
    }

    // ==========================================
    // refundPayment() idempotency tests
    // ==========================================

    /**
     * @test
     */
    public function refundPaymentCallsStripeWhenNoExistingRecord(): void
    {
        $request = new RefundPaymentRequest('pi_abc123', 25.0);

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('refund:pi_abc123')
            ->willReturn(null);

        $this->repository
            ->expects($this->exactly(2))
            ->method('save');

        $refund = $this->createStripeRefund('re_xyz', 2500, 'eur', 'succeeded');
        $this->mockRefundCreate($refund);

        $result = $this->helper->refundPayment($this->stripeClient, $request);

        $this->assertTrue($result->successful);
        $this->assertSame('re_xyz', $result->refundId);
        $this->assertSame(25.0, $result->amountRefunded);
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

        $result = $this->helper->refundPayment($this->stripeClient, $request);

        $this->assertTrue($result->successful);
        $this->assertSame('re_cached', $result->refundId);
        $this->assertSame(25.0, $result->amountRefunded);
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

        $this->helper->refundPayment($this->stripeClient, $request);
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

        $result = $this->helper->refundPayment($this->stripeClient, $request);

        $this->assertFalse($result->successful);
        $this->assertSame('Refund declined', $result->errorMessage);
    }

    /**
     * @test
     */
    public function refundPaymentWithoutIdempotencyCallsStripeDirectly(): void
    {
        $helperNoIdempotency = new RefundHelper();
        $request = new RefundPaymentRequest('pi_abc123', 25.0);

        $refund = $this->createStripeRefund('re_direct', 2500, 'eur', 'succeeded');
        $this->mockRefundCreate($refund);

        $result = $helperNoIdempotency->refundPayment($this->stripeClient, $request);

        $this->assertTrue($result->successful);
        $this->assertSame('re_direct', $result->refundId);
    }

    // ==========================================
    // createRefundByCharge() idempotency tests
    // ==========================================

    /**
     * @test
     */
    public function createRefundByChargeCallsStripeWhenNoExistingRecord(): void
    {
        $refund = $this->createStripeRefund('re_charge', 5000, 'eur', 'succeeded');

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('refund_charge:ch_abc')
            ->willReturn(null);

        $this->mockRefundCreate($refund);

        $result = $this->helper->createRefundByCharge($this->stripeClient, 'ch_abc', 5000, 'duplicate');

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

        $this->helper->createRefundByCharge($this->stripeClient, 'ch_abc');
    }

    private function createStripeRefund(string $id, int $amount, string $currency, string $status): Refund
    {
        return Refund::constructFrom([
            'id' => $id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'created' => time(),
        ]);
    }

    private function mockRefundCreate(Refund $refund): void
    {
        $refundService = $this->createMock(RefundService::class);
        $refundService->method('create')->willReturn($refund);
        $this->stripeClient->refunds = $refundService;
    }
}
