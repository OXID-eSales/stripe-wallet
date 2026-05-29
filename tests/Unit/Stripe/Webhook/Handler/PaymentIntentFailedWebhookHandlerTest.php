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
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentFailedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentFailedWebhookHandler
 */
final class PaymentIntentFailedWebhookHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentIntentFailedWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->handler = new PaymentIntentFailedWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillmentHandler,
            $this->contractRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    /** @test */
    public function supportsPaymentIntentPaymentFailedEventType(): void
    {
        $this->assertTrue($this->handler->supports('payment_intent.payment_failed'));
    }

    /** @test */
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
        $this->assertFalse($this->handler->supports('payment_intent.canceled'));
    }

    /** @test */
    public function missingPaymentIntentId_returnsFailure(): void
    {
        $outcome = $this->handler->handle($this->makeEvent([]));

        $this->assertFalse($outcome->result->isSuccess());
        $this->assertNull($outcome->contractId);
    }

    /** @test */
    public function handlerReturnsTrue_propagatesFailureReason_returnsContractFailed(): void
    {
        $event = $this->makeEvent([
            'id' => 'pi_fail',
            'last_payment_error' => ['message' => 'insufficient_funds'],
        ]);

        $this->fulfillmentHandler->method('handlePaymentFailed')
            ->with('pi_fail', 'insufficient_funds')
            ->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-fail');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertSame('contract_failed', $outcome->result->action);
        $this->assertSame('ctr-fail', $outcome->contractId);
    }

    /** @test */
    public function handlerReturnsNull_returnsContractNotFoundSkipped(): void
    {
        $this->fulfillmentHandler->method('handlePaymentFailed')->willReturn(null);

        $outcome = $this->handler->handle($this->makeEvent(['id' => 'pi_nf']));

        $this->assertSame('skipped', $outcome->result->action);
        $this->assertSame('Contract not found', $outcome->result->error);
        $this->assertNull($outcome->contractId);
    }

    /**
     * @param array<string, mixed> $objectData
     */
    private function makeEvent(array $objectData): WebhookEvent
    {
        return new WebhookEvent(
            id: 'evt_pi_fail_' . substr(md5(json_encode($objectData) ?: ''), 0, 8),
            type: 'payment_intent.payment_failed',
            data: ['object' => $objectData],
            created: time()
        );
    }
}
