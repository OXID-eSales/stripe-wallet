<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Adapter;

use OxidEsales\PaymentComponent\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentComponent\Adapter\Request\ThreeDSecureRequest;
use OxidEsales\Payments\Stripe\Tests\Integration\Stripe\StripeIntegrationTestCase;

/**
 * Integration tests for StripeAdapter 3D Secure (SCA) flow with real Stripe API.
 *
 * Tests Strong Customer Authentication:
 * - 3DS initiation
 * - 3DS verification
 * - 3DS status mapping
 *
 * Note: Full 3DS flow requires browser automation.
 * These tests verify the API-level integration.
 *
 * @group integration
 * @group stripe
 * @group api
 * @group 3ds
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\StripeAdapter
 */
final class Stripe3DSecureIntegrationTest extends StripeIntegrationTestCase
{
    // ==========================================
    // 3D SECURE INITIATION TESTS
    // ==========================================

    public function testInitiates3DSecureForPaymentRequiringAction(): void
    {
        // Arrange - Create payment that will require 3DS
        // Note: In test mode, we can't force 3DS, but we can test the flow
        // Note: return_url can only be used when confirm=true, so we omit it here
        $createRequest = new CreatePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            metadata: ['test_3ds' => 'initiation']
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Act - Initiate 3DS check
        $threeDSRequest = new ThreeDSecureRequest(
            paymentId: $createResponse->providerPaymentId
        );

        $threeDSResponse = $this->adapter->initiate3DSecure($threeDSRequest);

        // Assert
        $this->assertEquals($createResponse->providerPaymentId, $threeDSResponse->paymentId);
        $this->assertEquals($createResponse->providerPaymentId, $threeDSResponse->authenticationId);
        $this->assertIsString($threeDSResponse->status);
        $this->assertContains($threeDSResponse->status, [
            'authenticated',
            'pending',
            'not_required',
            'failed'
        ]);

        // For a payment without confirmation, 3DS should not be required yet
        $this->assertFalse($threeDSResponse->authenticated);
    }

