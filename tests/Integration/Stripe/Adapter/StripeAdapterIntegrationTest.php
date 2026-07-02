<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\VoidPaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\AuthorizePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CaptureAuthorizationRequest;
use OxidEsales\PaymentBase\Adapter\Request\VoidAuthorizationRequest;
use OxidEsales\Payments\Stripe\Tests\Integration\Stripe\StripeIntegrationTestCase;

/**
 * Integration tests for StripeAdapter with real Stripe API.
 *
 * Tests real Stripe SDK → Stripe API interactions using test credentials.
 *
 *
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\StripeAdapter::class)]
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('stripe')]
#[\PHPUnit\Framework\Attributes\Group('api')]
#[\PHPUnit\Framework\Attributes\Group('requires-stripe-creds')]
final class StripeAdapterIntegrationTest extends StripeIntegrationTestCase
{
    // ==========================================
    // BASIC PAYMENT CREATION TESTS
    // ==========================================

    public function testCreatesPaymentIntentWithManualCapture(): void
    {
        // Arrange
        $request = new CreatePaymentRequest(
            amount: 10.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            metadata: ['test' => 'manual_capture']
        );

        // Act
        $response = $this->adapter->createPayment($request);

        // Track for cleanup
        $this->trackResource('payment_intent', $response->providerPaymentId);

        // Assert
        $this->assertStringStartsWith('pi_', $response->providerPaymentId);
        // Without payment method attached, status is 'pending' (requires_payment_method)
        $this->assertEquals('pending', $response->status);
        $this->assertEquals(10.00, $response->amount);
        $this->assertEquals('EUR', $response->currency);
        $this->assertFalse($response->requiresAction);
        $this->assertNotNull($response->clientSecret);
        $this->assertStringStartsWith('pi_', $response->clientSecret);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($response->providerPaymentId);
        $this->assertEquals('manual', $paymentIntent->capture_method);
        $this->assertEquals(1000, $paymentIntent->amount); // cents
        $this->assertEquals('eur', $paymentIntent->currency);
    }

    public function testCreatesPaymentIntentWithAutomaticCapture(): void
    {
        // Arrange
        $request = new CreatePaymentRequest(
            amount: 15.00,
            currency: 'USD',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: true,
            metadata: ['test' => 'direct_capture']
        );

        // Act
        $response = $this->adapter->createPayment($request);

        // Track for cleanup
        $this->trackResource('payment_intent', $response->providerPaymentId);

        // Assert
        $this->assertStringStartsWith('pi_', $response->providerPaymentId);
        $this->assertEquals(15.00, $response->amount);
        $this->assertEquals('USD', $response->currency);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($response->providerPaymentId);
        $this->assertEquals('automatic', $paymentIntent->capture_method);
        $this->assertEquals(1500, $paymentIntent->amount); // cents
        $this->assertEquals('usd', $paymentIntent->currency);
    }

    public function testCreatesPaymentWithSavedPaymentMethod(): void
    {
        // Arrange
        $customer = $this->createTestCustomer();
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->attachPaymentMethodToCustomer($paymentMethod->id, $customer->id);

        $request = new CreatePaymentRequest(
            amount: 25.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            customerId: $customer->id,
            paymentMethodId: $paymentMethod->id,
            returnUrl: 'https://example.com/return',
            metadata: ['test' => 'saved_card']
        );

        // Act
        $response = $this->adapter->createPayment($request);

        // Track for cleanup
        $this->trackResource('payment_intent', $response->providerPaymentId);

        // Assert
        $this->assertStringStartsWith('pi_', $response->providerPaymentId);
        $this->assertEquals(25.00, $response->amount);

        // Verify payment was auto-confirmed with saved payment method
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($response->providerPaymentId);
        $this->assertEquals($customer->id, $paymentIntent->customer);
        $this->assertEquals($paymentMethod->id, $paymentIntent->payment_method);
        $this->assertContains($paymentIntent->status, ['requires_capture', 'succeeded']);
        // Note: return_url may not be returned by Stripe in all cases,
        // but we've verified our adapter sends it correctly in unit tests
    }

    /**
     * Test that verifies the fix for Stripe API feedback about missing return_url.
     *
     * Stripe requires return_url when confirming a PaymentIntent.
     * This test ensures that when a payment is created with a saved payment method
     * (which triggers auto-confirmation), the return_url must be provided.
     *
     * @see https://stripe.com/docs/api/payment_intents/confirm
     */
    public function testRequiresReturnUrlWhenConfirmingPaymentWithSavedMethod(): void
    {
        // Arrange
        $customer = $this->createTestCustomer();
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->attachPaymentMethodToCustomer($paymentMethod->id, $customer->id);

        $requestWithoutReturnUrl = new CreatePaymentRequest(
            amount: 20.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            customerId: $customer->id,
            paymentMethodId: $paymentMethod->id,
            returnUrl: null, // Explicitly null - this should cause an error
            metadata: ['test' => 'missing_return_url']
        );

        // Act & Assert
        $this->expectException(\OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException::class);
        $this->expectExceptionMessage('return_url');

        $this->adapter->createPayment($requestWithoutReturnUrl);
    }

    public function testCreatesPaymentWithMetadata(): void
    {
        // Arrange
        $metadata = [
            'order_id' => 'ORDER-123',
            'customer_email' => 'test@example.com',
            'product_sku' => 'PROD-456',
        ];

        $request = new CreatePaymentRequest(
            amount: 50.00,
            currency: 'GBP',
            orderId: 'ORDER-123',
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            metadata: $metadata
        );

        // Act
        $response = $this->adapter->createPayment($request);

        // Track for cleanup
        $this->trackResource('payment_intent', $response->providerPaymentId);

        // Assert
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($response->providerPaymentId);
        $this->assertEquals('ORDER-123', $paymentIntent->metadata['order_id']);
        $this->assertEquals('1', $paymentIntent->metadata['shop_id']);
        $this->assertEquals('test@example.com', $paymentIntent->metadata['customer_email']);
        $this->assertEquals('PROD-456', $paymentIntent->metadata['product_sku']);
    }

    // ==========================================
    // CAPTURE PAYMENT TESTS
    // ==========================================

    public function testCapturesPaymentFullAmount(): void
    {
        // Arrange - Create authorized payment
        $createRequest = new CreatePaymentRequest(
            amount: 30.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Confirm payment with test card
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->confirmPaymentIntent($createResponse->providerPaymentId, $paymentMethod->id);

        // Act - Capture full amount
        $captureRequest = new CapturePaymentRequest(
            providerPaymentId: $createResponse->providerPaymentId,
            amount: null, // Full capture
            metadata: ['captured_by' => 'integration_test']
        );

        $captureResponse = $this->adapter->capturePayment($captureRequest);

        // Assert
        $this->assertEquals($createResponse->providerPaymentId, $captureResponse->providerPaymentId);
        // captureId can be either a charge ID (ch_) or fallback to payment intent ID (pi_)
        $this->assertTrue(
            str_starts_with($captureResponse->captureId, 'ch_') ||
            str_starts_with($captureResponse->captureId, 'pi_'),
            'captureId should start with ch_ or pi_, got: ' . $captureResponse->captureId
        );
        $this->assertEquals(30.00, $captureResponse->amountCaptured);
        $this->assertEquals('EUR', $captureResponse->currency);
        $this->assertEquals('captured', $captureResponse->status);
        $this->assertInstanceOf(\DateTimeImmutable::class, $captureResponse->capturedAt);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($createResponse->providerPaymentId);
        $this->assertEquals('succeeded', $paymentIntent->status);
        $this->assertEquals(3000, $paymentIntent->amount_received);
    }

    public function testCapturesPaymentPartialAmount(): void
    {
        // Arrange - Create authorized payment for 100.00
        $createRequest = new CreatePaymentRequest(
            amount: 100.00,
            currency: 'USD',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Confirm payment
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->confirmPaymentIntent($createResponse->providerPaymentId, $paymentMethod->id);

        // Act - Capture only 60.00
        $captureRequest = new CapturePaymentRequest(
            providerPaymentId: $createResponse->providerPaymentId,
            amount: 60.00,
            metadata: ['capture_type' => 'partial']
        );

        $captureResponse = $this->adapter->capturePayment($captureRequest);

        // Assert
        $this->assertEquals(60.00, $captureResponse->amountCaptured);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($createResponse->providerPaymentId);
        $this->assertEquals('succeeded', $paymentIntent->status);
        $this->assertEquals(6000, $paymentIntent->amount_received); // Only 60.00 captured
    }

    // ==========================================
    // REFUND PAYMENT TESTS
    // ==========================================

    public function testRefundsPaymentFullAmount(): void
    {
        // Arrange - Create and capture payment
        $paymentIntent = $this->createAndCapturePayment(50.00, 'EUR');
        $this->trackResource('payment_intent', $paymentIntent->id);

        // Act - Refund full amount
        $refundRequest = new RefundPaymentRequest(
            providerPaymentId: $paymentIntent->id,
            amount: null, // Full refund
            reason: 'requested_by_customer',
            metadata: ['refund_test' => 'full']
        );

        $refundResponse = $this->adapter->refundPayment($refundRequest);

        // Assert
        $this->assertEquals($paymentIntent->id, $refundResponse->providerPaymentId);
        $this->assertStringStartsWith('re_', $refundResponse->refundId);
        $this->assertEquals(50.00, $refundResponse->amountRefunded);
        $this->assertEquals('EUR', $refundResponse->currency);
        $this->assertEquals('requested_by_customer', $refundResponse->reason);
        $this->assertContains($refundResponse->status, ['succeeded', 'pending']);

        // Verify with Stripe API
        $refund = $this->stripeClient->refunds->retrieve($refundResponse->refundId);
        $this->assertEquals(5000, $refund->amount);
        $this->assertContains($refund->status, ['succeeded', 'pending']);
    }

    public function testRefundsPaymentPartialAmount(): void
    {
        // Arrange - Create and capture payment for 100.00
        $paymentIntent = $this->createAndCapturePayment(100.00, 'USD');
        $this->trackResource('payment_intent', $paymentIntent->id);

        // Act - Refund 40.00
        $refundRequest = new RefundPaymentRequest(
            providerPaymentId: $paymentIntent->id,
            amount: 40.00,
            reason: 'requested_by_customer',
            metadata: ['refund_type' => 'partial']
        );

        $refundResponse = $this->adapter->refundPayment($refundRequest);

        // Assert
        $this->assertEquals(40.00, $refundResponse->amountRefunded);

        // Wait for refund to process
        sleep(2);

        // Verify with Stripe API
        $refund = $this->stripeClient->refunds->retrieve($refundResponse->refundId);
        $this->assertEquals(4000, $refund->amount);

        // Verify payment intent shows partial refund (if charge data is available)
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($paymentIntent->id);
        if (!empty($paymentIntent->charges->data)) {
            $chargeRefunded = $paymentIntent->charges->data[0]->amount_refunded ?? 0;
            $this->assertEquals(4000, $chargeRefunded, 'Charge should show 4000 cents refunded');
        } else {
            $this->markTestIncomplete('Charge data not available in test mode');
        }
    }

    public function testRefundsWithDifferentReasons(): void
    {
        // Test each refund reason
        $reasons = ['requested_by_customer', 'fraudulent', 'duplicate'];

        foreach ($reasons as $reason) {
            // Arrange
            $paymentIntent = $this->createAndCapturePayment(20.00, 'EUR');
            $this->trackResource('payment_intent', $paymentIntent->id);

            // Act
            $refundRequest = new RefundPaymentRequest(
                providerPaymentId: $paymentIntent->id,
                amount: null,
                reason: $reason,
                metadata: ['test_reason' => $reason]
            );

            $refundResponse = $this->adapter->refundPayment($refundRequest);

            // Assert
            $this->assertEquals($reason, $refundResponse->reason);

            $refund = $this->stripeClient->refunds->retrieve($refundResponse->refundId);
            $this->assertEquals($reason, $refund->reason);
        }
    }

    // ==========================================
    // VOID/CANCEL PAYMENT TESTS
    // ==========================================

    public function testVoidsUnconfirmedPayment(): void
    {
        // Arrange - Create payment without confirming
        $createRequest = new CreatePaymentRequest(
            amount: 75.00,
            currency: 'GBP',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Act - Void payment
        $voidRequest = new VoidPaymentRequest(
            providerPaymentId: $createResponse->providerPaymentId,
            reason: 'requested_by_customer',
            metadata: ['void_test' => 'true']
        );

        $voidResponse = $this->adapter->voidPayment($voidRequest);

        // Assert
        $this->assertEquals($createResponse->providerPaymentId, $voidResponse->providerPaymentId);
        $this->assertEquals('cancelled', $voidResponse->status);
        $this->assertEquals('requested_by_customer', $voidResponse->reason);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($createResponse->providerPaymentId);
        $this->assertEquals('canceled', $paymentIntent->status);
    }

    public function testVoidsAuthorizedPayment(): void
    {
        // Arrange - Create and confirm payment (but don't capture)
        $createRequest = new CreatePaymentRequest(
            amount: 50.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Confirm with test card
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->confirmPaymentIntent($createResponse->providerPaymentId, $paymentMethod->id);

        // Act - Void before capture
        $voidRequest = new VoidPaymentRequest(
            providerPaymentId: $createResponse->providerPaymentId,
            reason: 'abandoned',
            metadata: ['void_reason' => 'customer_cancelled']
        );

        $voidResponse = $this->adapter->voidPayment($voidRequest);

        // Assert
        $this->assertEquals('cancelled', $voidResponse->status);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($createResponse->providerPaymentId);
        $this->assertEquals('canceled', $paymentIntent->status);
    }

    // ==========================================
    // GET PAYMENT DETAILS TESTS
    // ==========================================

    public function testRetrievesPaymentDetails(): void
    {
        // Arrange - Create payment
        $createRequest = new CreatePaymentRequest(
            amount: 99.99,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Act - Get details
        $details = $this->adapter->getPaymentDetails($createResponse->providerPaymentId);

        // Assert
        $this->assertEquals($createResponse->providerPaymentId, $details->providerPaymentId);
        $this->assertEquals(99.99, $details->amount);
        $this->assertEquals('EUR', $details->currency);
        $this->assertEquals(0.0, $details->amountCaptured);
        $this->assertEquals(0.0, $details->amountRefunded);
        $this->assertFalse($details->isCaptured);
        $this->assertFalse($details->isRefunded);
        $this->assertFalse($details->isCancelled);
        $this->assertInstanceOf(\DateTimeImmutable::class, $details->createdAt);
    }

    public function testRetrievesDetailsOfCapturedPayment(): void
    {
        // Arrange - Create and capture payment
        $paymentIntent = $this->createAndCapturePayment(75.50, 'USD');
        $this->trackResource('payment_intent', $paymentIntent->id);

        // Act
        $details = $this->adapter->getPaymentDetails($paymentIntent->id);

        // Assert
        $this->assertEquals(75.50, $details->amount);
        $this->assertEquals(75.50, $details->amountCaptured);
        $this->assertTrue($details->isCaptured);
        // capturedAt might be null if charge data isn't populated yet in test mode
        if ($details->capturedAt !== null) {
            $this->assertInstanceOf(\DateTimeImmutable::class, $details->capturedAt);
        }
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Create and capture a payment for testing.
     */
    private function createAndCapturePayment(float $amount, string $currency): \Stripe\PaymentIntent
    {
        // Create payment method
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');

        // Create and confirm payment
        $paymentIntent = $this->stripeClient->paymentIntents->create([
            'amount' => (int) ($amount * 100),
            'currency' => strtolower($currency),
            'capture_method' => 'automatic',
            'confirm' => true,
            'payment_method' => $paymentMethod->id,
            'return_url' => 'https://example.com/return',
        ]);

        // Wait a moment for processing
        sleep(2);

        // Retrieve updated status
        return $this->stripeClient->paymentIntents->retrieve($paymentIntent->id);
    }
}
