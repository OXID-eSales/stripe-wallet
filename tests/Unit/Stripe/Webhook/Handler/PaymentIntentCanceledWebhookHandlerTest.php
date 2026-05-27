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
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentCanceledWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentCanceledWebhookHandler
 */
final class PaymentIntentCanceledWebhookHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentIntentCanceledWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->handler = new PaymentIntentCanceledWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillmentHandler,
            $this->contractRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    /** @test */
    public function supportsPaymentIntentCanceledEventType(): void
    {
        $this->assertTrue($this->handler->supports('payment_intent.canceled'));
    }

    /** @test */
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('payment_intent.payment_failed'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
    }

    /** @test */
    public function missingPaymentIntentId_returnsFailure(): void
    {
        $outcome = $this->handler->handle($this->makeEvent([]));

        $this->assertFalse($outcome->result->isSuccess());
        $this->assertNull($outcome->contractId);
    }

    /** @test */
    public function handlerReturnsTrue_propagatesCancellationReason_returnsContractCancelled(): void
    {
        $event = $this->makeEvent([
            'id' => 'pi_cancel',
            'cancellation_reason' => 'requested_by_customer',
        ]);

        $this->fulfillmentHandler->method('handlePaymentCanceled')
            ->with('pi_cancel', 'requested_by_customer')
            ->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-cancel');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertSame('contract_cancelled', $outcome->result->action);
        $this->assertSame('ctr-cancel', $outcome->contractId);
    }

    /**
     * @param array<string, mixed> $objectData
     */
    private function makeEvent(array $objectData): WebhookEvent
    {
        return new WebhookEvent(
            id: 'evt_pi_cancel_' . substr(md5(json_encode($objectData) ?: ''), 0, 8),
            type: 'payment_intent.canceled',
            data: ['object' => $objectData],
            created: time()
        );
    }
}