    public function testInitiates3DSecureForConfirmedPayment(): void
    {
        // Arrange - Create and confirm payment
        // Note: return_url can only be used when confirm=true, so we omit it here
        $createRequest = new CreatePaymentRequest(
            amount: 150.00,
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
        $paymentIntent = $this->confirmPaymentIntent($createResponse->providerPaymentId, $paymentMethod->id);

        // Act - Check 3DS status after confirmation
        $threeDSRequest = new ThreeDSecureRequest(
            paymentId: $createResponse->providerPaymentId
        );

        $threeDSResponse = $this->adapter->initiate3DSecure($threeDSRequest);

        // Assert - For successful card, should be authenticated or not required
        $this->assertEquals($createResponse->providerPaymentId, $threeDSResponse->paymentId);
        $this->assertContains($threeDSResponse->status, ['authenticated', 'not_required']);

        // Payment should be in requires_capture state (successful authorization)
        if ($paymentIntent->status === 'requires_capture') {
            $this->assertTrue($threeDSResponse->authenticated);
        }
    }

    public function testReturnsRedirectUrlWhen3DSRequired(): void
    {
        // Arrange - Try to create a payment scenario where redirect might be needed
        // In test mode with automatic confirmation, this is limited
        // Note: return_url can only be used when confirm=true, so we omit it here
        $createRequest = new CreatePaymentRequest(
            amount: 200.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Act
        $threeDSRequest = new ThreeDSecureRequest(
            paymentId: $createResponse->providerPaymentId
        );

        $threeDSResponse = $this->adapter->initiate3DSecure($threeDSRequest);

        // Assert - Check redirect URL structure
        // For unconfirmed payment, there should be no redirect URL yet
        $this->assertNotNull($threeDSResponse);
        if ($threeDSResponse->redirectUrl !== null) {
            $this->assertStringContainsString('stripe.com', $threeDSResponse->redirectUrl);
        }
    }

    // ==========================================
    // 3D SECURE VERIFICATION TESTS
    // ==========================================

    public function testVerifies3DSecureResultForSuccessfulPayment(): void
    {
        // Arrange - Create and confirm payment with test card (no 3DS required)
        $createRequest = new CreatePaymentRequest(
            amount: 75.00,
            currency: 'USD',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Confirm with successful card
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->confirmPaymentIntent($createResponse->providerPaymentId, $paymentMethod->id);

        // Act - Verify 3DS result
        $verified = $this->adapter->verify3DSecureResult($createResponse->providerPaymentId);

        // Assert - Should be verified (or not required)
        $this->assertTrue($verified);

        // Verify payment is in correct state
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($createResponse->providerPaymentId);
        $this->assertContains($paymentIntent->status, ['requires_capture', 'succeeded']);
    }

    public function testVerifies3DSecureResultForUnconfirmedPayment(): void
    {
        // Arrange - Create payment but don't confirm
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

        // Act - Try to verify before confirmation
        $verified = $this->adapter->verify3DSecureResult($createResponse->providerPaymentId);

        // Assert - Should not be verified yet
        $this->assertFalse($verified);
    }

    public function testVerifies3DSecureResultForCancelledPayment(): void
    {
        // Arrange - Create and cancel payment
        $createRequest = new CreatePaymentRequest(
            amount: 60.00,
            currency: 'GBP',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Cancel payment
        $this->stripeClient->paymentIntents->cancel($createResponse->providerPaymentId);

        // Act - Verify after cancellation
        $verified = $this->adapter->verify3DSecureResult($createResponse->providerPaymentId);

        // Assert - Cancelled payment should not be verified
        $this->assertFalse($verified);
    }

    // ==========================================
    // 3D SECURE STATUS MAPPING TESTS
    // ==========================================

    public function test3DSecureStatusMappingForRequiresCapture(): void
    {
        // Arrange - Create and confirm payment
        $createRequest = new CreatePaymentRequest(
            amount: 85.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->confirmPaymentIntent($createResponse->providerPaymentId, $paymentMethod->id);

        // Act
        $threeDSRequest = new ThreeDSecureRequest(
            paymentId: $createResponse->providerPaymentId
        );

        $threeDSResponse = $this->adapter->initiate3DSecure($threeDSRequest);

        // Assert - requires_capture should map to 'authenticated'
        $this->assertEquals('authenticated', $threeDSResponse->status);
        $this->assertTrue($threeDSResponse->authenticated);
    }

    public function test3DSecureStatusMappingForRequiresPaymentMethod(): void
    {
        // Arrange - Create payment without payment method
        $createRequest = new CreatePaymentRequest(
            amount: 45.00,
            currency: 'USD',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Act
        $threeDSRequest = new ThreeDSecureRequest(
            paymentId: $createResponse->providerPaymentId
        );

        $threeDSResponse = $this->adapter->initiate3DSecure($threeDSRequest);

        // Assert - requires_payment_method should map to 'not_required'
        $this->assertEquals('not_required', $threeDSResponse->status);
        $this->assertFalse($threeDSResponse->authenticated);
    }

    // ==========================================
    // COMPLETE 3D SECURE FLOW TESTS
    // ==========================================

    public function testComplete3DSecureFlowWithNoAuthenticationRequired(): void
    {
        // Step 1: Create payment
        // Note: return_url can only be used when confirm=true, so we omit it here
        $createRequest = new CreatePaymentRequest(
            amount: 95.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            metadata: ['flow_test' => '3ds_complete']
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Step 2: Initiate 3DS check before confirmation
        $threeDSRequest = new ThreeDSecureRequest(
            paymentId: $createResponse->providerPaymentId
        );

        $threeDSResponse = $this->adapter->initiate3DSecure($threeDSRequest);
        $this->assertFalse($threeDSResponse->authenticated);

        // Step 3: Confirm payment with test card (no 3DS required)
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $paymentIntent = $this->confirmPaymentIntent($createResponse->providerPaymentId, $paymentMethod->id);

        // Step 4: Check 3DS status after confirmation
        $threeDSResponseAfter = $this->adapter->initiate3DSecure($threeDSRequest);
        $this->assertEquals('authenticated', $threeDSResponseAfter->status);
        $this->assertTrue($threeDSResponseAfter->authenticated);

        // Step 5: Verify 3DS result
        $verified = $this->adapter->verify3DSecureResult($createResponse->providerPaymentId);
        $this->assertTrue($verified);

        // Step 6: Verify payment is ready for capture
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($createResponse->providerPaymentId);
        $this->assertEquals('requires_capture', $paymentIntent->status);
    }

    public function test3DSecureWithDirectCapture(): void
    {
        // Arrange - Create payment with automatic capture
        // Note: return_url can only be used when confirm=true, so we omit it here
        $createRequest = new CreatePaymentRequest(
            amount: 120.00,
            currency: 'USD',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: true // Automatic capture
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Confirm payment
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $paymentIntent = $this->confirmPaymentIntent($createResponse->providerPaymentId, $paymentMethod->id);

        // Wait for processing
        sleep(2);
        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($createResponse->providerPaymentId);

        // Act - Check 3DS after automatic capture
        $threeDSRequest = new ThreeDSecureRequest(
            paymentId: $createResponse->providerPaymentId
        );

        $threeDSResponse = $this->adapter->initiate3DSecure($threeDSRequest);

        // Assert - Should be authenticated and captured
        if ($paymentIntent->status === 'succeeded') {
            $this->assertEquals('authenticated', $threeDSResponse->status);
            $this->assertTrue($threeDSResponse->authenticated);
        }

        // Verify result
        $verified = $this->adapter->verify3DSecureResult($createResponse->providerPaymentId);
        $this->assertTrue($verified);
    }

    public function test3DSecureDataInPaymentResponse(): void
    {
        // Arrange & Act - Create payment
        // Note: return_url can only be used when confirm=true, so we omit it here
        $createRequest = new CreatePaymentRequest(
            amount: 55.00,
            currency: 'EUR',
            orderId: $this->getTestOrderId(),
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            metadata: ['test' => '3ds_data']
        );

        $createResponse = $this->adapter->createPayment($createRequest);
        $this->trackResource('payment_intent', $createResponse->providerPaymentId);

        // Assert - Check response contains 3DS-related fields
        $this->assertNotNull($createResponse->clientSecret);
        $this->assertIsBool($createResponse->requiresAction);

        // Verify provider data contains necessary 3DS information
        $this->assertArrayHasKey('client_secret', $createResponse->providerData);

        // For unconfirmed payment, requiresAction should typically be false
        // It becomes true after confirmation if 3DS is needed
        $this->assertFalse($createResponse->requiresAction);
    }
}
