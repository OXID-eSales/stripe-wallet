<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Adapter;

use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentMethodRequest;
use OxidEsales\Payments\Stripe\Tests\Integration\Stripe\StripeIntegrationTestCase;

/**
 * Integration tests for StripeAdapter payment method management with real Stripe API.
 *
 * Tests vaulting/tokenization features:
 * - Creating payment methods
 * - Listing customer payment methods
 * - Deleting payment methods
 *
 * @group integration
 * @group stripe
 * @group api
 * @group payment-methods
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\StripeAdapter
 */
final class StripePaymentMethodIntegrationTest extends StripeIntegrationTestCase
{
    // ==========================================
    // CREATE PAYMENT METHOD TESTS
    // ==========================================

    public function testCreatesCardPaymentMethod(): void
    {
        // Arrange
        // Use Stripe test token instead of raw card numbers
        $request = new CreatePaymentMethodRequest(
            paymentMethod: 'card',
            paymentMethodData: [
                'card' => [
                    'token' => 'tok_visa', // Use test token instead of raw card data
                ],
            ],
            metadata: ['test' => 'card_creation']
        );

        // Act
        $response = $this->adapter->createPaymentMethod($request);

        // Track for cleanup
        $this->trackResource('payment_method', $response->paymentMethodId);

        // Assert
        $this->assertStringStartsWith('pm_', $response->paymentMethodId);
        $this->assertNull($response->customerId); // Not attached to customer yet
        $this->assertEquals('card', $response->type);
        $this->assertFalse($response->isDefault);
        $this->assertInstanceOf(\DateTimeImmutable::class, $response->createdAt);

        // Check card details
        $this->assertArrayHasKey('last4', $response->details);
        $this->assertEquals('4242', $response->details['last4']);
        $this->assertEquals('visa', $response->details['brand']);
        // Note: When using test tokens, exp_month/exp_year are set by Stripe and may vary
        $this->assertArrayHasKey('exp_month', $response->details);
        $this->assertArrayHasKey('exp_year', $response->details);

        // Verify with Stripe API
        $paymentMethod = $this->stripeClient->paymentMethods->retrieve($response->paymentMethodId);
        $this->assertEquals('card', $paymentMethod->type);
        $this->assertEquals('4242', $paymentMethod->card->last4);
    }

    public function testCreatesPaymentMethodAndAttachesToCustomer(): void
    {
        // Arrange
        $customer = $this->createTestCustomer();

        $request = new CreatePaymentMethodRequest(
            paymentMethod: 'card',
            customerId: $customer->id,
            paymentMethodData: [
                'card' => [
                    'token' => 'tok_mastercard', // Use Mastercard test token instead of raw card data
                ],
            ],
            metadata: ['test' => 'attach_to_customer']
        );

        // Act
        $response = $this->adapter->createPaymentMethod($request);

        // Track for cleanup
        $this->trackResource('payment_method', $response->paymentMethodId);

        // Assert
        $this->assertEquals($customer->id, $response->customerId);
        $this->assertEquals('card', $response->type);
        $this->assertEquals('4444', $response->details['last4']); // tok_mastercard ends in 4444
        $this->assertEquals('mastercard', $response->details['brand']);

        // Verify with Stripe API that payment method is attached
        $paymentMethod = $this->stripeClient->paymentMethods->retrieve($response->paymentMethodId);
        $this->assertEquals($customer->id, $paymentMethod->customer);
    }

    public function testCreatesPaymentMethodWithBillingAddress(): void
    {
        // Arrange
        $billingAddress = [
            'city' => 'Berlin',
            'country' => 'DE',
            'line1' => 'Test Street 123',
            'line2' => 'Apt 4B',
            'postal_code' => '10115',
            'state' => 'Berlin',
        ];

        $request = new CreatePaymentMethodRequest(
            paymentMethod: 'card',
            paymentMethodData: [
                'card' => [
                    'token' => 'tok_visa', // Use test token instead of raw card data
                ],
            ],
            billingAddress: $billingAddress,
            metadata: ['test' => 'with_billing_address']
        );

        // Act
        $response = $this->adapter->createPaymentMethod($request);

        // Track for cleanup
        $this->trackResource('payment_method', $response->paymentMethodId);

        // Assert
        $this->assertStringStartsWith('pm_', $response->paymentMethodId);

        // Verify with Stripe API
        $paymentMethod = $this->stripeClient->paymentMethods->retrieve($response->paymentMethodId);
        $this->assertEquals('Berlin', $paymentMethod->billing_details->address->city);
        $this->assertEquals('DE', $paymentMethod->billing_details->address->country);
        $this->assertEquals('Test Street 123', $paymentMethod->billing_details->address->line1);
        $this->assertEquals('10115', $paymentMethod->billing_details->address->postal_code);
    }

