<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\Stripe3DSRequiredEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Stripe3DSRequiredEvent.
 *
 * This event is dispatched when 3D Secure authentication is required.
 */
class Stripe3DSRequiredEventTest extends TestCase
{
    public function testEventContainsContext(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
        ]);

        $event = new Stripe3DSRequiredEvent($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testEventContainsClientSecret(): void
    {
        $context = new EventContext([
            'clientSecret' => 'pi_test_123_secret_abc',
        ]);

        $event = new Stripe3DSRequiredEvent($context);

        $this->assertEquals('pi_test_123_secret_abc', $event->getClientSecret());
    }

    public function testGetClientSecretReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([]);

        $event = new Stripe3DSRequiredEvent($context);

        $this->assertNull($event->getClientSecret());
    }

    public function testEventContainsPaymentIntentId(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_3ds_xyz',
            'clientSecret' => 'pi_test_3ds_xyz_secret',
        ]);

        $event = new Stripe3DSRequiredEvent($context);

        $this->assertEquals('pi_test_3ds_xyz', $event->getPaymentIntentId());
    }

    public function testGetPaymentIntentIdReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([]);

        $event = new Stripe3DSRequiredEvent($context);

        $this->assertNull($event->getPaymentIntentId());
    }

    public function testEventContainsReturnUrl(): void
    {
        $context = new EventContext([
            'returnUrl' => 'https://shop.example.com/order?fnc=stripeReturn',
        ]);

        $event = new Stripe3DSRequiredEvent($context);

        $this->assertEquals(
            'https://shop.example.com/order?fnc=stripeReturn',
            $event->getReturnUrl()
        );
    }
}
