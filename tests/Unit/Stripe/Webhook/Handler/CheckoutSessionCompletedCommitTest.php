<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\CommitOutcome;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Service\Commit\PaymentConfirmation;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Webhook\Handler\CheckoutSessionCompletedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GRAPH-QL / PS3 — `checkout.session.completed` with `payment_status = paid`
 * for a PENDING contract commits it (payment-base ContractCommitService); the
 * session id stays the provider order id in the confirmation so the shop can
 * find the order from either Stripe id. Unpaid sessions and settled contracts
 * behave as before.
 */
final class CheckoutSessionCompletedCommitTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillment;
    private ContractRepositoryInterface&MockObject $contracts;
    private ContractCommitServiceInterface&MockObject $commit;

    protected function setUp(): void
    {
        $this->fulfillment = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
        $this->commit = $this->createMock(ContractCommitServiceInterface::class);
    }

    public function testAPaidSessionCommitsThePendingContractAndFulfilsIt(): void
    {
        $contract = $this->pendingContract();
        $this->contracts->method('findById')->with('ctr-1')->willReturn($contract);
        $this->commit->expects($this->once())->method('commit')
            ->with($this->callback(function (PaymentConfirmation $c): bool {
                self::assertSame('ctr-1', $c->contractId);
                self::assertSame('stripe', $c->providerName);
                self::assertSame('pi_1', $c->authorizationId);
                self::assertSame('cs_1', $c->providerOrderId);
                self::assertSame(100.0, $c->amount);
                self::assertSame('EUR', $c->currency);
                self::assertSame('webhook', $c->source);
                self::assertSame('cs_1', $c->extraContext['checkoutSessionId']);

                return true;
            }))
            ->willReturnCallback(function () use ($contract): CommitOutcome {
                $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
                $contract->commitToOrder('order-1');

                return CommitOutcome::committed('order-1');
            });
        $this->fulfillment->expects($this->once())->method('handlePaymentSucceeded')->with('pi_1')->willReturn(true);

        $outcome = $this->handler()->handle($this->event('paid'));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('ctr-1', $outcome->contractId);
    }

    public function testAnUnpaidSessionCommitsNothing(): void
    {
        $this->contracts->method('findById')->willReturn($this->pendingContract());
        $this->commit->expects($this->never())->method('commit');
        $this->fulfillment->expects($this->never())->method('handlePaymentSucceeded');

        $outcome = $this->handler()->handle($this->event('unpaid'));

        self::assertTrue($outcome->result->isSuccess(), 'provider id update is still a success');
    }

    public function testASettledContractIsNotCommittedAgain(): void
    {
        $contract = $this->pendingContract();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $this->contracts->method('findById')->willReturn($contract);
        $this->commit->expects($this->never())->method('commit');
        $this->fulfillment->method('handlePaymentSucceeded')->willReturn(true);

        self::assertTrue($this->handler()->handle($this->event('paid'))->result->isSuccess());
    }

    public function testARefusedCommitIsReported(): void
    {
        $this->contracts->method('findById')->willReturn($this->pendingContract());
        $this->commit->method('commit')->willReturn(CommitOutcome::refused('contract_cancelled'));
        $this->fulfillment->expects($this->never())->method('handlePaymentSucceeded');

        $outcome = $this->handler()->handle($this->event('paid'));

        self::assertFalse($outcome->result->isSuccess());
        self::assertStringContainsString('contract_cancelled', (string) $outcome->result->error);
    }

    private function handler(): CheckoutSessionCompletedWebhookHandler
    {
        return new CheckoutSessionCompletedWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillment,
            $this->contracts,
            new NullLogger(),
            $this->commit
        );
    }

    private function event(string $paymentStatus): WebhookEvent
    {
        return new WebhookEvent(id: 'evt_cs', type: 'checkout.session.completed', data: ['object' => [
            'id' => 'cs_1',
            'payment_status' => $paymentStatus,
            'payment_intent' => 'pi_1',
            'amount_total' => 10000,
            'currency' => 'eur',
            'metadata' => ['contract_id' => 'ctr-1'],
        ]], created: time());
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
