<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter;

use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
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

/**
 * Unit tests for StripeAdapter Stripe-specific methods.
 *
 * Sprint 19: Route Stripe SDK calls through adapter
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\StripeAdapter::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-19')]
#[\PHPUnit\Framework\Attributes\Group('adapter')]
final class StripeAdapterTest extends TestCase
{
    private StripeClient&MockObject $stripeClient;
    private StripeAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeClient = $this->createMock(StripeClient::class);
        $idempotencyRepository = $this->createMock(IdempotencyRepositoryInterface::class);
        $this->adapter = new StripeAdapter(
            $this->stripeClient,
            new PaymentIntentHelper($idempotencyRepository),
            new RefundHelper($idempotencyRepository)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function implementsStripeAdapterInterface(): void
    {
        $this->assertInstanceOf(StripeAdapterInterface::class, $this->adapter);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function retrievesCheckoutSession(): void
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

        $this->assertInstanceOf(StripeCheckoutSessionDto::class, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function retrievesCheckoutSessionWithDefaultExpand(): void
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

        $this->assertInstanceOf(StripeCheckoutSessionDto::class, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createsCheckoutSession(): void
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

        $this->assertInstanceOf(StripeCheckoutSessionDto::class, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function retrievesPaymentIntent(): void
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

        $this->assertInstanceOf(StripePaymentIntentDto::class, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function retrievesPaymentIntentWithExpand(): void
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

        $this->assertInstanceOf(StripePaymentIntentDto::class, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createsRefundByChargeId(): void
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

        $this->assertInstanceOf(StripeRefundDto::class, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createsFullRefundByChargeId(): void
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

        $this->assertInstanceOf(StripeRefundDto::class, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function createsRefundWithMetadata(): void
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

        $this->assertInstanceOf(StripeRefundDto::class, $result);
    }
}
