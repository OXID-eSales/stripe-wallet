<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeAttemptCloser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S2d — the customer leaves a Stripe attempt for another payment
 * method. An open Checkout Session is expired, so it can take no more money.
 */
final class StripeAttemptCloserTest extends TestCase
{
    private StripeAdapterInterface&MockObject $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
    }

    public function testProvider_IsStripe(): void
    {
        self::assertSame('stripe', $this->closer()->provider());
    }

    public function testClose_PaidSession_IsPaidAndNothingIsExpired(): void
    {
        $this->adapter->method('retrieveCheckoutSession')->with('cs_1')->willReturn($this->session('complete', 'paid'));
        $this->adapter->expects($this->never())->method('expireCheckoutSession');

        self::assertSame(AttemptClosure::Paid, $this->closer()->close($this->contract('cs_1')));
    }

    public function testClose_OpenSession_IsExpiredAndClosed(): void
    {
        $this->adapter->method('retrieveCheckoutSession')->willReturn($this->session('open', 'unpaid'));
        $this->adapter->expects($this->once())->method('expireCheckoutSession')->with('cs_1')
            ->willReturn($this->session('expired', 'unpaid'));

        self::assertSame(AttemptClosure::Closed, $this->closer()->close($this->contract('cs_1')));
    }

    public function testClose_ExpiredSession_IsClosedWithoutACall(): void
    {
        $this->adapter->method('retrieveCheckoutSession')->willReturn($this->session('expired', 'unpaid'));
        $this->adapter->expects($this->never())->method('expireCheckoutSession');

        self::assertSame(AttemptClosure::Closed, $this->closer()->close($this->contract('cs_1')));
    }

    public function testClose_AttemptWithoutSession_IsClosedWithoutACall(): void
    {
        $this->adapter->expects($this->never())->method('retrieveCheckoutSession');

        self::assertSame(AttemptClosure::Closed, $this->closer()->close($this->contract(null)));
    }

    public function testClose_WhenStripeFails_TheErrorReachesTheCaller(): void
    {
        $this->adapter->method('retrieveCheckoutSession')->willThrowException(new \RuntimeException('Stripe unreachable'));

        $this->expectExceptionMessage('Stripe unreachable');
        $this->closer()->close($this->contract('cs_1'));
    }

    private function closer(): StripeAttemptCloser
    {
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($this->adapter);

        return new StripeAttemptCloser($factory);
    }

    private function contract(?string $sessionId): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 1.0, 'totalNet' => 1.0, 'totalVat' => 0.0, 'currency' => 'EUR',
        ]), 'ctr-1');
        if ($sessionId !== null) {
            $contract->setProvider('stripe', $sessionId);
        }

        return $contract;
    }

    private function session(string $status, string $paymentStatus): StripeCheckoutSessionDto
    {
        return new StripeCheckoutSessionDto(
            id: 'cs_1',
            paymentStatus: $paymentStatus,
            paymentIntentId: '',
            paymentIntentStatus: 'unknown',
            metadata: [],
            amountTotal: 100,
            currency: 'eur',
            status: $status,
        );
    }
}
