<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Webhook;

use DateTimeImmutable;
use OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookLog;
use OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandlerInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService;
use PHPUnit\Framework\TestCase;
use Stripe\Event;

/**
 * TDD Tests for PaymentIntent webhook events
 *
 * Tests that WebhookProcessingService correctly handles all payment_intent.* events.
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService
 * @group webhook
 * @group payment-intent
 * @group tdd
 * @group sprint-1
 */
final class PaymentIntentWebhookTest extends TestCase
{
    private WebhookLogRepositoryInterface $webhookLogRepository;
    private WebhookContractFulfillmentHandlerInterface $contractFulfillmentHandler;
    private WebhookProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookLogRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->contractFulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        // Default: contract not found (null), use legacy path
        $this->contractFulfillmentHandler->method('handlePaymentSucceeded')->willReturn(null);
        $this->contractFulfillmentHandler->method('handlePaymentFailed')->willReturn(null);
        $this->service = new WebhookProcessingService(
            contractFulfillmentHandler: $this->contractFulfillmentHandler,
            webhookLogRepository: $this->webhookLogRepository
        );
    }

    // =========================================================================
    // payment_intent.succeeded
    // =========================================================================

    /**
     * @test
     */
    public function handlesPaymentIntentSucceeded_LogsEvent(): void
    {
        $event = $this->createStripeEvent('evt_pi_succeeded_001', 'payment_intent.succeeded', [
            'id' => 'pi_test_succeeded_123',
            'status' => 'succeeded',
            'amount' => 10000,
            'currency' => 'eur',
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->with('evt_pi_succeeded_001')
            ->willReturn(false);

        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getEventId() === 'evt_pi_succeeded_001'
                    && $log->getEventType() === 'payment_intent.succeeded'
                    && $log->getProvider() === 'stripe'
                    && $log->getStatus() === 'received';
            }));

        $this->service->processEvent($event);
    }

    /**
     * @test
     */
    public function handlesPaymentIntentSucceeded_SkipsDuplicateEvent(): void
    {
        $event = $this->createStripeEvent('evt_duplicate_001', 'payment_intent.succeeded', [
            'id' => 'pi_test_dup',
            'status' => 'succeeded',
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->with('evt_duplicate_001')
            ->willReturn(true);

        $this->webhookLogRepository
            ->expects($this->never())
            ->method('save');

        $this->service->processEvent($event);
    }

    /**
     * @test
     */
    public function handlesPaymentIntentSucceeded_StoresPayloadCorrectly(): void
    {
        $paymentData = [
            'id' => 'pi_payload_test',
            'status' => 'succeeded',
            'amount' => 25000,
            'currency' => 'eur',
            'payment_method' => 'pm_card_visa',
        ];

        $event = $this->createStripeEvent('evt_payload_001', 'payment_intent.succeeded', $paymentData);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturn(false);

        $savedLog = null;
        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog) {
                $savedLog = $log;
                return true;
            }));

        $this->service->processEvent($event);

        $this->assertNotNull($savedLog);
        $payload = $savedLog->getPayload();
        $this->assertIsArray($payload);
        $this->assertEquals('pi_payload_test', $payload['id']);
        $this->assertEquals('succeeded', $payload['status']);
        $this->assertEquals(25000, $payload['amount']);
    }

    // =========================================================================
    // payment_intent.payment_failed
    // =========================================================================

    /**
     * @test
     */
    public function handlesPaymentIntentFailed_LogsErrorDetails(): void
    {
        $event = $this->createStripeEvent('evt_pi_failed_001', 'payment_intent.payment_failed', [
            'id' => 'pi_failed_123',
            'status' => 'requires_payment_method',
            'last_payment_error' => [
                'code' => 'card_declined',
                'message' => 'Your card was declined.',
                'decline_code' => 'insufficient_funds',
            ],
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturn(false);

        $savedLog = null;
        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog) {
                $savedLog = $log;
                return $log->getEventType() === 'payment_intent.payment_failed';
            }));

        $this->service->processEvent($event);

        $this->assertNotNull($savedLog);
        $payload = $savedLog->getPayload();
        $this->assertArrayHasKey('last_payment_error', $payload);
        $this->assertEquals('card_declined', $payload['last_payment_error']['code']);
    }

    /**
     * @test
     */
    public function handlesPaymentIntentFailed_WithMultipleDeclineReasons(): void
    {
        $declineReasons = [
            'card_declined' => 'Your card was declined.',
            'insufficient_funds' => 'Your card has insufficient funds.',
            'expired_card' => 'Your card has expired.',
        ];

        foreach ($declineReasons as $code => $message) {
            $eventId = 'evt_decline_' . $code;
            $event = $this->createStripeEvent($eventId, 'payment_intent.payment_failed', [
                'id' => 'pi_' . $code,
                'status' => 'requires_payment_method',
                'last_payment_error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ]);

            $repository = $this->createMock(WebhookLogRepositoryInterface::class);
            $repository->method('existsByEventId')->willReturn(false);

            $capturedLog = null;
            $repository->expects($this->once())
                ->method('save')
                ->with($this->callback(function (WebhookLog $log) use (&$capturedLog) {
                    $capturedLog = $log;
                    return true;
                }));

            $contractHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
            $contractHandler->method('handlePaymentFailed')->willReturn(null);
            $service = new WebhookProcessingService(
                contractFulfillmentHandler: $contractHandler,
                webhookLogRepository: $repository
            );
            $service->processEvent($event);

            $this->assertNotNull($capturedLog, "Log should be captured for $code");
            $this->assertEquals($code, $capturedLog->getPayload()['last_payment_error']['code']);
        }
    }

    // =========================================================================
    // payment_intent.requires_action (3DS)
    // =========================================================================

    /**
     * @test
     */
    public function handlesPaymentIntentRequiresAction_Logs3DSRequired(): void
    {
        $event = $this->createStripeEvent('evt_pi_3ds_001', 'payment_intent.requires_action', [
            'id' => 'pi_3ds_123',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'use_stripe_sdk',
                'use_stripe_sdk' => [
                    'type' => 'three_d_secure_redirect',
                    'stripe_js' => 'https://hooks.stripe.com/...',
                ],
            ],
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturn(false);

        $savedLog = null;
        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog) {
                $savedLog = $log;
                return $log->getEventType() === 'payment_intent.requires_action';
            }));

        $this->service->processEvent($event);

        $this->assertNotNull($savedLog);
        $payload = $savedLog->getPayload();
        $this->assertArrayHasKey('next_action', $payload);
        $this->assertEquals('use_stripe_sdk', $payload['next_action']['type']);
    }

    /**
     * @test
     */
    public function handlesPaymentIntentRequiresAction_WithRedirectToUrl(): void
    {
        $event = $this->createStripeEvent('evt_pi_redirect_001', 'payment_intent.requires_action', [
            'id' => 'pi_redirect_123',
            'status' => 'requires_action',
            'next_action' => [
                'type' => 'redirect_to_url',
                'redirect_to_url' => [
                    'url' => 'https://hooks.stripe.com/3d_secure_2/...',
                    'return_url' => 'https://shop.local/checkout/return',
                ],
            ],
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturn(false);

        $savedLog = null;
        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog) {
                $savedLog = $log;
                return true;
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals('redirect_to_url', $payload['next_action']['type']);
    }

    // =========================================================================
    // payment_intent.canceled
    // =========================================================================

    /**
     * @test
     */
    public function handlesPaymentIntentCanceled_LogsCancellation(): void
    {
        $event = $this->createStripeEvent('evt_pi_canceled_001', 'payment_intent.canceled', [
            'id' => 'pi_canceled_123',
            'status' => 'canceled',
            'cancellation_reason' => 'abandoned',
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturn(false);

        $savedLog = null;
        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog) {
                $savedLog = $log;
                return $log->getEventType() === 'payment_intent.canceled';
            }));

        $this->service->processEvent($event);

        $this->assertNotNull($savedLog);
        $payload = $savedLog->getPayload();
        $this->assertEquals('abandoned', $payload['cancellation_reason']);
    }

    /**
     * @test
     */
    public function handlesPaymentIntentCanceled_WithDifferentReasons(): void
    {
        $cancellationReasons = ['abandoned', 'duplicate', 'requested_by_customer', 'automatic'];

        foreach ($cancellationReasons as $reason) {
            $eventId = 'evt_cancel_' . $reason;
            $event = $this->createStripeEvent($eventId, 'payment_intent.canceled', [
                'id' => 'pi_cancel_' . $reason,
                'status' => 'canceled',
                'cancellation_reason' => $reason,
            ]);

            $repository = $this->createMock(WebhookLogRepositoryInterface::class);
            $repository->method('existsByEventId')->willReturn(false);

            $capturedLog = null;
            $repository->expects($this->once())
                ->method('save')
                ->with($this->callback(function (WebhookLog $log) use (&$capturedLog) {
                    $capturedLog = $log;
                    return true;
                }));

            $contractHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
            $service = new WebhookProcessingService(
                contractFulfillmentHandler: $contractHandler,
                webhookLogRepository: $repository
            );
            $service->processEvent($event);

            $this->assertEquals($reason, $capturedLog->getPayload()['cancellation_reason']);
        }
    }

    // =========================================================================
    // payment_intent.processing
    // =========================================================================

    /**
     * @test
     */
    public function handlesPaymentIntentProcessing_LogsProcessingState(): void
    {
        $event = $this->createStripeEvent('evt_pi_processing_001', 'payment_intent.processing', [
            'id' => 'pi_processing_123',
            'status' => 'processing',
            'amount' => 15000,
            'currency' => 'eur',
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturn(false);

        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getEventType() === 'payment_intent.processing'
                    && $log->getPayload()['status'] === 'processing';
            }));

        $this->service->processEvent($event);
    }

    // =========================================================================
    // payment_intent.amount_capturable_updated
    // =========================================================================

    /**
     * @test
     */
    public function handlesAmountCapturableUpdated_LogsCapturableAmount(): void
    {
        $event = $this->createStripeEvent('evt_pi_capturable_001', 'payment_intent.amount_capturable_updated', [
            'id' => 'pi_capturable_123',
            'status' => 'requires_capture',
            'amount' => 10000,
            'amount_capturable' => 9500,
            'amount_received' => 0,
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturn(false);

        $savedLog = null;
        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog) {
                $savedLog = $log;
                return $log->getEventType() === 'payment_intent.amount_capturable_updated';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals(9500, $payload['amount_capturable']);
        $this->assertEquals('requires_capture', $payload['status']);
    }

    // =========================================================================
    // payment_intent.created
    // =========================================================================

    /**
     * @test
     */
    public function handlesPaymentIntentCreated_LogsNewPaymentIntent(): void
    {
        $event = $this->createStripeEvent('evt_pi_created_001', 'payment_intent.created', [
            'id' => 'pi_new_123',
            'status' => 'requires_payment_method',
            'amount' => 20000,
            'currency' => 'eur',
            'capture_method' => 'automatic',
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturn(false);

        $savedLog = null;
        $this->webhookLogRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$savedLog) {
                $savedLog = $log;
                return $log->getEventType() === 'payment_intent.created';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals('requires_payment_method', $payload['status']);
        $this->assertEquals('automatic', $payload['capture_method']);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createStripeEvent(string $eventId, string $eventType, array $objectData): Event
    {
        return Event::constructFrom([
            'id' => $eventId,
            'type' => $eventType,
            'created' => time(),
            'data' => [
                'object' => $objectData,
            ],
        ]);
    }
}
