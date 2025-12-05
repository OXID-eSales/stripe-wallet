<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Webhook\Handler;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookEvent;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookEventHandlerInterface;
use OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler\ChargeRefundedHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Webhook\Handler\ChargeRefundedHandler
 * @group sprint-13
 * @group webhook
 * @group handler
 */
final class ChargeRefundedHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private LoggerInterface $logger;
    private ChargeRefundedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new ChargeRefundedHandler(
            $this->contractRepository,
            $this->logger
        );
    }

    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(WebhookEventHandlerInterface::class, $this->handler);
    }

    /**
     * @test
     */
    public function supportsChargeRefundedEvent(): void
    {
        $this->assertTrue($this->handler->supports('charge.refunded'));
    }

    /**
     * @test
     */
    public function doesNotSupportOtherEvents(): void
    {
        $this->assertFalse($this->handler->supports('charge.succeeded'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
    }

    /**
     * @test
     */
    public function updatesContractRefundedAmount(): void
    {
        $event = $this->createRefundEvent('pi_test_123', 5000, 1000);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->expects($this->once())
            ->method('addRefundedAmount')
            ->with(10.00); // 1000 cents = 10.00

        $contract->expects($this->once())
            ->method('setRefundedAt')
            ->with($this->isInstanceOf(\DateTimeInterface::class));

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->with('pi_test_123')
            ->willReturn($contract);

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('refund_recorded', $result->action);
    }

    /**
     * @test
     */
    public function returnsSkippedWhenNoContractFound(): void
    {
        $event = $this->createRefundEvent('pi_no_contract', 5000, 1000);

        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn(null);

        $result = $this->handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
    }

    /**
     * @test
     */
    public function logsRefundProcessing(): void
    {
        $event = $this->createRefundEvent('pi_log_test', 5000, 2500);

        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        $loggedMessages = [];
        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info')
            ->willReturnCallback(function ($message, $context) use (&$loggedMessages) {
                $loggedMessages[] = ['message' => $message, 'context' => $context];
            });

        $this->handler->handle($event);

        $handlingLog = array_filter($loggedMessages, fn($log) => str_contains($log['message'], 'Handling'));
        $this->assertNotEmpty($handlingLog, 'Should log handling event');
    }

    /**
     * @test
     */
    public function extractsPaymentIntentIdFromCharge(): void
    {
        $event = new WebhookEvent(
            'evt_123',
            'charge.refunded',
            [
                'object' => [
                    'id' => 'ch_charge_123',
                    'payment_intent' => 'pi_from_charge',
                    'amount_refunded' => 1000,
                ],
            ],
            time()
        );

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('addRefundedAmount');
        $contract->method('setRefundedAt');

        $this->contractRepository
            ->expects($this->once())
            ->method('findByProviderOrderId')
            ->with('pi_from_charge')
            ->willReturn($contract);

        $this->handler->handle($event);
    }

    /**
     * Create a test refund event.
     */
    private function createRefundEvent(
        string $paymentIntentId,
        int $amountCents,
        int $amountRefundedCents
    ): WebhookEvent {
        return new WebhookEvent(
            'evt_refund_' . substr(md5($paymentIntentId), 0, 8),
            'charge.refunded',
            [
                'object' => [
                    'id' => 'ch_' . substr(md5($paymentIntentId), 0, 16),
                    'payment_intent' => $paymentIntentId,
                    'amount' => $amountCents,
                    'amount_refunded' => $amountRefundedCents,
                ],
            ],
            time()
        );
    }
}
