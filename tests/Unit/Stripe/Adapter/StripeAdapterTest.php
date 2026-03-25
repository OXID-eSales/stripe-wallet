<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter;

use OxidEsales\Payments\Stripe\Adapter\StripeAdapter;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\PaymentIntentService;
use Stripe\Service\RefundService;
use Stripe\StripeClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for StripeAdapter Stripe-specific methods.
 *
 * Sprint 19: Route Stripe SDK calls through adapter
 *
 */
#[CoversClass(\OxidEsales\Payments\Stripe\Adapter\StripeAdapter::class)]
    #[Group('sprint-19')]
    #[Group('adapter')]
final class StripeAdapterTest extends TestCase
{
    private StripeClient&MockObject $stripeClient;
    private StripeAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->adapter = new StripeAdapter($this->stripeClient);
    }

    /**
     * Sprint 19: Adapter should implement StripeAdapterInterface
     */
    public function testImplementsStripeAdapterInterface(): void
    {
        $this->assertInstanceOf(StripeAdapterInterface::class, $this->adapter);
    }

    /**
     * Sprint 19: Retrieves checkout session through adapter
     */
    public function testRetrievesCheckoutSession(): void
    {
        $sessionId = 'cs_test_123';
        $expectedSession = $this->createMock(Session::class);

        $sessionService = $this->createMock(SessionService::class);
        $sessionService->expects($this->once())
            ->method('retrieve')
            ->with($sessionId, ['expand' => ['payment_intent']])
            ->willReturn($expectedSession);

        $checkoutService = $this->createMock(CheckoutServiceFactory::class);
        $checkoutService->sessions = $sessionService;

        $this->stripeClient->checkout = $checkoutService;

        $result = $this->adapter->retrieveCheckoutSession($sessionId, ['payment_intent']);

        $this->assertSame($expectedSession, $result);
    }

    /**
     * Sprint 19: Retrieves checkout session with default expand
     */
    public function testRetrievesCheckoutSessionWithDefaultExpand(): void
    {
        $sessionId = 'cs_test_456';
        $expectedSession = $this->createMock(Session::class);

        $sessionService = $this->createMock(SessionService::class);
        $sessionService->expects($this->once())
            ->method('retrieve')
            ->with($sessionId, [])
            ->willReturn($expectedSession);

        $checkoutService = $this->createMock(CheckoutServiceFactory::class);
        $checkoutService->sessions = $sessionService;

        $this->stripeClient->checkout = $checkoutService;

        $result = $this->adapter->retrieveCheckoutSession($sessionId);

        $this->assertSame($expectedSession, $result);
    }

    /**
     * Sprint 19: Creates checkout session through adapter
     */
    public function testCreatesCheckoutSession(): void
    {
        $params = [
            'mode' => 'payment',
            'line_items' => [
                ['price_data' => ['currency' => 'eur', 'unit_amount' => 1000], 'quantity' => 1],
            ],
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ];

        $expectedSession = $this->createMock(Session::class);

        $sessionService = $this->createMock(SessionService::class);
        $sessionService->expects($this->once())
            ->method('create')
            ->with($params)
            ->willReturn($expectedSession);

        $checkoutService = $this->createMock(CheckoutServiceFactory::class);
        $checkoutService->sessions = $sessionService;

        $this->stripeClient->checkout = $checkoutService;

        $result = $this->adapter->createCheckoutSession($params);

        $this->assertSame($expectedSession, $result);
    }

    /**
     * Sprint 19: Retrieves payment intent through adapter
     */
    public function testRetrievesPaymentIntent(): void
    {
        $paymentIntentId = 'pi_test_123';
        $expectedIntent = $this->createMock(PaymentIntent::class);

        $paymentIntentService = $this->createMock(PaymentIntentService::class);
        $paymentIntentService->expects($this->once())
            ->method('retrieve')
            ->with($paymentIntentId, [])
            ->willReturn($expectedIntent);

        $this->stripeClient->paymentIntents = $paymentIntentService;

        $result = $this->adapter->retrievePaymentIntent($paymentIntentId);

        $this->assertSame($expectedIntent, $result);
    }

    /**
     * Sprint 19: Retrieves payment intent with expand options
     */
    public function testRetrievesPaymentIntentWithExpand(): void
    {
        $paymentIntentId = 'pi_test_789';
        $expectedIntent = $this->createMock(PaymentIntent::class);

        $paymentIntentService = $this->createMock(PaymentIntentService::class);
        $paymentIntentService->expects($this->once())
            ->method('retrieve')
            ->with($paymentIntentId, ['expand' => ['latest_charge']])
            ->willReturn($expectedIntent);

        $this->stripeClient->paymentIntents = $paymentIntentService;

        $result = $this->adapter->retrievePaymentIntent($paymentIntentId, ['latest_charge']);

        $this->assertSame($expectedIntent, $result);
    }

    /**
     * Sprint 19: Creates refund by charge ID through adapter
     */
    public function testCreatesRefundByChargeId(): void
    {
        $chargeId = 'ch_test_123';
        $amount = 1000;
        $reason = 'requested_by_customer';

        $expectedRefund = $this->createMock(Refund::class);

        $refundService = $this->createMock(RefundService::class);
        $refundService->expects($this->once())
            ->method('create')
            ->with([
                'charge' => $chargeId,
                'amount' => $amount,
                'reason' => $reason,
            ])
            ->willReturn($expectedRefund);

        $this->stripeClient->refunds = $refundService;

        $result = $this->adapter->createRefundByCharge($chargeId, $amount, $reason);

        $this->assertSame($expectedRefund, $result);
    }

    /**
     * Sprint 19: Creates full refund by charge ID (no amount specified)
     */
    public function testCreatesFullRefundByChargeId(): void
    {
        $chargeId = 'ch_test_456';

        $expectedRefund = $this->createMock(Refund::class);

        $refundService = $this->createMock(RefundService::class);
        $refundService->expects($this->once())
            ->method('create')
            ->with(['charge' => $chargeId])
            ->willReturn($expectedRefund);

        $this->stripeClient->refunds = $refundService;

        $result = $this->adapter->createRefundByCharge($chargeId);

        $this->assertSame($expectedRefund, $result);
    }

    /**
     * Sprint 19: Creates refund with metadata through adapter
     */
    public function testCreatesRefundWithMetadata(): void
    {
        $chargeId = 'ch_test_789';
        $amount = 500;
        /** @var array<string, string> $metadata */
        $metadata = ['order_id' => 'order_123', 'initiator' => 'admin'];

        $expectedRefund = $this->createMock(Refund::class);

        $refundService = $this->createMock(RefundService::class);
        $refundService->expects($this->once())
            ->method('create')
            ->with([
                'charge' => $chargeId,
                'amount' => $amount,
                'metadata' => $metadata,
            ])
            ->willReturn($expectedRefund);

        $this->stripeClient->refunds = $refundService;

        $result = $this->adapter->createRefundByCharge($chargeId, $amount, null, $metadata);

        $this->assertSame($expectedRefund, $result);
    }
}
