<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandlerInterface;
use OxidEsales\Payments\Stripe\Service\WebhookProcessingService;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for WebhookProcessingService using WebhookLogRepositoryInterface
 *
 * Sprint 2 Phase 1: WebhookProcessingService must use the repository interface
 * instead of raw SQL queries to oe_payments_webhook_log table.
 *
 * LSP Compliance: Service depends on interface, not concrete implementation.
 *
 * Note: Tests that call processEvent() require OXID container for Registry::getLogger().
 * These are marked with @group requires-container and can be skipped in isolated unit tests.
 *
 * @group sprint-2
 * @group webhook-consolidation
 */
class WebhookProcessingServiceRepositoryTest extends TestCase
{
    /**
     * Check if OXID container is available for tests that need it
     */
    private function requiresContainer(): void
    {
        if (!class_exists(\OxidEsales\Eshop\Core\Registry::class)) {
            $this->markTestSkipped('OXID Registry not available');
        }

        // Try to get logger - if it fails, skip the test
        try {
            \OxidEsales\Eshop\Core\Registry::getLogger();
        } catch (\Throwable $e) {
            $this->markTestSkipped('OXID container not properly initialized: ' . $e->getMessage());
        }
    }

    /**
     * @test
     * RED: WebhookProcessingService should accept WebhookLogRepositoryInterface in constructor
     */
    public function serviceAcceptsWebhookLogRepositoryInterface(): void
    {
        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        $contractHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $service = new WebhookProcessingService(
            contractFulfillmentHandler: $contractHandler,
            webhookLogRepository: $repository
        );

        $this->assertInstanceOf(WebhookProcessingService::class, $service);
    }

    /**
     * @test
     * RED: Service should use repository to log webhook events
     * @group requires-container
     */
    public function serviceUsesRepositoryToLogWebhookEvents(): void
    {
        $this->requiresContainer();

        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        // Mock idempotency check - event not yet processed
        $repository
            ->method('existsByEventId')
            ->with('evt_test_123')
            ->willReturn(false);

        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getEventId() === 'evt_test_123'
                    && $log->getEventType() === 'payment_intent.succeeded'
                    && $log->getProvider() === 'stripe'
                    && $log->getStatus() === 'received';
            }));

        $contractHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $contractHandler->method('handlePaymentSucceeded')->willReturn(null);
        $service = new WebhookProcessingService(
            contractFulfillmentHandler: $contractHandler,
            webhookLogRepository: $repository
        );

        // Create mock Stripe event
        $stripeEvent = $this->createMockStripeEvent('evt_test_123', 'payment_intent.succeeded');

        $service->processEvent($stripeEvent);
    }

    /**
     * @test
     * RED: Service should store payload in webhook log
     * @group requires-container
     */
    public function serviceStoresPayloadInWebhookLog(): void
    {
        $this->requiresContainer();

        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        // Mock idempotency check
        $repository
            ->method('existsByEventId')
            ->willReturn(false);

        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) {
                $payload = $log->getPayload();
                return $payload !== null
                    && isset($payload['id'])
                    && $payload['id'] === 'pi_mock_123';
            }));

        $contractHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $contractHandler->method('handlePaymentSucceeded')->willReturn(null);
        $service = new WebhookProcessingService(
            contractFulfillmentHandler: $contractHandler,
            webhookLogRepository: $repository
        );

        $stripeEvent = $this->createMockStripeEvent('evt_payload_test', 'payment_intent.succeeded');

        $service->processEvent($stripeEvent);
    }

    /**
     * @test
     * RED: Service should check if event already processed (idempotency)
     * @group requires-container
     */
    public function serviceChecksIdempotencyBeforeProcessing(): void
    {
        $this->requiresContainer();

        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('existsByEventId')
            ->with('evt_duplicate_123')
            ->willReturn(true);

        // Should NOT process if already exists
        $repository
            ->expects($this->never())
            ->method('save');

        $contractHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $service = new WebhookProcessingService(
            contractFulfillmentHandler: $contractHandler,
            webhookLogRepository: $repository
        );

        $stripeEvent = $this->createMockStripeEvent('evt_duplicate_123', 'payment_intent.succeeded');

        $service->processEvent($stripeEvent);
    }

    /**
     * @test
     * RED: Service should save log with received status
     * @group requires-container
     */
    public function serviceSavesLogWithReceivedStatus(): void
    {
        $this->requiresContainer();

        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        $repository
            ->method('existsByEventId')
            ->willReturn(false);

        // Save should be called with 'received' status
        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (WebhookLog $log) {
                return $log->getStatus() === 'received';
            }));

        $contractHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $contractHandler->method('handlePaymentSucceeded')->willReturn(null);
        $service = new WebhookProcessingService(
            contractFulfillmentHandler: $contractHandler,
            webhookLogRepository: $repository
        );

        $stripeEvent = $this->createMockStripeEvent('evt_status_test', 'payment_intent.succeeded');

        $service->processEvent($stripeEvent);
    }

    // =================================================================
    // Helper Methods
    // =================================================================

    /**
     * Create mock Stripe Event
     *
     * Stripe\Event extends StripeObject which uses __get/__set magic methods.
     * We construct a real Event object from array data.
     */
    private function createMockStripeEvent(string $eventId, string $eventType): \Stripe\Event
    {
        // Construct event from array data - this is how Stripe SDK creates events
        return \Stripe\Event::constructFrom([
            'id' => $eventId,
            'type' => $eventType,
            'data' => [
                'object' => [
                    'id' => 'pi_mock_123',
                    'status' => 'succeeded',
                    'amount' => 1000,
                    'currency' => 'eur',
                ],
            ],
        ]);
    }
}
