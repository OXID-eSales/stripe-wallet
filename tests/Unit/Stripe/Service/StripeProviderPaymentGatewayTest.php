<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\LateMoney\ProviderPaymentGatewayInterface;
use OxidEsales\PaymentBase\LateMoney\ProviderPaymentState;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeProviderPaymentGateway;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * VR-23 T1/T5: what payment-base's LateMoneyResolver asks Stripe - what a PaymentIntent holds, and to give it back
 * once. Replaces the module's own StripeLatePaymentRefunder.
 */
final class StripeProviderPaymentGatewayTest extends TestCase
{
    private StripeAdapterInterface&MockObject $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
    }

    public function testItIsTheStripeGateway(): void
    {
        self::assertInstanceOf(ProviderPaymentGatewayInterface::class, $this->gateway());
        self::assertSame('stripe', $this->gateway()->provider());
    }

    public function testAPaidIntentHoldsItsReceivedAmount(): void
    {
        $this->adapter->method('retrievePaymentIntent')->with('pi_1')->willReturn($this->intent('succeeded', amountReceived: 4200));

        $status = $this->gateway()->statusOf('pi_1');

        self::assertSame(ProviderPaymentState::Paid, $status->state);
        self::assertSame('pi_1', $status->providerPaymentId);
        self::assertSame(42.0, $status->amount);
        self::assertSame('EUR', $status->currency);
    }

    public function testAnIntentAwaitingCaptureHoldsAnAuthorization(): void
    {
        $this->adapter->method('retrievePaymentIntent')->willReturn($this->intent('requires_capture', amountCapturable: 4200));

        $status = $this->gateway()->statusOf('pi_1');

        self::assertSame(ProviderPaymentState::Authorized, $status->state);
        self::assertSame(42.0, $status->amount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function intentsHoldingNoMoney(): iterable
    {
        yield 'processing' => ['processing'];
        yield 'needs a payment method' => ['requires_payment_method'];
        yield 'canceled' => ['canceled'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('intentsHoldingNoMoney')]
    public function testAnIntentThatTookNoMoneyHoldsNone(string $status): void
    {
        $this->adapter->method('retrievePaymentIntent')->willReturn($this->intent($status));

        self::assertSame(ProviderPaymentState::NoMoney, $this->gateway()->statusOf('pi_1')->state);
    }

    public function testTheWholeChargeIsRefundedUnderTheCallersIdempotencyKey(): void
    {
        $this->adapter->method('retrievePaymentIntent')->with('pi_1')->willReturn($this->intent('succeeded', chargeId: 'ch_1'));
        $this->adapter->expects($this->once())->method('createRefundByCharge')
            ->with('ch_1', null, 'duplicate', $this->anything(), 'late:pi_1')
            ->willReturn(new StripeRefundDto('re_1', 4200, 'eur', 'succeeded', 'duplicate', 0));

        self::assertSame('re_1', $this->gateway()->refund('pi_1', 'late:pi_1'));
    }

    public function testAPendingRefundIsAccepted(): void
    {
        $this->adapter->method('retrievePaymentIntent')->willReturn($this->intent('succeeded', chargeId: 'ch_1'));
        $this->adapter->method('createRefundByCharge')->willReturn(new StripeRefundDto('re_1', 4200, 'eur', 'pending', 'duplicate', 0));

        self::assertSame('re_1', $this->gateway()->refund('pi_1', 'late:pi_1'));
    }

    public function testARefundStripeFailedIsAnError(): void
    {
        $this->adapter->method('retrievePaymentIntent')->willReturn($this->intent('succeeded', chargeId: 'ch_1'));
        $this->adapter->method('createRefundByCharge')->willReturn(new StripeRefundDto('re_1', 4200, 'eur', 'failed', 'duplicate', 0));

        $this->expectException(RuntimeException::class);

        $this->gateway()->refund('pi_1', 'late:pi_1');
    }

    public function testAnIntentWithoutAChargeCannotBeRefunded(): void
    {
        $this->adapter->method('retrievePaymentIntent')->willReturn($this->intent('succeeded'));
        $this->adapter->expects($this->never())->method('createRefundByCharge');

        $this->expectException(RuntimeException::class);

        $this->gateway()->refund('pi_1', 'late:pi_1');
    }

    public function testAnAuthorizationIsReleasedByCancellingTheIntent(): void
    {
        $this->adapter->expects($this->once())->method('cancelPaymentIntent')->with('pi_1', 'abandoned')
            ->willReturn($this->intent('canceled'));

        $this->gateway()->release('pi_1', 'late:pi_1');
    }

    private function gateway(): StripeProviderPaymentGateway
    {
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($this->adapter);

        return new StripeProviderPaymentGateway($factory);
    }

    private function intent(string $status, int $amountReceived = 0, int $amountCapturable = 0, ?string $chargeId = null): StripePaymentIntentDto
    {
        return new StripePaymentIntentDto('pi_1', $status, 4200, 'eur', 0, $chargeId, null, $amountCapturable, $amountReceived);
    }
}
