<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Checkout\PreviousCheckoutAttemptCleanerInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\PaidSessionSettler;
use OxidEsales\Payments\Stripe\Service\StripeAwareAttemptCleaner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S3 — the shared retire (payment-base) cancels the attempt a session
 * left open, whatever its provider. For a Stripe attempt Stripe is asked first:
 * a paid session is committed, never cancelled.
 */
final class StripeAwareAttemptCleanerTest extends TestCase
{
    private PreviousCheckoutAttemptCleanerInterface&MockObject $inner;
    private ContractRepositoryInterface&MockObject $contracts;
    private PaidSessionSettler&MockObject $settler;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(PreviousCheckoutAttemptCleanerInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->settler = $this->createMock(PaidSessionSettler::class);
    }

    public function testClean_StripeContractWithPaidSession_SettlesAndDoesNotCancel(): void
    {
        $contract = $this->contract('stripe');
        $this->contracts->method('findById')->with('ctr-1')->willReturn($contract);
        $this->settler->expects($this->once())->method('settle')->with($contract)->willReturn(true);
        $this->inner->expects($this->never())->method('clean');

        self::assertFalse($this->cleaner()->clean('ctr-1'));
    }

    public function testClean_StripeContractWithUnpaidSession_DelegatesToTheSharedRetire(): void
    {
        $this->contracts->method('findById')->willReturn($this->contract('stripe'));
        $this->settler->method('settle')->willReturn(false);
        $this->inner->expects($this->once())->method('clean')->with('ctr-1')->willReturn(true);

        self::assertTrue($this->cleaner()->clean('ctr-1'));
    }

    public function testClean_OtherProvider_DelegatesWithoutAskingStripe(): void
    {
        $this->contracts->method('findById')->willReturn($this->contract('mollie'));
        $this->settler->expects($this->never())->method('settle');
        $this->inner->expects($this->once())->method('clean')->with('ctr-1')->willReturn(true);

        self::assertTrue($this->cleaner()->clean('ctr-1'));
    }

    public function testClean_UnknownOrEmptyContract_DelegatesUnchanged(): void
    {
        $this->contracts->method('findById')->willReturn(null);
        $this->settler->expects($this->never())->method('settle');
        $this->inner->expects($this->exactly(2))->method('clean')->willReturn(false);

        self::assertFalse($this->cleaner()->clean('missing'));
        self::assertFalse($this->cleaner()->clean(null));
    }

    private function cleaner(): StripeAwareAttemptCleaner
    {
        return new StripeAwareAttemptCleaner($this->inner, $this->contracts, $this->settler);
    }

    private function contract(string $provider): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider($provider, 'prov-1');

        return $contract;
    }
}
