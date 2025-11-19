<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe;

use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeAdapter;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

/**
 * Base test case for Stripe integration tests with real API.
 *
 * Provides:
 * - Stripe API client setup
 * - Environment variable loading
 * - Test helpers for common operations
 * - Cleanup functionality
 *
 * @since 1.0.0
 */
abstract class StripeIntegrationTestCase extends TestCase
{
    protected StripeAdapter $adapter;
    protected StripeClient $stripeClient;
    protected string $testSecretKey;
    protected array $createdResources = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Load environment variables from tests/.env
        $this->loadEnvFile();

        // Get test credentials
        $this->testSecretKey = $_ENV['STRIPE_TEST_SECRET_KEY'] ?? '';

        if (empty($this->testSecretKey) || $this->testSecretKey === 'sk_test_your_key_here') {
            $this->markTestSkipped(
                'Stripe test credentials not configured. ' .
                'Set STRIPE_TEST_SECRET_KEY in tests/.env file. ' .
                'Get test keys from https://dashboard.stripe.com/test/apikeys'
            );
        }

        // Create Stripe client
        $this->stripeClient = new StripeClient($this->testSecretKey);

        // Create adapter with injected client (constructor injection - SOLID principle)
        $this->adapter = new StripeAdapter($this->stripeClient);
    }

    protected function tearDown(): void
    {
        // Clean up created resources
        $this->cleanupCreatedResources();

        parent::tearDown();
    }

    /**
     * Load environment variables from tests/.env file.
     */
    private function loadEnvFile(): void
    {
        $envFile = __DIR__ . '/../../.env';

        if (!file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }

    /**
     * Track a Stripe resource for cleanup after test.
     *
     * @param string $type Resource type (payment_intent, customer, payment_method, etc.)
     * @param string $id Resource ID
     */
    protected function trackResource(string $type, string $id): void
    {
        $this->createdResources[] = ['type' => $type, 'id' => $id];
    }

    /**
     * Clean up all tracked resources.
     */
    private function cleanupCreatedResources(): void
    {
        foreach ($this->createdResources as $resource) {
            try {
                match ($resource['type']) {
                    'payment_intent' => $this->cancelPaymentIntentIfPossible($resource['id']),
                    'customer' => $this->stripeClient->customers->delete($resource['id']),
                    'payment_method' => $this->stripeClient->paymentMethods->detach($resource['id']),
                    default => null,
                };
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }

        $this->createdResources = [];
    }

    /**
     * Cancel a payment intent if it's in a cancellable state.
     */
    private function cancelPaymentIntentIfPossible(string $paymentIntentId): void
    {
        try {
            $pi = $this->stripeClient->paymentIntents->retrieve($paymentIntentId);

            // Only cancel if in cancellable state
            if (in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'])) {
                $this->stripeClient->paymentIntents->cancel($paymentIntentId);
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
    }

    /**
     * Create a test Stripe customer.
     */
    protected function createTestCustomer(array $params = []): \Stripe\Customer
    {
        $customer = $this->stripeClient->customers->create(array_merge([
            'email' => 'test-' . uniqid() . '@example.com',
            'name' => 'Test Customer',
            'metadata' => ['test' => 'true'],
        ], $params));

        $this->trackResource('customer', $customer->id);

        return $customer;
    }

    /**
     * Create a test payment method (card).
     *
     * Uses Stripe's test tokens to create payment methods safely.
     * This avoids sending raw card numbers directly to the API.
     *
     * @see https://stripe.com/docs/testing#cards
     * @see https://docs.stripe.com/testing#use-test-cards
     *
     * @param string $cardNumber Test card number (maps to appropriate test token)
     */
    protected function createTestPaymentMethod(string $cardNumber = '4242424242424242'): \Stripe\PaymentMethod
    {
        // Map card numbers to Stripe test tokens
        // These tokens can be used to create PaymentMethods without sending raw card data
        $testToken = match ($cardNumber) {
            '4242424242424242' => 'tok_visa', // Visa - succeeds
            '5555555555554444' => 'tok_mastercard', // Mastercard - succeeds
            '378282246310005' => 'tok_amex', // Amex - succeeds
            '6011111111111117' => 'tok_discover', // Discover - succeeds
            '3056930009020004' => 'tok_diners', // Diners Club - succeeds
            '3566002020360505' => 'tok_jcb', // JCB - succeeds
            default => 'tok_visa',
        };

        // Create a PaymentMethod using the test token
        // This is the safe way to create test payment methods
        $paymentMethod = $this->stripeClient->paymentMethods->create([
            'type' => 'card',
            'card' => [
                'token' => $testToken,
            ],
        ]);

        $this->trackResource('payment_method', $paymentMethod->id);

        return $paymentMethod;
    }

    /**
     * Attach payment method to customer.
     */
    protected function attachPaymentMethodToCustomer(string $paymentMethodId, string $customerId): void
    {
        $this->stripeClient->paymentMethods->attach($paymentMethodId, [
            'customer' => $customerId,
        ]);
    }

    /**
     * Confirm a payment intent (simulates customer completing payment).
     *
     * @param string $paymentIntentId The PaymentIntent ID to confirm
     * @param string|null $paymentMethodId Optional payment method ID
     * @return \Stripe\PaymentIntent The confirmed PaymentIntent
     */
    protected function confirmPaymentIntent(string $paymentIntentId, ?string $paymentMethodId = null): \Stripe\PaymentIntent
    {
        $params = [
            'return_url' => 'https://example.com/return', // Required when confirming
        ];

        if ($paymentMethodId !== null) {
            $params['payment_method'] = $paymentMethodId;
        }

        return $this->stripeClient->paymentIntents->confirm($paymentIntentId, $params);
    }

    /**
     * Get unique order ID for testing.
     */
    protected function getTestOrderId(): string
    {
        return 'test-order-' . uniqid();
    }

    /**
     * Assert payment intent has expected status.
     */
    protected function assertPaymentIntentStatus(string $expectedStatus, \Stripe\PaymentIntent $paymentIntent): void
    {
        $this->assertEquals(
            $expectedStatus,
            $paymentIntent->status,
            sprintf(
                'Expected PaymentIntent %s to have status "%s" but got "%s"',
                $paymentIntent->id,
                $expectedStatus,
                $paymentIntent->status
            )
        );
    }
}
