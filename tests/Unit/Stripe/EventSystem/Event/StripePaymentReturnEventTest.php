<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StripePaymentReturnEvent.
 *
 * This event is dispatched when customer returns from Stripe after Payment Element confirmation.
 * Different from StripeCheckoutReturnEvent which handles Checkout Session returns.
 */
class StripePaymentReturnEventTest extends TestCase
{
    public function testEventContainsContext(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
        ]);

        $event = new StripePaymentReturnEvent($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testEventContainsPaymentIntentId(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_return_abc',
        ]);

        $event = new StripePaymentReturnEvent($context);

        $this->assertEquals('pi_test_return_abc', $event->getPaymentIntentId());
    }

    public function testGetPaymentIntentIdReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([]);

        $event = new StripePaymentReturnEvent($context);

        $this->assertNull($event->getPaymentIntentId());
    }

    public function testEventContainsRedirectStatus(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
            'redirectStatus' => 'succeeded',
        ]);

        $event = new StripePaymentReturnEvent($context);

        $this->assertEquals('succeeded', $event->getRedirectStatus());
    }

    public function testGetRedirectStatusReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([]);

        $event = new StripePaymentReturnEvent($context);

        $this->assertNull($event->getRedirectStatus());
    }

    public function testEventContainsClientSecret(): void
    {
        $context = new EventContext([
            'clientSecret' => 'pi_test_123_secret_xyz',
        ]);

        $event = new StripePaymentReturnEvent($context);

        $this->assertEquals('pi_test_123_secret_xyz', $event->getClientSecret());
    }
}
