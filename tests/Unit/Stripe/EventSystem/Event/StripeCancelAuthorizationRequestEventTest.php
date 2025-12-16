<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCancelAuthorizationRequestEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

class StripeCancelAuthorizationRequestEventTest extends TestCase
{
    public function testEventContainsContext(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_cancel',
            'cancellationReason' => 'requested_by_customer',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testEventCanAccessPaymentIntentId(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->assertEquals('pi_test_123', $event->getPaymentIntentId());
    }

    public function testEventCanAccessCancellationReason(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_456',
            'cancellationReason' => 'fraudulent',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->assertEquals('fraudulent', $event->getCancellationReason());
    }

    public function testEventReturnsNullForMissingPaymentIntentId(): void
    {
        $context = new EventContext([]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->assertNull($event->getPaymentIntentId());
    }

    public function testEventReturnsNullForMissingCancellationReason(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_789',
        ]);
        $event = new StripeCancelAuthorizationRequestEvent($context);

        $this->assertNull($event->getCancellationReason());
    }
}
