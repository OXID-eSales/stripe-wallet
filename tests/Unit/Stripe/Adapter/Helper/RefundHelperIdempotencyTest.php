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
use OxidEsales\Payments\Stripe\Adapter\Helper\IdempotencyKeyFactory;
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
 * Sprint 133 · Story 2 (F2) — DELIBERATE BEHAVIOUR CHANGE. This file previously
 * asserted the defect: keys were payment-scoped ('refund:pi_abc123'), so a
 * second legitimate partial refund replayed the first one's stored response as
 * a fresh success, while the by-charge path had no completed-check at all and
 * re-refunded for real. Keys are now request-scoped via IdempotencyKeyFactory
 * and both paths share IdempotentExecutor.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-46')]
#[\PHPUnit\Framework\Attributes\Group('idempotency')]
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
    #[\PHPUnit\Framework\Attributes\Test]
    public function refundPaymentCallsStripeWhenNoExistingRecord(): void
    {
        $request = new RefundPaymentRequest('pi_abc123', 25.0);

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with(IdempotencyKeyFactory::forRefund('pi_abc123', 2500, null, null))
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

    #[\PHPUnit\Framework\Attributes\Test]
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
            IdempotencyKeyFactory::forRefund('pi_abc123', 2500, null, null),
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function refundPaymentThrowsWhenProcessing(): void
    {
        $request = new RefundPaymentRequest('pi_abc123');

        $existingRecord = new IdempotencyRecord(
            'id_processing',
            IdempotencyKeyFactory::forRefund('pi_abc123', 2500, null, null),
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

    #[\PHPUnit\Framework\Attributes\Test]
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
            IdempotencyKeyFactory::forRefund('pi_abc123', 2500, null, null),
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
     * Sprint 133 · Story 3 (F8) — replaces refundPaymentWithoutIdempotencyCallsStripeDirectly,
     * which asserted that a RefundHelper built without a repository silently
     * performs unprotected refunds. Same class, same API, no signal: removed.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function cannotBeConstructedWithoutAnIdempotencyRepository(): void
    {
        $this->expectException(\ArgumentCountError::class);

        /** @phpstan-ignore-next-line intentionally wrong: proves the dependency is required */
        new RefundHelper();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function refundPaymentPassesNativeIdempotencyKeyToStripe(): void
    {
        $this->repository->method('findByKey')->willReturn(null);

        $seenOptions = 'not-called';
        $refund = $this->createStripeRefund('re_native', 2500, 'eur', 'succeeded');
        $refundService = $this->createMock(\Stripe\Service\RefundService::class);
        $refundService->method('create')->willReturnCallback(
            function (?array $params = null, ?array $opts = null) use (&$seenOptions, $refund) {
                $seenOptions = $opts;
                return $refund;
            }
        );
        $this->stripeClient->refunds = $refundService;

        $this->helper->refundPayment($this->stripeClient, new RefundPaymentRequest('pi_abc123', 25.0));

        $this->assertIsArray($seenOptions);
        $this->assertSame(
            IdempotencyKeyFactory::forRefund('pi_abc123', 2500, null, null),
            $seenOptions['idempotency_key'] ?? null
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createRefundByChargePassesNativeIdempotencyKeyToStripe(): void
    {
        $this->repository->method('findByKey')->willReturn(null);

        $seenOptions = 'not-called';
        $refund = $this->createStripeRefund('re_native_charge', 5000, 'eur', 'succeeded');
        $refundService = $this->createMock(\Stripe\Service\RefundService::class);
        $refundService->method('create')->willReturnCallback(
            function (?array $params = null, ?array $opts = null) use (&$seenOptions, $refund) {
                $seenOptions = $opts;
                return $refund;
            }
        );
        $this->stripeClient->refunds = $refundService;

        $this->helper->createRefundByCharge($this->stripeClient, 'ch_abc', 5000, null, null, 'refunded:0');

        $this->assertIsArray($seenOptions);
        $this->assertSame(
            IdempotencyKeyFactory::forRefundByCharge('ch_abc', 5000, null, 'refunded:0'),
            $seenOptions['idempotency_key'] ?? null
        );
    }

    // ==========================================
    // createRefundByCharge() idempotency tests
    // ==========================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function createRefundByChargeCallsStripeWhenNoExistingRecord(): void
    {
        $refund = $this->createStripeRefund('re_charge', 5000, 'eur', 'succeeded');

        $this->repository
            ->expects($this->once())
            ->method('findByKey')
            ->with(IdempotencyKeyFactory::forRefundByCharge('ch_abc', 5000, 'duplicate', null))
            ->willReturn(null);

        $this->mockRefundCreate($refund);

        $result = $this->helper->createRefundByCharge($this->stripeClient, 'ch_abc', 5000, 'duplicate');

        $this->assertSame($refund, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createRefundByChargeThrowsWhenProcessing(): void
    {
        $existingRecord = new IdempotencyRecord(
            'id_processing',
            IdempotencyKeyFactory::forRefundByCharge('ch_abc', null, null, null),
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


    // ==========================================
    // Sprint 133 · Story 2 (F2) — request-scoped keys
    // ==========================================

    #[\PHPUnit\Framework\Attributes\Test]
    public function refundPaymentUsesDifferentKeysForDifferentAmounts(): void
    {
        $seenKeys = [];
        $this->repository
            ->method('findByKey')
            ->willReturnCallback(function (string $key) use (&$seenKeys) {
                $seenKeys[] = $key;
                return null;
            });

        $this->mockRefundCreate($this->createStripeRefund('re_a', 1000, 'eur', 'succeeded'));

        $this->helper->refundPayment($this->stripeClient, new RefundPaymentRequest('pi_same', 10.0));
        $this->helper->refundPayment($this->stripeClient, new RefundPaymentRequest('pi_same', 20.0));

        $this->assertCount(2, $seenKeys);
        $this->assertNotSame(
            $seenKeys[0],
            $seenKeys[1],
            'Two different partial refund amounts must not share an idempotency key.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function refundPaymentUsesDifferentKeysForDifferentRequestReferences(): void
    {
        $seenKeys = [];
        $this->repository
            ->method('findByKey')
            ->willReturnCallback(function (string $key) use (&$seenKeys) {
                $seenKeys[] = $key;
                return null;
            });

        $this->mockRefundCreate($this->createStripeRefund('re_b', 1000, 'eur', 'succeeded'));

        // Same amount, same reason: only the caller's request reference (the
        // pre-refund state of the charge) distinguishes them.
        $this->helper->refundPayment(
            $this->stripeClient,
            new RefundPaymentRequest('pi_same', 10.0, null, [], null, 'refunded:0')
        );
        $this->helper->refundPayment(
            $this->stripeClient,
            new RefundPaymentRequest('pi_same', 10.0, null, [], null, 'refunded:1000')
        );

        $this->assertNotSame($seenKeys[0], $seenKeys[1]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createRefundByChargeReplaysStoredResultWhenCompleted(): void
    {
        $cached = json_encode([
            'id' => 're_cached_charge',
            'amount' => 5000,
            'currency' => 'eur',
            'status' => 'succeeded',
            'reason' => null,
            'created' => 1700000000,
        ]);

        $existing = new IdempotencyRecord(
            'id_done',
            IdempotencyKeyFactory::forRefundByCharge('ch_abc', 5000, null, null),
            'ch_abc',
            'refund_charge',
            'completed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $existing->setResult($cached);

        $this->repository->method('findByKey')->willReturn($existing);

        // The whole point: Stripe must NOT be called again for the same request.
        $refundService = $this->createMock(\Stripe\Service\RefundService::class);
        $refundService->expects($this->never())->method('create');
        $this->stripeClient->refunds = $refundService;

        $result = $this->helper->createRefundByCharge($this->stripeClient, 'ch_abc', 5000);

        $this->assertSame('re_cached_charge', $result->id);
        $this->assertSame(5000, $result->amount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createRefundByChargeStoresSerializedResultOnCompletion(): void
    {
        $this->repository->method('findByKey')->willReturn(null);

        $saved = [];
        $this->repository
            ->method('save')
            ->willReturnCallback(function (IdempotencyRecord $record) use (&$saved) {
                $saved[] = ['status' => $record->getStatus(), 'result' => $record->getResult()];
            });

        $this->mockRefundCreate($this->createStripeRefund('re_stored', 5000, 'eur', 'succeeded'));

        $this->helper->createRefundByCharge($this->stripeClient, 'ch_abc', 5000);

        $completed = array_values(array_filter($saved, static fn (array $r): bool => $r['status'] === 'completed'));
        $this->assertNotEmpty($completed, 'A completed record must be persisted.');
        $this->assertNotNull(
            $completed[0]['result'],
            'Without a stored result the completed record can never be replayed.'
        );
        $this->assertStringContainsString('re_stored', (string) $completed[0]['result']);
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