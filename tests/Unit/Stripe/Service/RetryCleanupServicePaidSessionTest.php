<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The guard with Stripe replaced: answers the scripted session for any id and
 * a fixed current basket total.
 */
final class ScriptedInFlightGuard extends CheckoutInFlightGuard
{
    public function __construct(private readonly ?StripeCheckoutSessionDto $session)
    {
        parent::__construct(null);
    }

    protected function retrieveSession(string $sessionId): ?StripeCheckoutSessionDto
    {
        return $this->session;
    }

    protected function readCurrentBasketTotal(): array
    {
        return [10000, 'EUR'];
    }
}

/**
 * GRAPH-QL / PS3 — the hazard found in the 2026-10-01 feasibility report:
 * a shopper pays in Stripe but never reaches the return leg; the stale
 * cleanup then deleted the NOT_FINISHED order before asking the in-flight
 * guard, and cancelled the contract - money taken, no order. Now Stripe is
 * asked first: a paid session commits the contract, a usable unpaid one is
 * kept, and only then is anything deleted.
 */
final class RetryCleanupServicePaidSessionTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contracts;
    private ShopOrderServiceInterface&MockObject $orders;
    private ContractCommitServiceInterface&MockObject $commit;

    protected function setUp(): void
    {
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->orders = $this->createMock(ShopOrderServiceInterface::class);
        $this->commit = $this->createMock(ContractCommitServiceInterface::class);
    }

    public function testAPaidSessionCommitsTheContractInsteadOfCancellingIt(): void
    {
        $contract = $this->pendingContract();
        $this->contracts->method('findById')->willReturn($contract);
        $this->orders->expects($this->never())->method('deleteNotFinishedOrder');
        $this->commit->expects($this->once())->method('commit')
            ->with($this->callback(function (PaymentConfirmation $c): bool {
                self::assertSame('ctr-1', $c->contractId);
                self::assertSame('stripe', $c->providerName);
                self::assertSame('pi_1', $c->authorizationId);
                self::assertSame('cs_1', $c->providerOrderId);
                self::assertSame(100.0, $c->amount);
                self::assertSame('EUR', $c->currency);
                self::assertSame('cleanup', $c->source);

                return true;
            }))
            ->willReturn(CommitOutcome::committed('order-1'));

        $service = $this->service($this->session('paid'));
        $cleaned = $service->cleanupPreviousAttempt('ctr-1');

        self::assertFalse($cleaned, 'nothing was cleaned up: the contract is money, not garbage');
        self::assertFalse($contract->getState()->isCancelled());
    }

    public function testAUsableUnpaidSessionIsKeptAndTheOrderIsNeverDeleted(): void
    {
        $this->contracts->method('findById')->willReturn($this->pendingContract());
        $this->orders->expects($this->never())->method('deleteNotFinishedOrder');
        $this->commit->expects($this->never())->method('commit');

        self::assertFalse($this->service($this->session('unpaid'))->cleanupPreviousAttempt('ctr-1'));
    }

    public function testWithoutAUsableSessionTheAttemptIsRetiredAsBefore(): void
    {
        $contract = $this->pendingContract();
        $this->contracts->method('findById')->willReturn($contract);
        $this->orders->expects($this->once())->method('deleteNotFinishedOrder')->with('order-1')->willReturn(true);
        $this->commit->expects($this->never())->method('commit');

        self::assertTrue($this->service(null)->cleanupPreviousAttempt('ctr-1'));
        self::assertTrue($contract->getState()->isCancelled());
    }

    /**
     * No commit service wired (a services.yaml that predates this): a paid
     * session must still never be cancelled - it is logged and left alone.
     */
    public function testAPaidSessionWithoutACommitServiceIsLeftAlone(): void
    {
        $contract = $this->pendingContract();
        $this->contracts->method('findById')->willReturn($contract);
        $this->orders->expects($this->never())->method('deleteNotFinishedOrder');

        $service = new RetryCleanupService($this->contracts, $this->orders, null, new ScriptedInFlightGuard($this->session('paid')));

        self::assertFalse($service->cleanupPreviousAttempt('ctr-1'));
        self::assertTrue($contract->getState()->isPending());
    }

    private function service(?StripeCheckoutSessionDto $session): RetryCleanupService
    {
        return new RetryCleanupService($this->contracts, $this->orders, null, new ScriptedInFlightGuard($session), $this->commit);
    }

    private function session(string $paymentStatus): StripeCheckoutSessionDto
    {
        return new StripeCheckoutSessionDto(
            id: 'cs_1',
            paymentStatus: $paymentStatus,
            paymentIntentId: 'pi_1',
            paymentIntentStatus: $paymentStatus === 'paid' ? 'succeeded' : 'requires_payment_method',
            metadata: ['contract_id' => 'ctr-1'],
            amountTotal: 10000,
            currency: 'eur',
            url: 'https://checkout.stripe.com/c/cs_1',
        );
    }

    private function pendingContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'cs_1');

        return $contract;
    }
}
