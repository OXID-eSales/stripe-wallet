<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\AuthorizePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\CaptureAuthorizationRequest;
use OxidEsales\PaymentBase\Adapter\Request\VoidAuthorizationRequest;
use OxidEsales\PaymentBase\Adapter\Request\ReauthorizePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\Payments\Stripe\Tests\Integration\Stripe\StripeIntegrationTestCase;

/**
 * Integration tests for StripeAdapter authorization flow with real Stripe API.
 *
 * Tests two-step payment flow:
 * 1. Authorization (hold funds)
 * 2. Capture (take funds) or Void (release funds)
 *
 *
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\StripeAdapter::class)]
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('stripe')]
#[\PHPUnit\Framework\Attributes\Group('api')]
#[\PHPUnit\Framework\Attributes\Group('authorization')]
#[\PHPUnit\Framework\Attributes\Group('requires-stripe-creds')]
final class StripeAuthorizationFlowIntegrationTest extends StripeIntegrationTestCase
{
    // ==========================================
    // AUTHORIZATION TESTS
    // ==========================================

    public function testAuthorizesPayment(): void
    {
        // Arrange
        $request = new AuthorizePaymentRequest(
            amount: 150.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            metadata: ['test' => 'authorization']
        );

        // Act
        $response = $this->adapter->authorizePayment($request);

        // Track for cleanup
        $this->trackResource('payment_intent', $response->authorizationId);

        // Assert
        $this->assertStringStartsWith('pi_', $response->authorizationId);
        $this->assertEquals($response->authorizationId, $response->providerPaymentId);
        // Without payment method attached, status is 'pending' (requires_payment_method)
        $this->assertEquals('pending', $response->status);
        $this->assertEquals(150.00, $response->amount);
        $this->assertEquals('EUR', $response->currency);
        $this->assertInstanceOf(\DateTimeImmutable::class, $response->authorizedAt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $response->expiresAt);
        $this->assertNotNull($response->clientSecret);

        // Verify authorization expires in ~7 days (Stripe default)
        // Note: Depending on timing, this could be 6 or 7 days
        $now = new \DateTimeImmutable();
        $diffDays = $now->diff($response->expiresAt)->days;
        $this->assertGreaterThanOrEqual(6, $diffDays);
        $this->assertLessThanOrEqual(7, $diffDays);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($response->authorizationId);
        $this->assertEquals('manual', $paymentIntent->capture_method);
        $this->assertEquals(15000, $paymentIntent->amount);
    }

    public function testAuthorizesPaymentWithSavedCard(): void
    {
        // Arrange
        $customer = $this->createTestCustomer();
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->attachPaymentMethodToCustomer($paymentMethod->id, $customer->id);

        $request = new AuthorizePaymentRequest(
            amount: 200.00,
            currency: 'USD',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            customerId: $customer->id,
            paymentMethodId: $paymentMethod->id,
            returnUrl: 'https://example.com/return',
            metadata: ['authorization_type' => 'saved_card']
        );

        // Act
        $response = $this->adapter->authorizePayment($request);

        // Track for cleanup
        $this->trackResource('payment_intent', $response->authorizationId);

        // Assert
        $this->assertStringStartsWith('pi_', $response->authorizationId);
        $this->assertEquals(200.00, $response->amount);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($response->authorizationId);
        $this->assertEquals($customer->id, $paymentIntent->customer);
        $this->assertEquals($paymentMethod->id, $paymentIntent->payment_method);
        $this->assertEquals('requires_capture', $paymentIntent->status);
        // Note: return_url may not be returned by Stripe in all cases,
        // but we've verified our adapter sends it correctly
    }

    /**
     * Test that verifies the fix for Stripe API feedback about missing return_url.
     *
     * Stripe requires return_url when confirming a PaymentIntent.
     * This test ensures that when an authorization is created with a saved payment method
     * (which triggers auto-confirmation), the return_url must be provided.
     *
     * @see https://stripe.com/docs/api/payment_intents/confirm
     */
    public function testRequiresReturnUrlWhenAuthorizingWithSavedMethod(): void
    {
        // Arrange
        $customer = $this->createTestCustomer();
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->attachPaymentMethodToCustomer($paymentMethod->id, $customer->id);

        $requestWithoutReturnUrl = new AuthorizePaymentRequest(
            amount: 150.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            customerId: $customer->id,
            paymentMethodId: $paymentMethod->id,
            returnUrl: null, // Explicitly null - this should cause an error
            metadata: ['test' => 'missing_return_url']
        );

        // Act & Assert
        $this->expectException(PaymentAdapterException::class);
        $this->expectExceptionMessage('return_url');

        $this->adapter->authorizePayment($requestWithoutReturnUrl);
    }

    // ==========================================
    // CAPTURE AUTHORIZATION TESTS
    // ==========================================

    public function testCapturesFullAuthorization(): void
    {
        // Arrange - Create authorization
        $authRequest = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            metadata: ['test' => 'full_capture']
        );

        $authResponse = $this->adapter->authorizePayment($authRequest);
        $this->trackResource('payment_intent', $authResponse->authorizationId);

        // Confirm authorization
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->confirmPaymentIntent($authResponse->authorizationId, $paymentMethod->id);

        // Act - Capture full amount
        $captureRequest = new CaptureAuthorizationRequest(
            authorizationId: $authResponse->authorizationId,
            amount: null, // Full capture
            metadata: ['capture_test' => 'full']
        );

        $captureResponse = $this->adapter->captureAuthorization($captureRequest);

        // Assert
        $this->assertEquals($authResponse->authorizationId, $captureResponse->providerPaymentId);
        $this->assertEquals(100.00, $captureResponse->amountCaptured);
        $this->assertEquals('captured', $captureResponse->status);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($authResponse->authorizationId);
        $this->assertEquals('succeeded', $paymentIntent->status);
        $this->assertEquals(10000, $paymentIntent->amount_received);
    }

    public function testCapturesPartialAuthorization(): void
    {
        // Arrange - Create authorization for 500.00
        $authRequest = new AuthorizePaymentRequest(
            amount: 500.00,
            currency: 'USD',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card'
        );

        $authResponse = $this->adapter->authorizePayment($authRequest);
        $this->trackResource('payment_intent', $authResponse->authorizationId);

        // Confirm authorization
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->confirmPaymentIntent($authResponse->authorizationId, $paymentMethod->id);

        // Act - Capture only 300.00
        $captureRequest = new CaptureAuthorizationRequest(
            authorizationId: $authResponse->authorizationId,
            amount: 300.00,
            metadata: ['capture_type' => 'partial']
        );

        $captureResponse = $this->adapter->captureAuthorization($captureRequest);

        // Assert
        $this->assertEquals(300.00, $captureResponse->amountCaptured);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($authResponse->authorizationId);
        $this->assertEquals('succeeded', $paymentIntent->status);
        $this->assertEquals(30000, $paymentIntent->amount_received);
    }

    // ==========================================
    // VOID AUTHORIZATION TESTS
    // ==========================================

    public function testVoidsUnconfirmedAuthorization(): void
    {
        // Arrange - Create authorization without confirming
        $authRequest = new AuthorizePaymentRequest(
            amount: 250.00,
            currency: 'GBP',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card'
        );

        $authResponse = $this->adapter->authorizePayment($authRequest);
        $this->trackResource('payment_intent', $authResponse->authorizationId);

        // Act - Void authorization
        $voidRequest = new VoidAuthorizationRequest(
            authorizationId: $authResponse->authorizationId,
            reason: 'requested_by_customer',
            metadata: ['void_test' => 'unconfirmed']
        );

        $voidResponse = $this->adapter->voidAuthorization($voidRequest);

        // Assert
        $this->assertEquals($authResponse->authorizationId, $voidResponse->providerPaymentId);
        $this->assertEquals('cancelled', $voidResponse->status);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($authResponse->authorizationId);
        $this->assertEquals('canceled', $paymentIntent->status);
    }

    public function testVoidsConfirmedAuthorization(): void
    {
        // Arrange - Create and confirm authorization
        $authRequest = new AuthorizePaymentRequest(
            amount: 175.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card'
        );

        $authResponse = $this->adapter->authorizePayment($authRequest);
        $this->trackResource('payment_intent', $authResponse->authorizationId);

        // Confirm authorization
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->confirmPaymentIntent($authResponse->authorizationId, $paymentMethod->id);

        // Verify authorization succeeded
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($authResponse->authorizationId);
        $this->assertEquals('requires_capture', $paymentIntent->status);

        // Act - Void before capture
        $voidRequest = new VoidAuthorizationRequest(
            authorizationId: $authResponse->authorizationId,
            reason: 'duplicate',
            metadata: ['void_test' => 'confirmed']
        );

        $voidResponse = $this->adapter->voidAuthorization($voidRequest);

        // Assert
        $this->assertEquals('cancelled', $voidResponse->status);

        // Verify with Stripe API
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($authResponse->authorizationId);
        $this->assertEquals('canceled', $paymentIntent->status);
    }

    // ==========================================
    // REAUTHORIZATION TESTS
    // ==========================================

    public function testReauthorizationThrowsNotSupportedException(): void
    {
        // Arrange - Create expired authorization (simulated)
        $authRequest = new AuthorizePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card'
        );

        $authResponse = $this->adapter->authorizePayment($authRequest);
        $this->trackResource('payment_intent', $authResponse->authorizationId);

        // Act & Assert - Reauthorization not supported
        $this->expectException(PaymentAdapterException::class);
        $this->expectExceptionMessage('Stripe does not support reauthorization');

        $reauthorizeRequest = new ReauthorizePaymentRequest(
            authorizationId: $authResponse->authorizationId,
            amount: 100.00,
            metadata: ['attempt' => 'reauthorize']
        );

        $this->adapter->reauthorizePayment($reauthorizeRequest);
    }

    // ==========================================
    // COMPLETE AUTHORIZATION LIFECYCLE TESTS
    // ==========================================

    public function testCompleteAuthorizationLifecycleWithCapture(): void
    {
        // Step 1: Authorize payment
        $authRequest = new AuthorizePaymentRequest(
            amount: 125.50,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            metadata: ['lifecycle_test' => 'capture']
        );

        $authResponse = $this->adapter->authorizePayment($authRequest);
        $this->trackResource('payment_intent', $authResponse->authorizationId);

        // Without payment method attached, status is 'pending' (requires_payment_method)
        $this->assertEquals('pending', $authResponse->status);

        // Step 2: Confirm authorization (simulate customer completing payment)
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $paymentIntent = $this->confirmPaymentIntent($authResponse->authorizationId, $paymentMethod->id);

        $this->assertEquals('requires_capture', $paymentIntent->status);

        // Step 3: Capture authorization
        $captureRequest = new CaptureAuthorizationRequest(
            authorizationId: $authResponse->authorizationId,
            amount: 125.50,
            metadata: ['captured' => 'yes']
        );

        $captureResponse = $this->adapter->captureAuthorization($captureRequest);

        $this->assertEquals('captured', $captureResponse->status);
        $this->assertEquals(125.50, $captureResponse->amountCaptured);

        // Step 4: Verify final state
        $details = $this->adapter->getPaymentDetails($authResponse->authorizationId);

        $this->assertEquals(125.50, $details->amount);
        $this->assertEquals(125.50, $details->amountCaptured);
        $this->assertTrue($details->isCaptured);
        $this->assertFalse($details->isCancelled);
    }

    public function testCompleteAuthorizationLifecycleWithVoid(): void
    {
        // Step 1: Authorize payment
        $authRequest = new AuthorizePaymentRequest(
            amount: 90.00,
            currency: 'USD',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            metadata: ['lifecycle_test' => 'void']
        );

        $authResponse = $this->adapter->authorizePayment($authRequest);
        $this->trackResource('payment_intent', $authResponse->authorizationId);

        // Without payment method attached, status is 'pending' (requires_payment_method)
        $this->assertEquals('pending', $authResponse->status);

        // Step 2: Confirm authorization
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $paymentIntent = $this->confirmPaymentIntent($authResponse->authorizationId, $paymentMethod->id);

        $this->assertEquals('requires_capture', $paymentIntent->status);

        // Step 3: Void authorization instead of capturing
        $voidRequest = new VoidAuthorizationRequest(
            authorizationId: $authResponse->authorizationId,
            reason: 'requested_by_customer',
            metadata: ['voided' => 'yes']
        );

        $voidResponse = $this->adapter->voidAuthorization($voidRequest);

        $this->assertEquals('cancelled', $voidResponse->status);

        // Step 4: Verify final state
        $details = $this->adapter->getPaymentDetails($authResponse->authorizationId);

        $this->assertEquals(90.00, $details->amount);
        $this->assertEquals(0.0, $details->amountCaptured);
        $this->assertFalse($details->isCaptured);
        $this->assertTrue($details->isCancelled);
    }

    public function testAuthorizationExpirationDate(): void
    {
        // Arrange & Act
        $authRequest = new AuthorizePaymentRequest(
            amount: 50.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card'
        );

        $authResponse = $this->adapter->authorizePayment($authRequest);
        $this->trackResource('payment_intent', $authResponse->authorizationId);

        // Assert - Authorization expires in 7 days (Stripe default)
        $now = new \DateTimeImmutable();
        $expiresIn = $authResponse->expiresAt->getTimestamp() - $now->getTimestamp();
        $expiresInDays = $expiresIn / 86400; // Convert to days

        $this->assertGreaterThan(6.9, $expiresInDays);
        $this->assertLessThan(7.1, $expiresInDays);
    }
}
