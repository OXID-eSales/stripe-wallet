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
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentAmountCapturableUpdatedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GRAPH-QL / PS6 follow-up — manual capture on the headless path. Found live:
 * the shopper paid, the session completed `unpaid`, the intent was
 * `requires_capture`, and no webhook committed anything (PS3 committed paid
 * sessions and succeeded intents only). This event does it, requiresCapture.
 */
final class PaymentIntentAmountCapturableUpdatedCommitTest extends TestCase
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

    public function testSupportsOnlyItsEvent(): void
    {
        self::assertTrue($this->handler()->supports('payment_intent.amount_capturable_updated'));
        self::assertFalse($this->handler()->supports('payment_intent.succeeded'));
    }

    public function testAnAuthorizedIntentCommitsThePendingContractWithRequiresCapture(): void
    {
        $contract = $this->pendingContract();
        // A Checkout Session contract still carries the session id: found through metadata.
        $this->contracts->method('findByProviderOrderId')->with('pi_1')->willReturn(null);
        $this->contracts->method('findById')->with('ctr-1')->willReturn($contract);
        $this->commit->expects($this->once())->method('commit')
            ->with($this->callback(function (PaymentConfirmation $c): bool {
                self::assertSame('ctr-1', $c->contractId);
                self::assertSame('stripe', $c->providerName);
                self::assertSame('pi_1', $c->authorizationId);
                self::assertSame('pi_1', $c->providerOrderId);
                self::assertSame(30.9, $c->amount);
                self::assertSame('EUR', $c->currency);
                self::assertTrue($c->requiresCapture, 'authorized, not captured: the order must not be marked paid');
                self::assertSame('webhook', $c->source);
                self::assertSame('pi_1', $c->extraContext['paymentIntentId']);

                return true;
            }))
            ->willReturnCallback(function () use ($contract): CommitOutcome {
                $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
                $contract->commitToOrder('order-1');

                return CommitOutcome::committed('order-1');
            });
        $this->fulfillment->expects($this->never())->method('handlePaymentSucceeded');

        $outcome = $this->handler()->handle($this->event([
            'id' => 'pi_1', 'status' => 'requires_capture', 'amount_capturable' => 3090, 'amount' => 3090,
            'currency' => 'eur', 'metadata' => ['contract_id' => 'ctr-1'],
        ]));

        self::assertTrue($outcome->result->isSuccess());
        self::assertSame('contract_authorized', $outcome->result->action);
        self::assertSame('ctr-1', $outcome->contractId);
    }

    public function testAnIntentNotAwaitingCaptureIsSkipped(): void
    {
        $this->commit->expects($this->never())->method('commit');

        $outcome = $this->handler()->handle($this->event(['id' => 'pi_1', 'status' => 'succeeded', 'amount' => 3090, 'currency' => 'eur']));

        self::assertSame('skipped', $outcome->result->action);
    }

    public function testAnAlreadyCommittedContractIsLeftAlone(): void
    {
        // The browser return leg was first (CheckoutReturnService accepts requires_capture).
        $contract = $this->pendingContract();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $this->contracts->method('findByProviderOrderId')->willReturn($contract);
        $this->commit->expects($this->never())->method('commit');

        $outcome = $this->handler()->handle($this->event(['id' => 'pi_1', 'status' => 'requires_capture', 'amount_capturable' => 3090, 'currency' => 'eur']));

        self::assertSame('skipped', $outcome->result->action);
        self::assertSame('ctr-1', $outcome->contractId);
    }

    public function testARefusedCommitIsAWebhookFailureSoStripeRetries(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn($this->pendingContract());
        $this->commit->method('commit')->willReturn(CommitOutcome::refused('amount_mismatch'));

        $outcome = $this->handler()->handle($this->event(['id' => 'pi_1', 'status' => 'requires_capture', 'amount_capturable' => 100, 'currency' => 'eur']));

        self::assertFalse($outcome->result->isSuccess());
        self::assertStringContainsString('amount_mismatch', (string) $outcome->result->error);
    }

    public function testNoContractNoCommit(): void
    {
        $this->contracts->method('findByProviderOrderId')->willReturn(null);
        $this->contracts->method('findById')->willReturn(null);
        $this->commit->expects($this->never())->method('commit');

        $outcome = $this->handler()->handle($this->event(['id' => 'pi_1', 'status' => 'requires_capture', 'amount_capturable' => 3090, 'currency' => 'eur']));

        self::assertSame('skipped', $outcome->result->action);
    }

    private function handler(): PaymentIntentAmountCapturableUpdatedWebhookHandler
    {
        return new PaymentIntentAmountCapturableUpdatedWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillment,
            $this->contracts,
            new NullLogger(),
            $this->commit
        );
    }

    /**
     * @param array<string, mixed> $object
     */
    private function event(array $object): WebhookEvent
    {
        return new WebhookEvent(id: 'evt_1', type: 'payment_intent.amount_capturable_updated', data: ['object' => $object], created: time());
    }

    private function pendingContract(): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 30.9, 'totalNet' => 25.97, 'totalVat' => 4.93, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'cs_test_1');

        return $contract;
    }
}
