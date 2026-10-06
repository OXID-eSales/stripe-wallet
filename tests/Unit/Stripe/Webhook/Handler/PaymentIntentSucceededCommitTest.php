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
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GRAPH-QL / PS3 — `payment_intent.succeeded` for a contract that is still
 * PENDING: until now the handler skipped ("not in COMMITTED state") and waited
 * for the browser return leg. A headless client may never come back, so the
 * paid intent commits the contract through payment-base's ContractCommitService
 * and the existing fulfilment follows.
 */
final class PaymentIntentSucceededCommitTest extends TestCase
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

    public function testAPendingContractIsCommittedFromThePaidIntentThenFulfilled(): void
    {
        $contract = $this->pendingContract();
        $this->contracts->method('findByProviderOrderId')->with('pi_1')->willReturn($contract);
        $this->commit->expects($this->once())->method('commit')
            ->with($this->callback(function (PaymentConfirmation $c): bool {
                self::assertSame('ctr-1', $c->contractId);
                self::assertSame('stripe', $c->providerName);
                self::assertSame('pi_1', $c->authorizationId);
                self::assertSame('pi_1', $c->providerOrderId);
                self::assertSame(100.0, $c->amount);
                self::assertSame('EUR', $c->currency);
                self::assertFalse($c->requiresCapture);
                self::assertSame('webhook', $c->source);
                self::assertSame('pi_1', $c->extraContext['paymentIntentId']);

                return true;
            }))
            ->willReturnCallback(function () use ($contract): CommitOutcome {
                $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
                $contract->commitToOrder('order-1');

                return CommitOutcome::committed('order-1');
            });
        $this->fulfillment->expects($this->once())->method('handlePaymentSucceeded')->with('pi_1')->willReturn(true);

        $outcome = $this->handler()->handle($this->event(['id' => 'pi_1', 'amount_received' => 10000, 'currency' => 'eur']));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('ctr-1', $outcome->contractId);
    }

    public function testAContractFoundOnlyThroughMetadataIsCommittedToo(): void
    {
        $contract = $this->pendingContract();
        $this->contracts->method('findByProviderOrderId')->willReturn(null);
        $this->contracts->method('findById')->with('ctr-1')->willReturn($contract);
        $this->commit->expects($this->once())->method('commit')->willReturn(CommitOutcome::committed('order-1'));
        $this->fulfillment->method('handlePaymentSucceeded')->willReturn(null);

        $outcome = $this->handler()->handle($this->event([
            'id' => 'pi_1', 'amount_received' => 10000, 'currency' => 'eur', 'metadata' => ['contract_id' => 'ctr-1'],
        ]));

        self::assertTrue($outcome->result->isSuccess());
    }

    public function testAnAlreadyCommittedContractIsNotCommittedAgain(): void
    {
        $contract = $this->pendingContract();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $this->contracts->method('findByProviderOrderId')->willReturn($contract);
        $this->commit->expects($this->never())->method('commit');
        $this->fulfillment->method('handlePaymentSucceeded')->willReturn(true);

        $outcome = $this->handler()->handle($this->event(['id' => 'pi_1', 'amount_received' => 10000, 'currency' => 'eur']));

        self::assertTrue($outcome->result->isSuccess());
    }

    public function testARefusedCommitIsReportedAndNothingIsFulfilled(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn($this->pendingContract());
        $this->commit->method('commit')->willReturn(CommitOutcome::refused('amount_mismatch'));
        $this->fulfillment->expects($this->never())->method('handlePaymentSucceeded');

        $outcome = $this->handler()->handle($this->event(['id' => 'pi_1', 'amount_received' => 9900, 'currency' => 'eur']));

        self::assertFalse($outcome->result->isSuccess());
        self::assertStringContainsString('amount_mismatch', (string) $outcome->result->error);
    }

    public function testWithoutACommitServiceTheOldBehaviourStays(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn($this->pendingContract());
        $this->fulfillment->method('handlePaymentSucceeded')->willReturn(false);

        $outcome = $this->handler(withCommit: false)->handle($this->event(['id' => 'pi_1', 'amount_received' => 10000, 'currency' => 'eur']));

        self::assertSame('skipped', $outcome->result->action, 'as before: nothing committed, fulfilment skipped');
        self::assertStringContainsString('COMMITTED', (string) $outcome->result->error);
    }

    private function handler(bool $withCommit = true): PaymentIntentSucceededWebhookHandler
    {
        return new PaymentIntentSucceededWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillment,
            $this->contracts,
            new NullLogger(),
            $withCommit ? $this->commit : null
        );
    }

    /**
     * @param array<string, mixed> $object
     */
    private function event(array $object): WebhookEvent
    {
        return new WebhookEvent(id: 'evt_1', type: 'payment_intent.succeeded', data: ['object' => $object], created: time());
    }

    private function pendingContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'pi_1');

        return $contract;
    }
}
