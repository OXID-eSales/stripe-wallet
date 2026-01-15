<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Event;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

class StripeCheckoutReturnEventTest extends TestCase
{
    public function testEventContainsContext(): void
    {
        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_123',
        ]);

        $event = new StripeCheckoutReturnEvent($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testEventContainsCheckoutSessionId(): void
    {
        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_abc123',
        ]);

        $event = new StripeCheckoutReturnEvent($context);

        $this->assertEquals('cs_test_abc123', $event->getContext()->get('checkoutSessionId'));
    }

    public function testEventContainsContractId(): void
    {
        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_123',
            'contractId' => 'contract_xyz',
        ]);

        $event = new StripeCheckoutReturnEvent($context);

        $this->assertEquals('contract_xyz', $event->getContext()->get('contractId'));
    }

    public function testGetCheckoutSessionIdMethod(): void
    {
        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_method',
        ]);

        $event = new StripeCheckoutReturnEvent($context);

        $this->assertEquals('cs_test_method', $event->getCheckoutSessionId());
    }

    public function testGetCheckoutSessionIdReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([]);

        $event = new StripeCheckoutReturnEvent($context);

        $this->assertNull($event->getCheckoutSessionId());
    }
}
