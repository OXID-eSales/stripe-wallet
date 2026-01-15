<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Webhook;

use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandlerInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService;
use PHPUnit\Framework\TestCase;
use Stripe\Event;

/**
 * TDD Tests for Charge webhook events
 *
 * Tests that WebhookProcessingService correctly handles all charge.* events.
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService
 * @group webhook
 * @group charge
 * @group tdd
 * @group sprint-1
 */
final class ChargeWebhookTest extends TestCase
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
        $this->contractFulfillmentHandler->method('handleChargeCaptured')->willReturn(null);
        $this->contractFulfillmentHandler->method('handleChargeRefunded')->willReturn(null);
        $this->service = new WebhookProcessingService(
            contractFulfillmentHandler: $this->contractFulfillmentHandler,
            webhookLogRepository: $this->webhookLogRepository
        );
    }

    // =========================================================================
    // charge.succeeded
    // =========================================================================

    /**
     * @test
     */
    public function handlesChargeSucceeded_LogsChargeDetails(): void
    {
        $event = $this->createStripeEvent('evt_ch_succeeded_001', 'charge.succeeded', [
            'id' => 'ch_test_123',
            'payment_intent' => 'pi_test_123',
            'amount' => 10000,
            'amount_captured' => 10000,
            'currency' => 'eur',
            'status' => 'succeeded',
            'paid' => true,
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
                return $log->getEventType() === 'charge.succeeded';
            }));

        $this->service->processEvent($event);

        $this->assertNotNull($savedLog);
        $payload = $savedLog->getPayload();
        $this->assertEquals('ch_test_123', $payload['id']);
        $this->assertEquals('pi_test_123', $payload['payment_intent']);
        $this->assertTrue($payload['paid']);
    }

    // =========================================================================
    // charge.captured
    // =========================================================================

    /**
     * @test
     */
    public function handlesChargeCaptured_LogsCaptureDetails(): void
    {
        $event = $this->createStripeEvent('evt_ch_captured_001', 'charge.captured', [
            'id' => 'ch_captured_123',
            'payment_intent' => 'pi_captured_123',
            'amount' => 10000,
            'amount_captured' => 10000,
            'captured' => true,
            'status' => 'succeeded',
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
                return $log->getEventType() === 'charge.captured';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertTrue($payload['captured']);
        $this->assertEquals(10000, $payload['amount_captured']);
    }

    /**
     * @test
     */
    public function handlesChargeCaptured_WithPartialCapture(): void
    {
        $event = $this->createStripeEvent('evt_ch_partial_001', 'charge.captured', [
            'id' => 'ch_partial_123',
            'payment_intent' => 'pi_partial_123',
            'amount' => 10000,
            'amount_captured' => 7500, // Partial capture
            'captured' => true,
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
        $this->assertEquals(10000, $payload['amount']);
        $this->assertEquals(7500, $payload['amount_captured']);
    }

    // =========================================================================
    // charge.refunded
    // =========================================================================

    /**
     * @test
     */
    public function handlesChargeRefunded_LogsPartialRefund(): void
    {
        $event = $this->createStripeEvent('evt_ch_refund_partial_001', 'charge.refunded', [
            'id' => 'ch_refund_partial_123',
            'payment_intent' => 'pi_refund_123',
            'amount' => 10000,
            'amount_refunded' => 5000, // Partial refund
            'refunded' => false,
            'status' => 'succeeded',
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
                return $log->getEventType() === 'charge.refunded';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals(5000, $payload['amount_refunded']);
        $this->assertFalse($payload['refunded']); // Not fully refunded
    }

    /**
     * @test
     */
    public function handlesChargeRefunded_LogsFullRefund(): void
    {
        $event = $this->createStripeEvent('evt_ch_refund_full_001', 'charge.refunded', [
            'id' => 'ch_refund_full_123',
            'payment_intent' => 'pi_refund_full_123',
            'amount' => 10000,
            'amount_refunded' => 10000, // Full refund
            'refunded' => true,
            'status' => 'succeeded',
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
        $this->assertEquals(10000, $payload['amount_refunded']);
        $this->assertTrue($payload['refunded']); // Fully refunded
    }

    /**
     * @test
     */
    public function handlesChargeRefunded_WithMultipleRefunds(): void
    {
        // First refund
        $event1 = $this->createStripeEvent('evt_ch_multi_refund_001', 'charge.refunded', [
            'id' => 'ch_multi_refund',
            'payment_intent' => 'pi_multi_refund',
            'amount' => 10000,
            'amount_refunded' => 3000,
            'refunded' => false,
        ]);

        // Second refund (cumulative)
        $event2 = $this->createStripeEvent('evt_ch_multi_refund_002', 'charge.refunded', [
            'id' => 'ch_multi_refund',
            'payment_intent' => 'pi_multi_refund',
            'amount' => 10000,
            'amount_refunded' => 7000, // 3000 + 4000
            'refunded' => false,
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->willReturnOnConsecutiveCalls(false, false);

        $logs = [];
        $this->webhookLogRepository
            ->expects($this->exactly(2))
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) use (&$logs) {
                $logs[] = $log;
                return true;
            }));

        $this->service->processEvent($event1);
        $this->service->processEvent($event2);

        $this->assertCount(2, $logs);
        $this->assertEquals(3000, $logs[0]->getPayload()['amount_refunded']);
        $this->assertEquals(7000, $logs[1]->getPayload()['amount_refunded']);
    }

    // =========================================================================
    // charge.failed
    // =========================================================================

    /**
     * @test
     */
    public function handlesChargeFailed_LogsFailureReason(): void
    {
        $event = $this->createStripeEvent('evt_ch_failed_001', 'charge.failed', [
            'id' => 'ch_failed_123',
            'payment_intent' => 'pi_failed_123',
            'status' => 'failed',
            'failure_code' => 'card_declined',
            'failure_message' => 'Your card was declined.',
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
                return $log->getEventType() === 'charge.failed';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals('card_declined', $payload['failure_code']);
        $this->assertEquals('Your card was declined.', $payload['failure_message']);
    }

    /**
     * @test
     */
    public function handlesChargeFailed_WithDifferentFailureCodes(): void
    {
        $failureCodes = [
            'card_declined' => 'Your card was declined.',
            'expired_card' => 'Your card has expired.',
            'incorrect_cvc' => 'Your card\'s security code is incorrect.',
            'processing_error' => 'An error occurred while processing your card.',
        ];

        foreach ($failureCodes as $code => $message) {
            $eventId = 'evt_fail_' . str_replace('_', '', $code);
            $event = $this->createStripeEvent($eventId, 'charge.failed', [
                'id' => 'ch_' . $code,
                'status' => 'failed',
                'failure_code' => $code,
                'failure_message' => $message,
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
            $contractHandler->method('handleChargeCaptured')->willReturn(null);
            $contractHandler->method('handleChargeRefunded')->willReturn(null);
            $service = new WebhookProcessingService(
                contractFulfillmentHandler: $contractHandler,
                webhookLogRepository: $repository
            );
            $service->processEvent($event);

            $this->assertEquals($code, $capturedLog->getPayload()['failure_code']);
        }
    }

    // =========================================================================
    // charge.pending
    // =========================================================================

    /**
     * @test
     */
    public function handlesChargePending_LogsPendingState(): void
    {
        $event = $this->createStripeEvent('evt_ch_pending_001', 'charge.pending', [
            'id' => 'ch_pending_123',
            'payment_intent' => 'pi_pending_123',
            'status' => 'pending',
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
                return $log->getEventType() === 'charge.pending'
                    && $log->getPayload()['status'] === 'pending';
            }));

        $this->service->processEvent($event);
    }

    // =========================================================================
    // charge.updated
    // =========================================================================

    /**
     * @test
     */
    public function handlesChargeUpdated_LogsUpdatedFields(): void
    {
        $event = $this->createStripeEvent('evt_ch_updated_001', 'charge.updated', [
            'id' => 'ch_updated_123',
            'payment_intent' => 'pi_updated_123',
            'description' => 'Updated description',
            'metadata' => ['order_id' => '12345'],
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
                return $log->getEventType() === 'charge.updated';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals('Updated description', $payload['description']);
        $this->assertEquals(['order_id' => '12345'], $payload['metadata']);
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
