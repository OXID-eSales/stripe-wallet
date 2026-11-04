<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededWebhookHandler::class)]
final class PaymentIntentSucceededWebhookHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private LoggerInterface&MockObject $logger;
    private PaymentIntentSucceededWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new PaymentIntentSucceededWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillmentHandler,
            $this->contractRepository,
            $this->logger,
            $this->createMock(\OxidEsales\PaymentBase\LateMoney\LateMoneyResolverInterface::class),
            $this->createMock(\OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface::class)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function supportsPaymentIntentSucceededEventType(): void
    {
        $this->assertTrue($this->handler->supports('payment_intent.succeeded'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('payment_intent.payment_failed'));
        $this->assertFalse($this->handler->supports('charge.refunded'));
        $this->assertFalse($this->handler->supports('checkout.session.completed'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function missingPaymentIntentId_returnsFailure(): void
    {
        $event = $this->makeEvent([]);

        $outcome = $this->handler->handle($event);

        $this->assertFalse($outcome->result->isSuccess());
        $this->assertSame('invalid_event', $outcome->result->action);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fulfillmentHandlerReturnsTrue_returnsContractFulfilledOutcome(): void
    {
        $event = $this->makeEvent(['id' => 'pi_ok', 'amount_received' => 1000, 'currency' => 'eur']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->with('pi_ok')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-abc');
        $contract->method('getState')->willReturn(ContractState::committed());
        $this->contractRepository->method('findByProviderOrderId')->with('pi_ok')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertTrue($outcome->result->isSuccess());
        $this->assertSame('contract_fulfilled', $outcome->result->action);
        $this->assertSame('ctr-abc', $outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fulfillmentHandlerReturnsFalse_returnsSkippedWithContractId(): void
    {
        $event = $this->makeEvent(['id' => 'pi_skip', 'amount_received' => 1000, 'currency' => 'eur']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-skip');
        $contract->method('getState')->willReturn(ContractState::committed());
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertTrue($outcome->result->isSuccess());
        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('ctr-skip', $outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fulfillmentHandlerReturnsNull_noMetadata_returnsContractNotFoundSkipped(): void
    {
        $event = $this->makeEvent(['id' => 'pi_nf', 'amount_received' => 1000, 'currency' => 'eur']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->willReturn(null);
        $this->contractRepository->method('findById')->willReturn(null);

        $outcome = $this->handler->handle($event);

        $this->assertTrue($outcome->result->isSuccess());
        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract not found', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unknownProviderOrderIdIsSkippedNotMatchedByMetadata(): void
    {
        $event = $this->makeEvent([
            'id' => 'pi_unknown',
            'amount_received' => 1000,
            'currency' => 'eur',
            'metadata' => ['contract_id' => 'ctr-by-metadata'],
        ]);

        $this->fulfillmentHandler->expects($this->once())->method('handlePaymentSucceeded')->willReturn(null);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-by-metadata');
        $contract->method('getState')->willReturn(ContractState::committed());
        $contract->expects($this->never())->method('setProvider');
        $this->contractRepository->method('findById')->willReturn($contract);
        $this->contractRepository->expects($this->never())->method('save');
        $this->logger->expects($this->atLeastOnce())->method('warning')->with(
            $this->stringContains('no contract'),
            $this->callback(static fn (array $c): bool => ($c['payment_intent_id'] ?? null) === 'pi_unknown')
        );

        $outcome = $this->handler->handle($event);

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract not found', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function testHandle_PassesAmountReceivedInCurrencyUnitsToFulfillmentHandler(): void
    {
        $event = $this->makeEvent(['id' => 'pi_amt', 'amount_received' => 1999, 'amount' => 2500, 'currency' => 'eur']);
        $this->fulfillmentHandler->expects($this->once())
            ->method('handlePaymentSucceeded')
            ->with('pi_amt', 19.99)
            ->willReturn(true);

        $this->handler->handle($event);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function authorisedOnlyBooksNothing(): void
    {
        // An authorised-not-captured intent carries the authorised `amount` but received nothing yet.
        $event = $this->makeEvent(['id' => 'pi_auth', 'amount' => 2500, 'amount_received' => 0, 'currency' => 'eur']);
        $this->fulfillmentHandler->expects($this->never())->method('handlePaymentSucceeded');

        $outcome = $this->handler->handle($event);

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('No amount received', $outcome->result->error);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aPayloadWithoutAmountReceivedBooksNothing(): void
    {
        $event = $this->makeEvent(['id' => 'pi_noamt', 'amount' => 2500, 'currency' => 'eur']);
        $this->fulfillmentHandler->expects($this->never())->method('handlePaymentSucceeded');

        $outcome = $this->handler->handle($event);

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('No amount received', $outcome->result->error);
    }

    /**
     * @param array<string, mixed> $objectData
     */
    private function makeEvent(array $objectData): WebhookEvent
    {
        return new WebhookEvent(
            id: 'evt_pi_succ_' . substr(md5(json_encode($objectData) ?: ''), 0, 8),
            type: 'payment_intent.succeeded',
            data: ['object' => $objectData],
            created: time()
        );
    }
}
