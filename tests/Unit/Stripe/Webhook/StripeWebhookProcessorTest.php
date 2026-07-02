<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\Exception\WebhookSignatureException;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventHandlerInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StripeWebhookProcessor infrastructure (non-routing concerns).
 *
 * Event-type routing behavior is covered exhaustively by
 * StripeWebhookProcessorCharacterizationTest.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor::class)]
class StripeWebhookProcessorTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $logRepository;
    private LoggerInterface&MockObject $logger;
    private ModuleConfigurationServiceInterface&MockObject $config;

    protected function setUp(): void
    {
        $this->logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
    }

    public function testGetProviderNameReturnsStripe(): void
    {
        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('getProviderName');

        $this->assertSame('stripe', $method->invoke($processor));
    }

    public function testParseAndValidateRequestThrowsOnInvalidSignature(): void
    {
        $this->config->expects($this->once())
            ->method('getWebhookSecret')
            ->willReturn('whsec_test_secret');

        $request = new WebhookRequest(
            payload: '{"id":"evt_123"}',
            signature: 'invalid_signature',
            remoteIp: '127.0.0.1',
            receivedAt: new DateTimeImmutable()
        );

        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('parseAndValidateRequest');

        $this->expectException(WebhookSignatureException::class);
        $method->invoke($processor, $request);
    }

    public function testProcessEventDispatchesToSupportingHandler(): void
    {
        $event = new WebhookEvent(
            id: 'evt_dispatch',
            type: 'charge.refunded',
            data: ['object' => ['id' => 'ch_x']],
            created: time()
        );

        $expectedResult = WebhookResult::success('charge_refunded');

        $handler = $this->createMock(StripeWebhookEventHandlerInterface::class);
        $handler->method('supports')->with('charge.refunded')->willReturn(true);
        $handler->expects($this->once())
            ->method('handle')
            ->with($event)
            ->willReturn(StripeWebhookOutcome::of($expectedResult, 'ctr-dispatch'));

        $processor = $this->createProcessor([$handler]);

        $result = $this->invokeProtected($processor, 'processEvent', $event);

        $this->assertSame('charge_refunded', $result->action);
        $this->assertSame(
            'ctr-dispatch',
            $this->invokeProtected($processor, 'getContractIdFromResult', $result)
        );
    }

    public function testProcessEventSkipsNonSupportingHandlerAndDispatchesToSupporting(): void
    {
        $event = new WebhookEvent(
            id: 'evt_order',
            type: 'payment_intent.succeeded',
            data: ['object' => ['id' => 'pi_x']],
            created: time()
        );

        $nonSupporting = $this->createMock(StripeWebhookEventHandlerInterface::class);
        $nonSupporting->method('supports')->willReturn(false);
        $nonSupporting->expects($this->never())->method('handle');

        $supporting = $this->createMock(StripeWebhookEventHandlerInterface::class);
        $supporting->method('supports')->with('payment_intent.succeeded')->willReturn(true);
        $supporting->expects($this->once())
            ->method('handle')
            ->willReturn(StripeWebhookOutcome::of(WebhookResult::success('contract_fulfilled'), 'ctr-1'));

        $processor = $this->createProcessor([$nonSupporting, $supporting]);

        $result = $this->invokeProtected($processor, 'processEvent', $event);

        $this->assertSame('contract_fulfilled', $result->action);
    }

    public function testProcessEventReturnsSkippedWhenNoHandlerSupports(): void
    {
        $event = new WebhookEvent(
            id: 'evt_unknown',
            type: 'customer.created',
            data: ['object' => ['id' => 'cus_x']],
            created: time()
        );

        $processor = $this->createProcessor();

        $result = $this->invokeProtected($processor, 'processEvent', $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Unhandled event type: customer.created', $result->error);
    }

    public function testProcessEventPropagatesContractIdFromOutcome(): void
    {
        $event = new WebhookEvent(
            id: 'evt_cid',
            type: 'charge.dispute.created',
            data: ['object' => ['id' => 'dp_1']],
            created: time()
        );

        $handler = $this->createMock(StripeWebhookEventHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(
            StripeWebhookOutcome::of(WebhookResult::success('dispute_logged'), 'ctr-linked')
        );

        $processor = $this->createProcessor([$handler]);

        $result = $this->invokeProtected($processor, 'processEvent', $event);

        $this->assertSame(
            'ctr-linked',
            $this->invokeProtected($processor, 'getContractIdFromResult', $result)
        );
    }

    public function testProcessEventSetsNullContractIdWhenHandlerReturnsNullContractId(): void
    {
        $event = new WebhookEvent(
            id: 'evt_nocontract',
            type: 'charge.dispute.created',
            data: ['object' => ['id' => 'dp_2']],
            created: time()
        );

        $handler = $this->createMock(StripeWebhookEventHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(
            StripeWebhookOutcome::of(WebhookResult::success('dispute_logged'))
        );

        $processor = $this->createProcessor([$handler]);

        $result = $this->invokeProtected($processor, 'processEvent', $event);

        $this->assertNull($this->invokeProtected($processor, 'getContractIdFromResult', $result));
    }

    public function testProcessEventFallsThroughForChargeCaptured(): void
    {
        // charge.captured is not handled — it was dead code removed in Sprint 112.
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'charge.captured',
            data: ['object' => ['id' => 'ch_x', 'payment_intent' => 'pi_x']],
            created: time()
        );

        $processor = $this->createProcessor();

        $result = $this->invokeProtected($processor, 'processEvent', $event);

        $this->assertSame('skipped', $result->action);
        $this->assertSame('Unhandled event type: charge.captured', $result->error);
    }

    /**
     * @param list<StripeWebhookEventHandlerInterface> $handlers
     */
    private function createProcessor(array $handlers = []): StripeWebhookProcessor
    {
        return new StripeWebhookProcessor(
            $this->logRepository,
            $this->logger,
            $this->config,
            $handlers
        );
    }

    private function invokeProtected(StripeWebhookProcessor $processor, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionClass($processor);
        return $reflection->getMethod($method)->invoke($processor, ...$args);
    }
}
