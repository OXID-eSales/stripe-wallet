<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Webhook;

use OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Webhook\WebhookLog;
use OxidSolutionCatalysts\Payments\Stripe\Handler\WebhookContractFulfillmentHandlerInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService;
use PHPUnit\Framework\TestCase;
use Stripe\Event;

/**
 * TDD Tests for Dispute (chargeback) webhook events
 *
 * Tests that WebhookProcessingService correctly handles all charge.dispute.* events.
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService
 * @group webhook
 * @group dispute
 * @group tdd
 * @group sprint-1
 */
final class DisputeWebhookTest extends TestCase
{
    private WebhookLogRepositoryInterface $webhookLogRepository;
    private WebhookContractFulfillmentHandlerInterface $contractFulfillmentHandler;
    private WebhookProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookLogRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->contractFulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->service = new WebhookProcessingService(
            contractFulfillmentHandler: $this->contractFulfillmentHandler,
            webhookLogRepository: $this->webhookLogRepository
        );
    }

    // =========================================================================
    // charge.dispute.created
    // =========================================================================

    /**
     * @test
     */
    public function handlesDisputeCreated_LogsDisputeDetails(): void
    {
        $event = $this->createStripeEvent('evt_dispute_created_001', 'charge.dispute.created', [
            'id' => 'dp_created_123',
            'charge' => 'ch_123',
            'payment_intent' => 'pi_123',
            'amount' => 10000,
            'currency' => 'eur',
            'reason' => 'fraudulent',
            'status' => 'needs_response',
            'evidence_details' => [
                'due_by' => 1735689600, // Future timestamp
                'has_evidence' => false,
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
                return $log->getEventType() === 'charge.dispute.created';
            }));

        $this->service->processEvent($event);

        $this->assertNotNull($savedLog);
        $payload = $savedLog->getPayload();
        $this->assertEquals('dp_created_123', $payload['id']);
        $this->assertEquals('fraudulent', $payload['reason']);
        $this->assertEquals('needs_response', $payload['status']);
    }

    /**
     * @test
     */
    public function handlesDisputeCreated_WithDifferentReasons(): void
    {
        $disputeReasons = [
            'fraudulent' => 'Fraudulent transaction',
            'duplicate' => 'Duplicate charge',
            'product_not_received' => 'Product not received',
            'product_unacceptable' => 'Product unacceptable',
            'subscription_canceled' => 'Subscription was canceled',
            'unrecognized' => 'Charge not recognized',
            'credit_not_processed' => 'Credit not processed',
            'general' => 'General dispute',
        ];

        foreach ($disputeReasons as $reason => $description) {
            $eventId = 'evt_dispute_' . str_replace('_', '', $reason);
            $event = $this->createStripeEvent($eventId, 'charge.dispute.created', [
                'id' => 'dp_' . $reason,
                'charge' => 'ch_' . $reason,
                'amount' => 10000,
                'reason' => $reason,
                'status' => 'needs_response',
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

            $this->assertEquals($reason, $capturedLog->getPayload()['reason'], "Reason '$reason' not captured");
        }
    }

    // =========================================================================
    // charge.dispute.updated
    // =========================================================================

    /**
     * @test
     */
    public function handlesDisputeUpdated_LogsStatusChange(): void
    {
        $event = $this->createStripeEvent('evt_dispute_updated_001', 'charge.dispute.updated', [
            'id' => 'dp_updated_123',
            'charge' => 'ch_123',
            'status' => 'under_review',
            'evidence_details' => [
                'has_evidence' => true,
                'submission_count' => 1,
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
                return $log->getEventType() === 'charge.dispute.updated';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals('under_review', $payload['status']);
        $this->assertTrue($payload['evidence_details']['has_evidence']);
    }

    /**
     * @test
     */
    public function handlesDisputeUpdated_WithEvidenceSubmission(): void
    {
        $event = $this->createStripeEvent('evt_dispute_evidence_001', 'charge.dispute.updated', [
            'id' => 'dp_evidence_123',
            'charge' => 'ch_123',
            'status' => 'under_review',
            'evidence' => [
                'customer_name' => 'John Doe',
                'customer_email_address' => 'john@example.com',
                'shipping_carrier' => 'DHL',
                'shipping_tracking_number' => 'DHL123456789',
            ],
            'evidence_details' => [
                'has_evidence' => true,
                'submission_count' => 1,
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
        $this->assertArrayHasKey('evidence', $payload);
        $this->assertEquals('John Doe', $payload['evidence']['customer_name']);
    }

    // =========================================================================
    // charge.dispute.closed
    // =========================================================================

    /**
     * @test
     */
    public function handlesDisputeClosed_LogsWonOutcome(): void
    {
        $event = $this->createStripeEvent('evt_dispute_won_001', 'charge.dispute.closed', [
            'id' => 'dp_won_123',
            'charge' => 'ch_123',
            'status' => 'won',
            'amount' => 10000,
            'currency' => 'eur',
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
                return $log->getEventType() === 'charge.dispute.closed';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals('won', $payload['status']);
    }

    /**
     * @test
     */
    public function handlesDisputeClosed_LogsLostOutcome(): void
    {
        $event = $this->createStripeEvent('evt_dispute_lost_001', 'charge.dispute.closed', [
            'id' => 'dp_lost_123',
            'charge' => 'ch_123',
            'status' => 'lost',
            'amount' => 10000,
            'currency' => 'eur',
            'balance_transactions' => [
                ['id' => 'txn_debit', 'amount' => -10000],
                ['id' => 'txn_fee', 'amount' => -1500],
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
        $this->assertEquals('lost', $payload['status']);
        $this->assertArrayHasKey('balance_transactions', $payload);
    }

    /**
     * @test
     */
    public function handlesDisputeClosed_WithAllOutcomes(): void
    {
        $outcomes = ['won', 'lost', 'warning_closed'];

        foreach ($outcomes as $outcome) {
            $eventId = 'evt_dispute_outcome_' . $outcome;
            $event = $this->createStripeEvent($eventId, 'charge.dispute.closed', [
                'id' => 'dp_' . $outcome,
                'charge' => 'ch_' . $outcome,
                'status' => $outcome,
                'amount' => 10000,
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

            $this->assertEquals($outcome, $capturedLog->getPayload()['status']);
        }
    }

    // =========================================================================
    // charge.dispute.funds_reinstated
    // =========================================================================

    /**
     * @test
     */
    public function handlesDisputeFundsReinstated_LogsReinstatement(): void
    {
        $event = $this->createStripeEvent('evt_dispute_reinstated_001', 'charge.dispute.funds_reinstated', [
            'id' => 'dp_reinstated_123',
            'charge' => 'ch_123',
            'status' => 'won',
            'amount' => 10000,
            'balance_transactions' => [
                ['id' => 'txn_credit', 'amount' => 10000],
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
                return $log->getEventType() === 'charge.dispute.funds_reinstated';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertEquals('won', $payload['status']);
        $this->assertArrayHasKey('balance_transactions', $payload);
    }

    // =========================================================================
    // charge.dispute.funds_withdrawn
    // =========================================================================

    /**
     * @test
     */
    public function handlesDisputeFundsWithdrawn_LogsWithdrawal(): void
    {
        $event = $this->createStripeEvent('evt_dispute_withdrawn_001', 'charge.dispute.funds_withdrawn', [
            'id' => 'dp_withdrawn_123',
            'charge' => 'ch_123',
            'status' => 'needs_response',
            'amount' => 10000,
            'balance_transactions' => [
                ['id' => 'txn_debit', 'amount' => -10000],
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
                return $log->getEventType() === 'charge.dispute.funds_withdrawn';
            }));

        $this->service->processEvent($event);

        $payload = $savedLog->getPayload();
        $this->assertArrayHasKey('balance_transactions', $payload);
        $this->assertEquals(-10000, $payload['balance_transactions'][0]['amount']);
    }

    // =========================================================================
    // Idempotency Tests
    // =========================================================================

    /**
     * @test
     */
    public function handlesDisputeEvent_SkipsDuplicate(): void
    {
        $event = $this->createStripeEvent('evt_dispute_dup_001', 'charge.dispute.created', [
            'id' => 'dp_dup',
            'charge' => 'ch_dup',
            'amount' => 10000,
            'reason' => 'fraudulent',
            'status' => 'needs_response',
        ]);

        $this->webhookLogRepository
            ->method('existsByEventId')
            ->with('evt_dispute_dup_001')
            ->willReturn(true);

        $this->webhookLogRepository
            ->expects($this->never())
            ->method('save');

        $this->service->processEvent($event);
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
