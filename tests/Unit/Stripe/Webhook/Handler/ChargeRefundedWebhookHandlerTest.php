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
use OxidEsales\Payments\Stripe\Webhook\ChargeRefund;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefundListerInterface;
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
    private ChargeRefundListerInterface&MockObject $lister;
    private ChargeRefundedWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->lister = $this->createMock(ChargeRefundListerInterface::class);

        $this->handler = new ChargeRefundedWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillmentHandler,
            $this->contractRepository,
            $this->createMock(LoggerInterface::class),
            $this->lister
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
            ->with('pi_refund', [])
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
     * VR-21 F5: what the event lists reaches the handler as it is (id, amount, currency) - the cumulative
     * `amount_refunded` is not booked from.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function theRefundsTheEventListsAreHandedOver(): void
    {
        $this->lister->expects($this->never())->method('listFor');
        $this->fulfillmentHandler->expects($this->once())
            ->method('handleChargeRefunded')
            ->with('pi_1', $this->callback(
                static fn (array $refunds): bool => count($refunds) === 1
                    && $refunds[0]->id === 're_1' && $refunds[0]->amount === 12.5 && $refunds[0]->currency === 'EUR'
            ))
            ->willReturn(true);

        $this->handler->handle($this->makeEvent([
            'id' => 'ch_1',
            'payment_intent' => 'pi_1',
            'currency' => 'eur',
            'amount_refunded' => 1250,
            'refunds' => ['data' => [['id' => 're_1', 'amount' => 1250, 'currency' => 'eur', 'status' => 'succeeded']], 'has_more' => false],
        ]));
    }

    /**
     * Newer API versions leave `refunds` off the event: Stripe's own list of the charge's refunds is the source.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function anEventWithoutARefundListIsCompletedFromStripesRefundList(): void
    {
        $listed = [new ChargeRefund('re_dash', 25.0, 'EUR', true)];
        $this->lister->expects($this->once())->method('listFor')->with('ch_nolist')->willReturn($listed);
        $this->fulfillmentHandler->expects($this->once())
            ->method('handleChargeRefunded')
            ->with('pi_nolist', $listed)
            ->willReturn(true);

        $this->handler->handle($this->makeEvent([
            'id' => 'ch_nolist',
            'payment_intent' => 'pi_nolist',
            'currency' => 'eur',
            'amount_refunded' => 2500,
        ]));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aChargeWithoutAnIdIsAnInvalidEvent(): void
    {
        $this->fulfillmentHandler->expects($this->never())->method('handleChargeRefunded');

        $outcome = $this->handler->handle($this->makeEvent(['payment_intent' => 'pi_noid', 'currency' => 'eur']));

        $this->assertTrue($outcome->result->isFailure());
        $this->assertSame('invalid_event', $outcome->result->action);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aFailureListingTheRefundsPropagatesSoStripeRetries(): void
    {
        $this->lister->method('listFor')->willThrowException(new \RuntimeException('Stripe unreachable'));
        $this->fulfillmentHandler->expects($this->never())->method('handleChargeRefunded');

        $this->expectException(\RuntimeException::class);

        $this->handler->handle($this->makeEvent([
            'id' => 'ch_x',
            'payment_intent' => 'pi_x',
            'currency' => 'eur',
        ]));
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
