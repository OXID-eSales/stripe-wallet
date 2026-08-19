<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;

/**
 * Unit tests for PaymentIntentHelper idempotency logic.
 *
 * Sprint 46: Idempotency moved from IdempotentStripeAdapter into helper.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-46')]
#[\PHPUnit\Framework\Attributes\Group('idempotency')]
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function captureCallsStripeWhenNoExistingRecord(): void
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function captureReturnsCachedResultWhenCompleted(): void
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function captureThrowsWhenProcessing(): void
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function captureCallsStripeWhenExpiredRecord(): void
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function captureRecordsFailureOnException(): void
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function captureDeserializesFailedCachedResponse(): void
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

    /**
     * Sprint 133 · Story 3 (F8) — replaces captureWithoutIdempotencyCallsStripeDirectly,
     * which asserted that the helper silently drops ALL duplicate-charge protection
     * when constructed without a repository. That mode was invisible at the call
     * site and in the logs, so it is gone: the collaborator is now required.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function cannotBeConstructedWithoutAnIdempotencyRepository(): void
    {
        $this->expectException(\ArgumentCountError::class);

        /** @phpstan-ignore-next-line intentionally wrong: proves the dependency is required */
        new PaymentIntentHelper();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function capturePassesNativeIdempotencyKeyToStripe(): void
    {
        $request = new CapturePaymentRequest('pi_abc123', 50.0);

        $this->repository->method('findByKey')->willReturn(null);

        $seenOptions = 'not-called';
        $paymentIntent = $this->createCapturedPaymentIntent('pi_abc123', 5000, 'eur', 'ch_native');
        $piService = $this->createMock(PaymentIntentService::class);
        $piService->method('capture')->willReturnCallback(
            function (string $id, ?array $params = null, ?array $opts = null) use (&$seenOptions, $paymentIntent) {
                $seenOptions = $opts;
                return $paymentIntent;
            }
        );
        $piService->method('retrieve')->willReturn($paymentIntent);
        $this->stripeClient->paymentIntents = $piService;

        $this->helper->capturePaymentIntent($this->stripeClient, $request);

        // A local DB record cannot protect against a lost response: Stripe must
        // see the key too, or a timed-out capture is retried as a new operation.
        $this->assertIsArray($seenOptions, 'Stripe must receive request options.');
        $this->assertArrayHasKey('idempotency_key', $seenOptions);
        $this->assertSame('capture:pi_abc123', $seenOptions['idempotency_key']);
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
