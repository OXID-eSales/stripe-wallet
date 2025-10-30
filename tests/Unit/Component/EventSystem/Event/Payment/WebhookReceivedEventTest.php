<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\EventSystem\Event\Payment;

use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\WebhookReceivedEventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentEventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;

final class WebhookReceivedEventTest extends TestCase
{
    private EventContext $context;

    protected function setUp(): void
    {
        $this->context = new EventContext(['userId' => 'user_456']);
    }

    public function testImplementsWebhookReceivedEventInterface(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.authorized',
            ['orderId' => 'order_123'],
            'signature_abc123'
        );

        $this->assertInstanceOf(WebhookReceivedEventInterface::class, $event);
    }

    public function testImplementsPaymentEventInterface(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.authorized',
            ['orderId' => 'order_123'],
            'signature_abc123'
        );

        $this->assertInstanceOf(PaymentEventInterface::class, $event);
    }

    public function testImplementsEventInterface(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.authorized',
            ['orderId' => 'order_123'],
            'signature_abc123'
        );

        $this->assertInstanceOf(EventInterface::class, $event);
    }

    public function testGetContext_ReturnsContext(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.authorized',
            ['orderId' => 'order_123'],
            'signature_abc123'
        );

        $this->assertSame($this->context, $event->getContext());
    }

    public function testGetProvider_ReturnsProvider(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'paypal',
            'payment.authorized',
            ['orderId' => 'order_123'],
            'signature_abc123'
        );

        $this->assertEquals('paypal', $event->getProvider());
    }

    public function testGetEventType_ReturnsEventType(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.captured',
            ['orderId' => 'order_123'],
            'signature_abc123'
        );

        $this->assertEquals('payment.captured', $event->getEventType());
    }

    public function testGetPayload_ReturnsPayload(): void
    {
        $payload = [
            'orderId' => 'order_123',
            'amount' => 100.50,
            'currency' => 'EUR'
        ];

        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.authorized',
            $payload,
            'signature_abc123'
        );

        $this->assertEquals($payload, $event->getPayload());
    }

    public function testGetSignature_ReturnsSignature(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.authorized',
            ['orderId' => 'order_123'],
            'signature_xyz789'
        );

        $this->assertEquals('signature_xyz789', $event->getSignature());
    }

    public function testEvent_IsImmutable(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.authorized',
            ['orderId' => 'order_123'],
            'signature_abc123'
        );

        $this->assertFalse(method_exists($event, 'setContext'));
        $this->assertFalse(method_exists($event, 'setProvider'));
        $this->assertFalse(method_exists($event, 'setEventType'));
    }

    public function testConstructor_UsesReadonlyProperties(): void
    {
        $event = new WebhookReceivedEvent(
            $this->context,
            'stripe',
            'payment.authorized',
            ['orderId' => 'order_123'],
            'signature_abc123'
        );

        $reflection = new \ReflectionClass($event);
        $this->assertTrue($reflection->getProperty('context')->isReadOnly());
        $this->assertTrue($reflection->getProperty('provider')->isReadOnly());
        $this->assertTrue($reflection->getProperty('eventType')->isReadOnly());
        $this->assertTrue($reflection->getProperty('payload')->isReadOnly());
        $this->assertTrue($reflection->getProperty('signature')->isReadOnly());
    }
}
