<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use DateTimeImmutable;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\Exception\WebhookSignatureException;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookRequest;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StripeWebhookProcessor.
 *
 * @covers \OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor
 */
class StripeWebhookProcessorTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $logRepository;
    private LoggerInterface&MockObject $logger;
    private ModuleConfigurationService&MockObject $config;
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;

    protected function setUp(): void
    {
        $this->logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(ModuleConfigurationService::class);
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
    }

    public function testGetProviderNameReturnsStripe(): void
    {
        $processor = $this->createProcessor();

        // Use reflection to test protected method
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

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('parseAndValidateRequest');

        $this->expectException(WebhookSignatureException::class);
        $method->invoke($processor, $request);
    }

    public function testProcessEventHandlesPaymentIntentSucceeded(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'payment_intent.succeeded',
            data: ['object' => ['id' => 'pi_test123', 'amount' => 1000]],
            created: time()
        );

        $this->fulfillmentHandler->expects($this->once())
            ->method('handlePaymentSucceeded')
            ->with('pi_test123')
            ->willReturn(true);

        $processor = $this->createProcessor();

        // Use reflection to test protected method
        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('processEvent');

        $result = $method->invoke($processor, $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_fulfilled', $result->action);
    }

    public function testProcessEventHandlesPaymentIntentFailed(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'payment_intent.payment_failed',
            data: [
                'object' => [
                    'id' => 'pi_test123',
                    'last_payment_error' => ['message' => 'Card declined'],
                ],
            ],
            created: time()
        );

        $this->fulfillmentHandler->expects($this->once())
            ->method('handlePaymentFailed')
            ->with('pi_test123', 'Card declined')
            ->willReturn(true);

        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('processEvent');

        $result = $method->invoke($processor, $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_failed', $result->action);
    }

    public function testProcessEventHandlesPaymentIntentCanceled(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'payment_intent.canceled',
            data: [
                'object' => [
                    'id' => 'pi_test123',
                    'cancellation_reason' => 'requested_by_customer',
                ],
            ],
            created: time()
        );

        $this->fulfillmentHandler->expects($this->once())
            ->method('handlePaymentCanceled')
            ->with('pi_test123', 'requested_by_customer')
            ->willReturn(true);

        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('processEvent');

        $result = $method->invoke($processor, $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_cancelled', $result->action);
    }

    public function testProcessEventHandlesChargeCaptured(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'charge.captured',
            data: [
                'object' => [
                    'id' => 'ch_test123',
                    'payment_intent' => 'pi_test456',
                    'amount' => 5000,
                ],
            ],
            created: time()
        );

        $this->fulfillmentHandler->expects($this->once())
            ->method('handleChargeCaptured')
            ->with('pi_test456', 50.0) // Amount in currency units
            ->willReturn(true);

        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('processEvent');

        $result = $method->invoke($processor, $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('charge_captured', $result->action);
    }

    public function testProcessEventHandlesChargeRefunded(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'charge.refunded',
            data: [
                'object' => [
                    'id' => 'ch_test123',
                    'payment_intent' => 'pi_test456',
                    'amount_refunded' => 2500,
                ],
            ],
            created: time()
        );

        $this->fulfillmentHandler->expects($this->once())
            ->method('handleChargeRefunded')
            ->with('pi_test456', 25.0) // Amount in currency units
            ->willReturn(true);

        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('processEvent');

        $result = $method->invoke($processor, $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('charge_refunded', $result->action);
    }

    public function testProcessEventHandlesCheckoutSessionExpired(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'checkout.session.expired',
            data: [
                'object' => [
                    'id' => 'cs_test123',
                    'metadata' => ['contract_id' => 'contract_abc'],
                ],
            ],
            created: time()
        );

        $this->fulfillmentHandler->expects($this->once())
            ->method('handleSessionExpired')
            ->with('contract_abc')
            ->willReturn(true);

        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('processEvent');

        $result = $method->invoke($processor, $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('session_expired', $result->action);
    }

    public function testProcessEventReturnsSkippedForUnhandledEventType(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'customer.created',
            data: ['object' => ['id' => 'cus_test123']],
            created: time()
        );

        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('processEvent');

        $result = $method->invoke($processor, $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Unhandled event type: customer.created', $result->error);
    }

    public function testProcessEventReturnsSkippedWhenContractNotFound(): void
    {
        $event = new WebhookEvent(
            id: 'evt_123',
            type: 'payment_intent.succeeded',
            data: ['object' => ['id' => 'pi_unknown']],
            created: time()
        );

        $this->fulfillmentHandler->expects($this->once())
            ->method('handlePaymentSucceeded')
            ->with('pi_unknown')
            ->willReturn(null); // Contract not found

        $processor = $this->createProcessor();

        $reflection = new \ReflectionClass($processor);
        $method = $reflection->getMethod('processEvent');

        $result = $method->invoke($processor, $event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
    }

    private function createProcessor(): StripeWebhookProcessor
    {
        return new StripeWebhookProcessor(
            $this->logRepository,
            $this->logger,
            $this->config,
            $this->fulfillmentHandler,
            $this->contractRepository
        );
    }
}
