<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StripePaymentExecuteEvent.
 *
 * This event is dispatched when Payment Element payment needs to be executed/verified.
 * Used in the Payment Element flow (card form on order page) as opposed to Checkout Session.
 */
class StripePaymentExecuteEventTest extends TestCase
{
    public function testEventContainsContext(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
        ]);

        $event = new StripePaymentExecuteEvent($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testEventContainsPaymentIntentId(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_abc123',
        ]);

        $event = new StripePaymentExecuteEvent($context);

        $this->assertEquals('pi_test_abc123', $event->getPaymentIntentId());
    }

    public function testGetPaymentIntentIdReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([]);

        $event = new StripePaymentExecuteEvent($context);

        $this->assertNull($event->getPaymentIntentId());
    }

    public function testEventContainsContractId(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
            'contractId' => 'contract_xyz',
        ]);

        $event = new StripePaymentExecuteEvent($context);

        $this->assertEquals('contract_xyz', $event->getContext()->get('contractId'));
    }

    public function testEventContainsClientSecret(): void
    {
        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
            'clientSecret' => 'pi_test_123_secret_abc',
        ]);

        $event = new StripePaymentExecuteEvent($context);

        $this->assertEquals('pi_test_123_secret_abc', $event->getClientSecret());
    }

    public function testGetClientSecretReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([]);

        $event = new StripePaymentExecuteEvent($context);

        $this->assertNull($event->getClientSecret());
    }
}