    public function testCreatesPaymentMethodWithMetadata(): void
    {
        // Arrange
        $metadata = [
            'customer_reference' => 'CUST-12345',
            'source' => 'mobile_app',
            'version' => '2.0',
        ];

        $request = new CreatePaymentMethodRequest(
            paymentMethod: 'card',
            paymentMethodData: [
                'card' => [
                    'token' => 'tok_visa', // Use test token instead of raw card data
                ],
            ],
            metadata: $metadata
        );

        // Act
        $response = $this->adapter->createPaymentMethod($request);

        // Track for cleanup
        $this->trackResource('payment_method', $response->paymentMethodId);

        // Assert
        $this->assertEquals($metadata, $response->metadata);

        // Verify with Stripe API
        $paymentMethod = $this->stripeClient->paymentMethods->retrieve($response->paymentMethodId);
        $this->assertEquals('CUST-12345', $paymentMethod->metadata['customer_reference']);
        $this->assertEquals('mobile_app', $paymentMethod->metadata['source']);
        $this->assertEquals('2.0', $paymentMethod->metadata['version']);
    }

    // ==========================================
    // LIST PAYMENT METHODS TESTS
    // ==========================================

    public function testListsCustomerPaymentMethods(): void
    {
        // Arrange - Create customer and attach multiple payment methods
        $customer = $this->createTestCustomer();

        // Create 3 payment methods
        $pm1 = $this->createTestPaymentMethod('4242424242424242');
        $pm2 = $this->createTestPaymentMethod('5555555555554444');
        $pm3 = $this->createTestPaymentMethod('378282246310005');

        // Attach all to customer
        $this->attachPaymentMethodToCustomer($pm1->id, $customer->id);
        $this->attachPaymentMethodToCustomer($pm2->id, $customer->id);
        $this->attachPaymentMethodToCustomer($pm3->id, $customer->id);

        // Act
        $paymentMethods = $this->adapter->listPaymentMethods($customer->id);

        // Assert
        $this->assertCount(3, $paymentMethods);

        // Verify each payment method
        foreach ($paymentMethods as $pm) {
            $this->assertStringStartsWith('pm_', $pm->paymentMethodId);
            $this->assertEquals($customer->id, $pm->customerId);
            $this->assertEquals('card', $pm->type);
            $this->assertArrayHasKey('last4', $pm->details);
            $this->assertArrayHasKey('brand', $pm->details);
            $this->assertInstanceOf(\DateTimeImmutable::class, $pm->createdAt);
        }

        // Verify we got the correct cards
        // tok_visa ends in 4242, tok_mastercard ends in 4444, tok_amex ends in 8431
        $last4Values = array_map(fn($pm) => $pm->details['last4'], $paymentMethods);
        $this->assertContains('4242', $last4Values);
        $this->assertContains('4444', $last4Values);
        $this->assertContains('8431', $last4Values); // tok_amex ends in 8431
    }

    public function testListsEmptyPaymentMethodsForNewCustomer(): void
    {
        // Arrange - Create customer with no payment methods
        $customer = $this->createTestCustomer();

        // Act
        $paymentMethods = $this->adapter->listPaymentMethods($customer->id);

        // Assert
        $this->assertCount(0, $paymentMethods);
        $this->assertIsArray($paymentMethods);
    }

    public function testListsOnlyCardPaymentMethods(): void
    {
        // Arrange
        $customer = $this->createTestCustomer();

        // Add card payment method
        $cardPm = $this->createTestPaymentMethod('4242424242424242');
        $this->attachPaymentMethodToCustomer($cardPm->id, $customer->id);

        // Act - List only returns card type
        $paymentMethods = $this->adapter->listPaymentMethods($customer->id);

        // Assert
        $this->assertCount(1, $paymentMethods);
        $this->assertEquals('card', $paymentMethods[0]->type);
    }

    // ==========================================
    // DELETE PAYMENT METHOD TESTS
    // ==========================================

    public function testDeletesPaymentMethod(): void
    {
        // Arrange - Create payment method attached to customer
        $customer = $this->createTestCustomer();
        $paymentMethod = $this->createTestPaymentMethod('4242424242424242');
        $this->attachPaymentMethodToCustomer($paymentMethod->id, $customer->id);

        // Verify it's attached
        $pmsBefore = $this->adapter->listPaymentMethods($customer->id);
        $this->assertCount(1, $pmsBefore);

        // Act - Delete payment method
        $result = $this->adapter->deletePaymentMethod($paymentMethod->id);

        // Assert
        $this->assertTrue($result);

        // Verify it's no longer attached
        $pmsAfter = $this->adapter->listPaymentMethods($customer->id);
        $this->assertCount(0, $pmsAfter);

        // Verify with Stripe API - payment method should be detached
        $pm = $this->stripeClient->paymentMethods->retrieve($paymentMethod->id);
        $this->assertNull($pm->customer);
    }

