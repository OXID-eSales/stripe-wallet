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
            $this->logger
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
        $event = $this->makeEvent(['id' => 'pi_ok']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->with('pi_ok')->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-abc');
        $this->contractRepository->method('findByProviderOrderId')->with('pi_ok')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertTrue($outcome->result->isSuccess());
        $this->assertSame('contract_fulfilled', $outcome->result->action);
        $this->assertSame('ctr-abc', $outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fulfillmentHandlerReturnsFalse_returnsSkippedWithContractId(): void
    {
        $event = $this->makeEvent(['id' => 'pi_skip']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->willReturn(false);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-skip');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertTrue($outcome->result->isSuccess());
        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('ctr-skip', $outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fulfillmentHandlerReturnsNull_noMetadata_returnsContractNotFoundSkipped(): void
    {
        $event = $this->makeEvent(['id' => 'pi_nf']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->willReturn(null);
        $this->contractRepository->method('findById')->willReturn(null);

        $outcome = $this->handler->handle($event);

        $this->assertTrue($outcome->result->isSuccess());
        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract not found', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fulfillmentHandlerReturnsNull_metadataContractFulfilled_returnsAlreadyFulfilled(): void
    {
        $event = $this->makeEvent([
            'id' => 'pi_meta',
            'metadata' => ['contract_id' => 'ctr-done'],
        ]);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->willReturn(null);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-done');
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $this->contractRepository->method('findById')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract already fulfilled', $outcome->result->error);
        $this->assertSame('ctr-done', $outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fulfillmentHandlerReturnsNull_metadataContractCommitted_fulfilledSuccessfully(): void
    {
        $event = $this->makeEvent([
            'id' => 'pi_committed',
            'metadata' => ['contract_id' => 'ctr-commit'],
        ]);

        $this->fulfillmentHandler->expects($this->exactly(2))
            ->method('handlePaymentSucceeded')
            ->willReturnOnConsecutiveCalls(null, true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-commit');
        $contract->method('getState')->willReturn(ContractState::committed());
        $this->contractRepository->method('findById')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertSame('contract_fulfilled', $outcome->result->action);
        $this->assertSame('ctr-commit', $outcome->contractId);
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
