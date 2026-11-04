<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentBase\Reconciliation\ProviderPaymentStatusInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripePaidStatusProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StripePaidStatusProvider::class)]
final class StripePaidStatusProviderTest extends TestCase
{
    #[Test]
    public function itIsTheStatusPortOfTheStripeProvider(): void
    {
        $provider = new StripePaidStatusProvider($this->createMock(StripeAdapterFactoryInterface::class));

        $this->assertInstanceOf(ProviderPaymentStatusInterface::class, $provider);
        $this->assertSame('stripe', $provider->provider());
    }

    #[Test]
    public function aCapturedPaymentIsPaidAtItsCaptureTime(): void
    {
        $capturedAt = new DateTimeImmutable('2026-10-01 12:30:00');

        $status = $this->providerAnswering($this->details('succeeded', true, $capturedAt))->statusOf('pi_1');

        $this->assertTrue($status->paid);
        $this->assertEquals($capturedAt, $status->paidAt);
    }

    #[Test]
    public function aPaymentThatIsNotCapturedIsNotPaidAndKeepsStripesStatus(): void
    {
        $status = $this->providerAnswering($this->details('requires_capture', false, null))->statusOf('pi_1');

        $this->assertFalse($status->paid);
        $this->assertSame('requires_capture', $status->status);
        $this->assertNull($status->paidAt);
    }

    #[Test]
    public function aCapturedPaymentWithoutCaptureTimeIsPaidWithoutTimestamp(): void
    {
        $status = $this->providerAnswering($this->details('succeeded', true, null))->statusOf('pi_1');

        $this->assertTrue($status->paid);
        $this->assertNull($status->paidAt, 'OXPAID must never be invented when Stripe gives no time');
    }

    #[Test]
    public function itAsksStripeForTheGivenPaymentIntent(): void
    {
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->expects($this->once())->method('getPaymentDetails')->with('pi_42')
            ->willReturn($this->details('succeeded', true, new DateTimeImmutable()));
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($adapter);

        (new StripePaidStatusProvider($factory))->statusOf('pi_42');
    }

    private function providerAnswering(PaymentDetailsResponse $details): StripePaidStatusProvider
    {
        $adapter = $this->createStub(StripeAdapterInterface::class);
        $adapter->method('getPaymentDetails')->willReturn($details);
        $factory = $this->createStub(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($adapter);

        return new StripePaidStatusProvider($factory);
    }

    private function details(string $status, bool $captured, ?DateTimeImmutable $capturedAt): PaymentDetailsResponse
    {
        return new PaymentDetailsResponse(
            providerPaymentId: 'pi_1',
            status: $status,
            amount: 10.0,
            currency: 'EUR',
            amountCaptured: $captured ? 10.0 : 0.0,
            amountRefunded: 0.0,
            isCaptured: $captured,
            isRefunded: false,
            isCancelled: false,
            createdAt: new DateTimeImmutable('2026-10-01 12:00:00'),
            capturedAt: $capturedAt,
        );
    }
}
