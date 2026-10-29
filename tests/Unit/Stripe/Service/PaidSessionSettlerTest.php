<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard;
use OxidEsales\Payments\Stripe\Service\PaidSessionSettler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The guard with Stripe replaced: answers the scripted session for any id.
 */
final class SettlerScriptedInFlightGuard extends CheckoutInFlightGuard
{
    public function __construct(
        private readonly ?StripeCheckoutSessionDto $session,
        private readonly ?\Throwable $failure = null
    ) {
        parent::__construct(null);
    }

    protected function retrieveSession(string $sessionId): ?StripeCheckoutSessionDto
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->session;
    }
}

/**
 * OPC-175 — the "is this attempt money?" question, moved out of
 * RetryCleanupService so the payment-base retire can ask it too.
 */
final class PaidSessionSettlerTest extends TestCase
{
    private ContractCommitServiceInterface&MockObject $commit;

    protected function setUp(): void
    {
        $this->commit = $this->createMock(ContractCommitServiceInterface::class);
    }

    public function testSettle_PaidSession_CommitsTheContractAndAnswersTrue(): void
    {
        $this->commit->expects($this->once())->method('commit')
            ->with($this->callback(static function (PaymentConfirmation $c): bool {
                return $c->contractId === 'ctr-1'
                    && $c->providerName === 'stripe'
                    && $c->authorizationId === 'pi_1'
                    && $c->providerOrderId === 'cs_1'
                    && $c->amount === 100.0
                    && $c->currency === 'EUR'
                    && $c->source === 'cleanup';
            }))
            ->willReturn(CommitOutcome::committed('order-1'));

        self::assertTrue($this->settler($this->session('paid'), $this->commit)->settle($this->pendingContract()));
    }

    public function testSettle_UnpaidSession_AnswersFalseAndCommitsNothing(): void
    {
        $this->commit->expects($this->never())->method('commit');

        self::assertFalse($this->settler($this->session('unpaid'), $this->commit)->settle($this->pendingContract()));
    }

    public function testSettle_NoStripeSession_AnswersFalse(): void
    {
        $this->commit->expects($this->never())->method('commit');

        self::assertFalse($this->settler(null, $this->commit)->settle($this->pendingContract()));
    }

    public function testSettle_StripeCannotAnswer_FailsAndCommitsNothing(): void
    {
        $this->commit->expects($this->never())->method('commit');
        $guard = new SettlerScriptedInFlightGuard(null, new \RuntimeException('Stripe unreachable'));

        $this->expectExceptionMessage('Stripe unreachable');
        (new PaidSessionSettler($guard, $this->commit, new NullLogger()))->settle($this->pendingContract());
    }

    private function settler(?StripeCheckoutSessionDto $session, ContractCommitServiceInterface $commit): PaidSessionSettler
    {
        return new PaidSessionSettler(new SettlerScriptedInFlightGuard($session), $commit, new NullLogger());
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