    public function testDeletesMultiplePaymentMethods(): void
    {
        // Arrange - Create customer with 3 payment methods
        $customer = $this->createTestCustomer();

        $pm1 = $this->createTestPaymentMethod('4242424242424242');
        $pm2 = $this->createTestPaymentMethod('5555555555554444');
        $pm3 = $this->createTestPaymentMethod('378282246310005');

        $this->attachPaymentMethodToCustomer($pm1->id, $customer->id);
        $this->attachPaymentMethodToCustomer($pm2->id, $customer->id);
        $this->attachPaymentMethodToCustomer($pm3->id, $customer->id);

        // Verify all attached
        $pmsBefore = $this->adapter->listPaymentMethods($customer->id);
        $this->assertCount(3, $pmsBefore);

        // Act - Delete 2 out of 3
        $this->adapter->deletePaymentMethod($pm1->id);
        $this->adapter->deletePaymentMethod($pm2->id);

        // Assert - Only 1 remaining
        $pmsAfter = $this->adapter->listPaymentMethods($customer->id);
        $this->assertCount(1, $pmsAfter);
        $this->assertEquals($pm3->id, $pmsAfter[0]->paymentMethodId);
    }

    // ==========================================
    // COMPLETE PAYMENT METHOD LIFECYCLE TESTS
    // ==========================================

    public function testCompletePaymentMethodLifecycle(): void
    {
        // Step 1: Create customer
        $customer = $this->createTestCustomer();

        // Step 2: Create and attach payment method
        // Use Stripe test token instead of raw card numbers
        $createRequest = new CreatePaymentMethodRequest(
            paymentMethod: 'card',
            customerId: $customer->id,
            paymentMethodData: [
                'card' => [
                    'token' => 'tok_visa', // Use test token instead of raw card data
                ],
            ],
            metadata: ['lifecycle_test' => 'true']
        );

        $createResponse = $this->adapter->createPaymentMethod($createRequest);
        $this->trackResource('payment_method', $createResponse->paymentMethodId);

        // Verify creation
        $this->assertEquals($customer->id, $createResponse->customerId);
        $this->assertEquals('card', $createResponse->type);

        // Step 3: List payment methods
        $paymentMethods = $this->adapter->listPaymentMethods($customer->id);
        $this->assertCount(1, $paymentMethods);
        $this->assertEquals($createResponse->paymentMethodId, $paymentMethods[0]->paymentMethodId);

        // Step 4: Use payment method for a payment
        $paymentIntent = $this->stripeClient->paymentIntents->create([
            'amount' => 2500,
            'currency' => 'eur',
            'customer' => $customer->id,
            'payment_method' => $createResponse->paymentMethodId,
            'confirm' => true,
            'capture_method' => 'automatic',
            'return_url' => 'https://example.com/return', // Required when confirming
        ]);

        $this->trackResource('payment_intent', $paymentIntent->id);
        $this->assertEquals($createResponse->paymentMethodId, $paymentIntent->payment_method);

        // Step 5: Delete payment method
        $deleteResult = $this->adapter->deletePaymentMethod($createResponse->paymentMethodId);
        $this->assertTrue($deleteResult);

        // Step 6: Verify deletion
        $paymentMethodsAfter = $this->adapter->listPaymentMethods($customer->id);
        $this->assertCount(0, $paymentMethodsAfter);
    }

    public function testPaymentMethodPersistsAcrossMultiplePayments(): void
    {
        // Arrange - Create customer with saved payment method
        $customer = $this->createTestCustomer();

        $createRequest = new CreatePaymentMethodRequest(
            paymentMethod: 'card',
            customerId: $customer->id,
            paymentMethodData: [
                'card' => [
                    'token' => 'tok_visa', // Use test token instead of raw card data
                ],
            ]
        );

        $pmResponse = $this->adapter->createPaymentMethod($createRequest);
        $this->trackResource('payment_method', $pmResponse->paymentMethodId);

        // Act - Use same payment method for 3 payments
        for ($i = 1; $i <= 3; $i++) {
            $paymentIntent = $this->stripeClient->paymentIntents->create([
                'amount' => 1000 * $i,
                'currency' => 'usd',
                'customer' => $customer->id,
                'payment_method' => $pmResponse->paymentMethodId,
                'confirm' => true,
                'capture_method' => 'automatic',
                'return_url' => 'https://example.com/return', // Required when confirming
            ]);

            $this->trackResource('payment_intent', $paymentIntent->id);
            $this->assertEquals($pmResponse->paymentMethodId, $paymentIntent->payment_method);
        }

        // Assert - Payment method still exists and is attached
        $paymentMethods = $this->adapter->listPaymentMethods($customer->id);
        $this->assertCount(1, $paymentMethods);
        $this->assertEquals($pmResponse->paymentMethodId, $paymentMethods[0]->paymentMethodId);
    }
}
