<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Event;

use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use PHPUnit\Framework\TestCase;

class StripeRefundRequestEventTest extends TestCase
{
    public function testEventContainsContext(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertSame($context, $event->getContext());
    }

    public function testEventCarriesAllContextData(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'contractId' => 'contract_456',
            'amount' => 50.00,
            'reason' => 'requested_by_customer',
            'description' => 'Customer changed mind',
            'initiator' => 'admin',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('order_123', $event->getOrderId());
        $this->assertEquals('contract_456', $event->getContractId());
        $this->assertEquals(50.00, $event->getAmount());
        $this->assertEquals('requested_by_customer', $event->getReason());
        $this->assertEquals('Customer changed mind', $event->getDescription());
        $this->assertEquals('admin', $event->getInitiator());
    }

    public function testFullRefundHasNullAmount(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'amount' => null,
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getAmount());
        $this->assertTrue($event->isFullRefund());
    }

    public function testPartialRefundHasAmount(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'amount' => 30.00,
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals(30.00, $event->getAmount());
        $this->assertFalse($event->isFullRefund());
    }

    public function testGetOrderIdReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getOrderId());
    }

    public function testGetContractIdReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getContractId());
    }

    public function testGetReasonReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getReason());
    }

    public function testGetDescriptionReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getDescription());
    }

    public function testGetInitiatorDefaultsToAdmin(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('admin', $event->getInitiator());
    }

    public function testGetInitiatorReturnsSetValue(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'initiator' => 'webhook',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('webhook', $event->getInitiator());
    }

    public function testGetChargeIdReturnsValueWhenSet(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'chargeId' => 'ch_test_123',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('ch_test_123', $event->getChargeId());
    }

    public function testGetChargeIdReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getChargeId());
    }

    public function testGetPaymentIntentIdReturnsValueWhenSet(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'paymentIntentId' => 'pi_test_456',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals('pi_test_456', $event->getPaymentIntentId());
    }

    public function testGetPaymentIntentIdReturnsNullWhenNotSet(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getPaymentIntentId());
    }

    public function testAmountConvertedToFloat(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'amount' => '25.50',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertIsFloat($event->getAmount());
        $this->assertEquals(25.50, $event->getAmount());
    }

    public function testAmountReturnsNullForInvalidValue(): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'amount' => 'invalid',
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertNull($event->getAmount());
    }

    /**
     * Test all Stripe-valid refund reasons.
     *
     * @dataProvider validRefundReasonsProvider
     */
    public function testValidRefundReasons(string $reason): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'reason' => $reason,
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals($reason, $event->getReason());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validRefundReasonsProvider(): array
    {
        return [
            'duplicate' => ['duplicate'],
            'fraudulent' => ['fraudulent'],
            'requested_by_customer' => ['requested_by_customer'],
        ];
    }

    /**
     * Test multi-channel initiators.
     *
     * @dataProvider initiatorProvider
     */
    public function testMultiChannelInitiators(string $initiator): void
    {
        $context = new EventContext([
            'orderId' => 'order_123',
            'initiator' => $initiator,
        ]);

        $event = new StripeRefundRequestEvent($context);

        $this->assertEquals($initiator, $event->getInitiator());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function initiatorProvider(): array
    {
        return [
            'admin' => ['admin'],
            'webhook' => ['webhook'],
            'api' => ['api'],
            'mcp' => ['mcp'],
        ];
    }
}
