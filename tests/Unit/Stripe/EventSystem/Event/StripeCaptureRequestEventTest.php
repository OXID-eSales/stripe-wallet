<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCaptureRequestEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeCaptureRequestEvent.
 */
class StripeCaptureRequestEventTest extends TestCase
{
    public function testGetContractIdReturnsValue(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_abc123',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertEquals('contract_abc123', $event->getContractId());
    }

    public function testGetContractIdReturnsNullWhenMissing(): void
    {
        $context = new EventContext([]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertNull($event->getContractId());
    }

    public function testGetOrderIdReturnsValue(): void
    {
        $context = new EventContext([
            'orderId' => 'order_xyz789',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertEquals('order_xyz789', $event->getOrderId());
    }

    public function testGetPaymentIntentIdReturnsValue(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertEquals('pi_test_123', $event->getPaymentIntentId());
    }

    public function testGetAmountReturnsValue(): void
    {
        $context = new EventContext([
            'amount' => 99.99,
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertEquals(99.99, $event->getAmount());
    }

    public function testGetAmountReturnsNullForFullCapture(): void
    {
        $context = new EventContext([]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertNull($event->getAmount());
    }

    public function testIsFullCaptureReturnsTrueWhenAmountIsNull(): void
    {
        $context = new EventContext([]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertTrue($event->isFullCapture());
    }

    public function testIsFullCaptureReturnsFalseWhenAmountIsSet(): void
    {
        $context = new EventContext([
            'amount' => 50.00,
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertFalse($event->isFullCapture());
    }

    public function testGetInitiatorReturnsValue(): void
    {
        $context = new EventContext([
            'initiator' => 'webhook',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertEquals('webhook', $event->getInitiator());
    }

    public function testGetInitiatorReturnsAdminAsDefault(): void
    {
        $context = new EventContext([]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertEquals('admin', $event->getInitiator());
    }

    public function testGetReasonReturnsValue(): void
    {
        $context = new EventContext([
            'reason' => 'Order shipped',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertEquals('Order shipped', $event->getReason());
    }

    public function testGetIdempotencyKeyReturnsValue(): void
    {
        $context = new EventContext([
            'idempotencyKey' => 'key_unique_123',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertEquals('key_unique_123', $event->getIdempotencyKey());
    }

    public function testGetContextReturnsContext(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_123',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testContextCanBeModifiedByHandler(): void
    {
        $context = new EventContext([
            'contractId' => 'contract_123',
        ]);
        $event = new StripeCaptureRequestEvent($context);

        // Simulate handler setting results
        $event->getContext()->set('captureSuccess', true);
        $event->getContext()->set('captureId', 'ch_captured_123');
        $event->getContext()->set('capturedAmount', 99.99);

        $this->assertTrue($event->getContext()->get('captureSuccess'));
        $this->assertEquals('ch_captured_123', $event->getContext()->get('captureId'));
        $this->assertEquals(99.99, $event->getContext()->get('capturedAmount'));
    }
}
