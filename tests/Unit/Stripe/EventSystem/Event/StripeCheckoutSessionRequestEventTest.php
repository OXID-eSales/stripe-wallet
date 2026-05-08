<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Event;

use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

class StripeCheckoutSessionRequestEventTest extends TestCase
{
    public function testEventContainsContext(): void
    {
        $context = new EventContext([
            'userId' => 'user123',
            'shopId' => 1,
        ]);

        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testEventContainsBasketFromContext(): void
    {
        $basket = (object) ['totalGross' => 100.0];
        $context = new EventContext([
            'basket' => $basket,
        ]);

        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->assertSame($basket, $event->getContext()->get('basket'));
    }

    public function testEventContainsUserFromContext(): void
    {
        $user = (object) ['id' => 'user123'];
        $context = new EventContext([
            'user' => $user,
            'userId' => 'user123',
        ]);

        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->assertSame($user, $event->getContext()->get('user'));
        $this->assertEquals('user123', $event->getContext()->get('userId'));
    }

    public function testEventContainsCaptureMode(): void
    {
        $context = new EventContext([
            'captureMode' => 'manual',
        ]);

        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->assertEquals('manual', $event->getContext()->get('captureMode'));
    }

    public function testEventDefaultCaptureModeIsAutomatic(): void
    {
        $context = new EventContext([]);

        $event = new StripeCheckoutSessionRequestEvent($context);

        $this->assertEquals('automatic', $event->getContext()->get('captureMode', 'automatic'));
    }
}
