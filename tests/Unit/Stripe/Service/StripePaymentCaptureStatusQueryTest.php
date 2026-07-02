<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripePaymentCaptureStatusQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * STRP-AUTOCAP-REFUND Sprint 06.
 *
 * Unit tests for {@see StripePaymentCaptureStatusQuery} — the Stripe
 * implementation of payment-base's `PaymentCaptureStatusQueryInterface`.
 * Verifies the boolean answer matches the PSP-side normalized status
 * and that the implementation degrades safely (returns null) on
 * non-Stripe contracts, missing data, and adapter failures.
 */
final class StripePaymentCaptureStatusQueryTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $adapter;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapter        = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);
    }

    public function testReturnsNullForNonStripeProvider(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn('paypal');
        // Adapter must NOT be consulted for foreign providers — Stripe's
        // impl is a no-op for them, expected to coexist with PayPal's own.
        $this->adapterFactory->expects(self::never())->method('getStripeAdapter');

        self::assertNull($this->query()->isPaymentCaptured($contract));
    }

    public function testReturnsNullWhenContractHasNoProviderOrderId(): void
    {
        $contract = $this->stripeContract(providerOrderId: '');
        $this->adapter->expects(self::never())->method('getPaymentDetails');

        self::assertNull($this->query()->isPaymentCaptured($contract));
    }

    public function testReturnsTrueWhenPspStatusIsCaptured(): void
    {
        $contract = $this->stripeContract(providerOrderId: 'pi_succeeded');

        $this->adapter
            ->expects(self::once())
            ->method('getPaymentDetails')
            ->with('pi_succeeded')
            ->willReturn($this->paymentDetailsWithStatus(StripeStatusMapper::STATUS_CAPTURED));

        self::assertTrue($this->query()->isPaymentCaptured($contract));
    }

    public function testReturnsFalseWhenPspStatusIsAuthorized(): void
    {
        $contract = $this->stripeContract(providerOrderId: 'pi_requires_capture');

        $this->adapter
            ->method('getPaymentDetails')
            ->willReturn($this->paymentDetailsWithStatus(StripeStatusMapper::STATUS_AUTHORIZED));

        self::assertFalse($this->query()->isPaymentCaptured($contract));
    }

    public function testReturnsNullForUnrecognizedNormalizedStatus(): void
    {
        $contract = $this->stripeContract(providerOrderId: 'pi_processing');

        $this->adapter
            ->method('getPaymentDetails')
            ->willReturn($this->paymentDetailsWithStatus(StripeStatusMapper::STATUS_PENDING));

        self::assertNull($this->query()->isPaymentCaptured($contract));
    }

    public function testReturnsNullWhenAdapterThrows(): void
    {
        $contract = $this->stripeContract(providerOrderId: 'pi_explodes');

        $this->adapter
            ->method('getPaymentDetails')
            ->willThrowException(new \RuntimeException('Stripe API unreachable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        self::assertNull($this->query($logger)->isPaymentCaptured($contract));
    }

    private function query(?LoggerInterface $logger = null): StripePaymentCaptureStatusQuery
    {
        return new StripePaymentCaptureStatusQuery(
            $this->adapterFactory,
            $logger ?? new NullLogger(),
        );
    }

    private function stripeContract(string $providerOrderId): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn(StripeDefinitions::PROVIDER);
        $contract->method('getProviderOrderId')->willReturn($providerOrderId === '' ? null : $providerOrderId);
        $contract->method('getId')->willReturn('contract-id-' . substr($providerOrderId, 0, 8));
        return $contract;
    }

    private function paymentDetailsWithStatus(string $status): PaymentDetailsResponse
    {
        return new PaymentDetailsResponse(
            providerPaymentId: 'pi_test',
            status: $status,
            amount: 100.0,
            currency: 'EUR',
            amountCaptured: 0.0,
            amountRefunded: 0.0,
            isCaptured: false,
            isRefunded: false,
            isCancelled: false,
            createdAt: new \DateTimeImmutable('2026-05-15 12:00:00'),
        );
    }
}
