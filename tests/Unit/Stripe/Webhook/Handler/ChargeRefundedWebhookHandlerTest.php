<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Webhook\Handler\ChargeRefundedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\ChargeRefundedWebhookHandler::class)]
final class ChargeRefundedWebhookHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ChargeRefundedWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->handler = new ChargeRefundedWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillmentHandler,
            $this->contractRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function supportsChargeRefundedEventType(): void
    {
        $this->assertTrue($this->handler->supports('charge.refunded'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('charge.captured'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function missingPaymentIntentIdInCharge_returnsFailure(): void
    {
        $event = $this->makeEvent(['id' => 'ch_nopi']);

        $outcome = $this->handler->handle($event);

        $this->assertFalse($outcome->result->isSuccess());
        $this->assertSame('invalid_event', $outcome->result->action);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsTrue_extractsAmountFromCharge_returnsChargeRefunded(): void
    {
        $event = $this->makeEvent([
            'id' => 'ch_ok',
            'payment_intent' => 'pi_refund',
            'amount_refunded' => 5000,
        ]);

        $this->fulfillmentHandler->method('handleChargeRefunded')
            ->with('pi_refund', 50.0)
            ->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-refund');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertSame('charge_refunded', $outcome->result->action);
        $this->assertSame('ctr-refund', $outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsNull_returnsContractNotFoundSkipped(): void
    {
        $event = $this->makeEvent([
            'id' => 'ch_nf',
            'payment_intent' => 'pi_nf',
            'amount_refunded' => 1000,
        ]);

        $this->fulfillmentHandler->method('handleChargeRefunded')->willReturn(null);

        $outcome = $this->handler->handle($event);

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract not found', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    /**
     * @param array<string, mixed> $objectData
     */

    /**
     * Sprint 133 · Story 9 (F9): an unparseable amount used to arrive as 0.00
     * and be recorded as a real refund of nothing. It is now a processing
     * failure, so the controller answers 500 and Stripe retries the event.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function missingRefundedAmount_returnsFailureAndDoesNotFulfil(): void
    {
        $this->fulfillmentHandler->expects($this->never())->method('handleChargeRefunded');

        $outcome = $this->handler->handle($this->makeEvent([
            'id' => 'ch_1',
            'payment_intent' => 'pi_1',
            'currency' => 'eur',
            // no amount_refunded
        ]));

        $this->assertTrue($outcome->result->isFailure());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function zeroRefundedAmountIsStillProcessed(): void
    {
        // A genuine zero must stay distinguishable from "unknown".
        $this->fulfillmentHandler->expects($this->once())
            ->method('handleChargeRefunded')
            ->with('pi_zero', 0.0)
            ->willReturn(true);

        $outcome = $this->handler->handle($this->makeEvent([
            'id' => 'ch_zero',
            'payment_intent' => 'pi_zero',
            'amount_refunded' => 0,
            'currency' => 'eur',
        ]));

        $this->assertFalse($outcome->result->isFailure());
    }

    private function makeEvent(array $objectData): WebhookEvent
    {
        return new WebhookEvent(
            id: 'evt_charge_ref_' . substr(md5(json_encode($objectData) ?: ''), 0, 8),
            type: 'charge.refunded',
            data: ['object' => $objectData],
            created: time()
        );
    }
}
