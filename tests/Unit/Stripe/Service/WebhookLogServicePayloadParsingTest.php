<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Service\WebhookLogService;
use PHPUnit\Framework\TestCase;

/**
 * Webhook file-log lines (VR-18 L4): the received line carries the id of the event's own object as `objectId` (the
 * field used to be called `payment_intent_id` and held `cs_...` / `ch_...` ids), the result line is payment-base's
 * WebhookDeliveryLogRecord with the reason the delivery was skipped or failed.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\WebhookLogService::class)]
class WebhookLogServicePayloadParsingTest extends TestCase
{
    public function testObjectIdIsTheIdOfTheEventObjectForAChargeEvent(): void
    {
        $recorder = $this->logReceived('evt_charge_refund', 'charge.refunded', ['id' => 'ch_abc', 'payment_intent' => 'pi_xyz']);

        $this->assertSame('ch_abc', $recorder->lastContext['objectId']);
        $this->assertArrayNotHasKey('payment_intent_id', $recorder->lastContext);
    }

    public function testObjectIdIsTheCheckoutSessionIdForACheckoutSessionEvent(): void
    {
        $recorder = $this->logReceived('evt_cs_completed', 'checkout.session.completed', ['id' => 'cs_qqq', 'payment_intent' => 'pi_target']);

        $this->assertSame('cs_qqq', $recorder->lastContext['objectId']);
    }

    public function testObjectIdForPaymentIntentEvent(): void
    {
        $recorder = $this->logReceived('evt_pi_succeeded', 'payment_intent.succeeded', ['id' => 'pi_zzz']);

        $this->assertSame('pi_zzz', $recorder->lastContext['objectId']);
    }

    public function testObjectIdIsUnknownWhenThePayloadHasNone(): void
    {
        $recorder = $this->logReceived('evt_no_obj', 'customer.created', []);

        $this->assertSame('unknown', $recorder->lastContext['objectId']);
    }

    public function testSkipReasonIsLogged(): void
    {
        $recorder = new CapturingFileLogger();

        (new WebhookLogService($recorder))->logResult(
            $this->payload('evt_cancel', 'payment_intent.canceled', ['id' => 'pi_1']),
            WebhookResult::skipped('Contract not found'),
            200,
            12,
            null
        );

        $this->assertSame('skipped', $recorder->lastContext['action']);
        $this->assertSame('Contract not found', $recorder->lastContext['reason']);
    }

    public function testResultLineIsTheDeliveryRecord(): void
    {
        $recorder = new CapturingFileLogger();

        (new WebhookLogService($recorder))->logResult(
            $this->payload('evt_1', 'checkout.session.completed', ['id' => 'cs_1']),
            WebhookResult::success('contract_fulfilled'),
            200,
            34,
            'contract-7'
        );

        $this->assertSame('WEBHOOK_RESULT', $recorder->lastMessage);
        $this->assertSame(
            [
                'provider' => 'stripe',
                'eventId' => 'evt_1',
                'eventType' => 'checkout.session.completed',
                'objectId' => 'cs_1',
                'contractId' => 'contract-7',
                'action' => 'contract_fulfilled',
                'reason' => null,
                'httpCode' => 200,
                'durationMs' => 34,
            ],
            $recorder->lastContext
        );
    }

    public function testAFailureLogsItsReason(): void
    {
        $recorder = new CapturingFileLogger();

        (new WebhookLogService($recorder))->logResult(
            $this->payload('evt_2', 'payment_intent.succeeded', ['id' => 'pi_2']),
            WebhookResult::failure('processing_failed', 'Webhook processing failed'),
            500,
            5,
            null
        );

        $this->assertSame('processing_failed', $recorder->lastContext['action']);
        $this->assertSame('Webhook processing failed', $recorder->lastContext['reason']);
        $this->assertSame(500, $recorder->lastContext['httpCode']);
    }

    /**
     * @param array<string, mixed> $object
     */
    private function logReceived(string $eventId, string $type, array $object): CapturingFileLogger
    {
        $recorder = new CapturingFileLogger();
        (new WebhookLogService($recorder))->logReceived($this->payload($eventId, $type, $object), 'sig', '127.0.0.1');

        return $recorder;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function payload(string $eventId, string $type, array $object): string
    {
        return (string) json_encode(['id' => $eventId, 'type' => $type, 'data' => ['object' => $object]]);
    }
}

/**
 * @internal
 */
final class CapturingFileLogger implements FileLoggerInterface
{
    /** @var array<string, mixed> */
    public array $lastContext = [];
    public string $lastMessage = '';

    public function log(string $message, array $context = []): void
    {
        $this->lastMessage = $message;
        $this->lastContext = $context;
    }
}
