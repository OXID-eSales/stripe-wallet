<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentComponent\Contract\IdempotencyRecord;
use OxidEsales\PaymentComponent\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for PaymentIntentHelper idempotency logic.
 *
 * Sprint 46: Idempotency moved from IdempotentStripeAdapter into helper.
 *
 */
#[CoversClass(\OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper::class)]
    #[Group('sprint-46')]
    #[Group('idempotency')]
final class PaymentIntentHelperIdempotencyTest extends TestCase
{
    private StripeClient&MockObject $stripeClient;
    private IdempotencyRepositoryInterface&MockObject $repository;
    private PaymentIntentHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->repository = $this->createMock(IdempotencyRepositoryInterface::class);
        $this->helper = new PaymentIntentHelper($this->repository);
    }

        public function testCaptureCallsStripeWhenNoExistingRecord(): void
    {
        $request = new CapturePaymentRequest('pi_abc123', 50.0);

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with('capture:pi_abc123')
            ->willReturn(null);

        $this->repository
            ->expects($this->exactly(2))
            ->method('save');

        $paymentIntent = $this->createCapturedPaymentIntent('pi_abc123', 5000, 'eur', 'ch_xyz');
        $this->mockCaptureAndRetrieve($paymentIntent);

        $result = $this->helper->capturePaymentIntent($this->stripeClient, $request);

        $this->assertTrue($result->successful);
        $this->assertSame('pi_abc123', $result->providerPaymentId);
        $this->assertSame(50.0, $result->amountCaptured);
    }

        public function testCaptureReturnsCachedResultWhenCompleted(): void
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

        $result = $this->helper->capturePaymentIntent($this->stripeClient, $request);

        $this->assertTrue($result->successful);
        $this->assertSame('pi_abc123', $result->providerPaymentId);
        $this->assertSame('ch_cached', $result->captureId);
        $this->assertSame(50.0, $result->amountCaptured);
    }

        public function testCaptureThrowsWhenProcessing(): void
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->helper->capturePaymentIntent($this->stripeClient, $request);
    }

        public function testCaptureCallsStripeWhenExpiredRecord(): void
    {
        $request = new CapturePaymentRequest('pi_abc123', 50.0);

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

        $paymentIntent = $this->createCapturedPaymentIntent('pi_abc123', 5000, 'eur', 'ch_new');
        $this->mockCaptureAndRetrieve($paymentIntent);

        $result = $this->helper->capturePaymentIntent($this->stripeClient, $request);

        $this->assertTrue($result->successful);
        $this->assertSame('ch_new', $result->captureId);
    }

        public function testCaptureRecordsFailureOnException(): void
    {
        $request = new CapturePaymentRequest('pi_abc123');

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->willReturn(null);

        $this->repository
            ->expects($this->exactly(2))
            ->method('save');

        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('capture')
            ->willThrowException(\Stripe\Exception\InvalidRequestException::factory('Stripe API error'));
        $this->stripeClient->paymentIntents = $piService;

        $this->expectException(\Exception::class);

        $this->helper->capturePaymentIntent($this->stripeClient, $request);
    }

        public function testCaptureDeserializesFailedCachedResponse(): void
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

        $result = $this->helper->capturePaymentIntent($this->stripeClient, $request);

        $this->assertFalse($result->successful);
        $this->assertSame('Insufficient funds', $result->errorMessage);
        $this->assertSame('card_declined', $result->errorCode);
    }

        public function testCaptureWithoutIdempotencyCallsStripeDirectly(): void
    {
        $helperNoIdempotency = new PaymentIntentHelper();
        $request = new CapturePaymentRequest('pi_abc123', 50.0);

        $paymentIntent = $this->createCapturedPaymentIntent('pi_abc123', 5000, 'eur', 'ch_direct');
        $this->mockCaptureAndRetrieve($paymentIntent);

        $result = $helperNoIdempotency->capturePaymentIntent($this->stripeClient, $request);

        $this->assertTrue($result->successful);
        $this->assertSame('ch_direct', $result->captureId);
    }

    private function createCapturedPaymentIntent(string $id, int $amountReceived, string $currency, string $chargeId): PaymentIntent
    {
        $charge = Charge::constructFrom([
            'id' => $chargeId,
            'created' => time(),
        ]);

        return PaymentIntent::constructFrom([
            'id' => $id,
            'amount_received' => $amountReceived,
            'currency' => $currency,
            'status' => 'succeeded',
            'latest_charge' => $charge,
        ]);
    }

    private function mockCaptureAndRetrieve(PaymentIntent $paymentIntent): void
    {
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('capture')->willReturn($paymentIntent);
        $piService->method('retrieve')->willReturn($paymentIntent);
        $this->stripeClient->paymentIntents = $piService;
    }
}
