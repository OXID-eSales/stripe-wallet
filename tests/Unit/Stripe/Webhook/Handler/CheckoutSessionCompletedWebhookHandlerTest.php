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
use OxidEsales\Payments\Stripe\Webhook\Handler\CheckoutSessionCompletedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\CheckoutSessionCompletedWebhookHandler::class)]
final class CheckoutSessionCompletedWebhookHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private CheckoutSessionCompletedWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->handler = new CheckoutSessionCompletedWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillmentHandler,
            $this->contractRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function supportsCheckoutSessionCompletedEventType(): void
    {
        $this->assertTrue($this->handler->supports('checkout.session.completed'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('checkout.session.expired'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paymentStatusNotPaid_returnsSkipped(): void
    {
        $outcome = $this->handler->handle($this->makeEvent('unpaid', 'pi_x', null));

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Checkout session not paid', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function paidButNoPaymentIntentId_returnsSkipped(): void
    {
        $event = new WebhookEvent(
            id: 'evt_cs_nopi',
            type: 'checkout.session.completed',
            data: ['object' => ['id' => 'cs_1', 'payment_status' => 'paid']],
            created: time()
        );

        $outcome = $this->handler->handle($event);

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('No payment intent ID in checkout session', $outcome->result->error);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function contractNotFound_returnsSkipped(): void
    {
        $this->contractRepository->method('findById')->willReturn(null);

        $outcome = $this->handler->handle($this->makeEvent('paid', 'pi_cs', 'ctr-missing'));

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract not found for checkout session', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function contractFoundAndCommitted_fulfillmentTrue_returnsContractFulfilled(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-committed');
        $contract->method('getState')->willReturn(ContractState::committed());
        $this->contractRepository->method('findById')->willReturn($contract);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->willReturn(true);

        $outcome = $this->handler->handle($this->makeEvent('paid', 'pi_cs_committed', 'ctr-committed'));

        $this->assertSame('contract_fulfilled', $outcome->result->action);
        $this->assertSame('ctr-committed', $outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function contractFoundNotCommitted_returnsContractUpdated(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-pending');
        $contract->method('getState')->willReturn(ContractState::pending());
        $this->contractRepository->method('findById')->willReturn($contract);

        $outcome = $this->handler->handle($this->makeEvent('paid', 'pi_cs_pending', 'ctr-pending'));

        $this->assertSame('contract_updated', $outcome->result->action);
        $this->assertSame('ctr-pending', $outcome->contractId);
    }

    private function makeEvent(string $paymentStatus, string $paymentIntentId, ?string $contractId): WebhookEvent
    {
        $objectData = [
            'id' => 'cs_test',
            'payment_status' => $paymentStatus,
            'payment_intent' => $paymentIntentId,
        ];

        if ($contractId !== null) {
            $objectData['metadata'] = ['contract_id' => $contractId];
        }

        return new WebhookEvent(
            id: 'evt_cs_comp_' . substr(md5($paymentIntentId), 0, 8),
            type: 'checkout.session.completed',
            data: ['object' => $objectData],
            created: time()
        );
    }
}
