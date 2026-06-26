<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\WebhookLogService;
use PHPUnit\Framework\TestCase;

/**
 * Receipt-log payload parsing — verifies the WEBHOOK_RECEIVED log line
 * carries the actual payment-intent ID for every event family, instead of
 * defaulting to the nested object's own ID (which is e.g. ch_… for charge.*
 * events, making the log mislabel the field).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\WebhookLogService::class)]
class WebhookLogServicePayloadParsingTest extends TestCase
{
    public function testLogReceivedReportsPaymentIntentIdForChargeRefundedEvent(): void
    {
        $payload = (string) json_encode([
            'id' => 'evt_charge_refund',
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_abc', 'payment_intent' => 'pi_xyz']],
        ]);

        $recorder = new CapturingFileLogger();
        (new WebhookLogService($recorder))->logReceived($payload, 'sig', '127.0.0.1');

        $this->assertSame('pi_xyz', $recorder->lastContext['payment_intent_id']);
        $this->assertNotSame('ch_abc', $recorder->lastContext['payment_intent_id']);
    }

    public function testLogReceivedReportsObjectIdForPaymentIntentSucceededEvent(): void
    {
        $payload = (string) json_encode([
            'id' => 'evt_pi_succeeded',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_zzz']],
        ]);

        $recorder = new CapturingFileLogger();
        (new WebhookLogService($recorder))->logReceived($payload, 'sig', '127.0.0.1');

        $this->assertSame('pi_zzz', $recorder->lastContext['payment_intent_id']);
    }

    public function testLogReceivedReportsPaymentIntentIdForCheckoutSessionCompletedEvent(): void
    {
        $payload = (string) json_encode([
            'id' => 'evt_cs_completed',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_qqq', 'payment_intent' => 'pi_target']],
        ]);

        $recorder = new CapturingFileLogger();
        (new WebhookLogService($recorder))->logReceived($payload, 'sig', '127.0.0.1');

        $this->assertSame('pi_target', $recorder->lastContext['payment_intent_id']);
    }

    public function testLogReceivedFallsBackToUnknownWhenNeitherFieldPresent(): void
    {
        $payload = (string) json_encode([
            'id' => 'evt_no_pi',
            'type' => 'customer.created',
            'data' => ['object' => []],
        ]);

        $recorder = new CapturingFileLogger();
        (new WebhookLogService($recorder))->logReceived($payload, 'sig', '127.0.0.1');

        $this->assertSame('unknown', $recorder->lastContext['payment_intent_id']);
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
