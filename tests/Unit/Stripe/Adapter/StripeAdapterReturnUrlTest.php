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
use OxidEsales\PaymentBase\Adapter\Request\CreatePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Request\AuthorizePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Stripe\StripeClient;

/**
 * Test double for StripeClient that allows setting paymentIntents property.
 */
class TestStripeClient extends StripeClient
{
    public $paymentIntents;

    public function __construct()
    {
        // Don't call parent constructor to avoid API initialization
    }
}

/**
 * Unit tests for StripeAdapter return_url validation.
 *
 * This test validates the fix for Stripe API feedback about missing return_url parameter.
 * When a PaymentIntent is confirmed (by providing a payment_method_id), Stripe requires
 * a return_url to redirect customers after payment completion.
 *
 *
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\StripeAdapter::class)]
#[\PHPUnit\Framework\Attributes\Group('unit')]
#[\PHPUnit\Framework\Attributes\Group('stripe')]
#[\PHPUnit\Framework\Attributes\Group('adapter')]
final class StripeAdapterReturnUrlTest extends TestCase
{
    private StripeClient $stripeClient;
    private StripeAdapter $adapter;

    protected function setUp(): void
    {
        // Create a test double for StripeClient
        $this->stripeClient = new TestStripeClient();

        // Create adapter with injected client (constructor injection - SOLID principle)
        $idempotencyRepository = $this->createMock(IdempotencyRepositoryInterface::class);
        $this->adapter = new StripeAdapter(
            $this->stripeClient,
            new PaymentIntentHelper($idempotencyRepository),
            new RefundHelper($idempotencyRepository)
        );
    }

    /**
     * Test that createPayment throws exception when return_url is missing
     * but payment_method_id is provided (which triggers confirmation).
     *
     * This reproduces the issue reported by Stripe API.
     */
    public function testCreatePaymentThrowsExceptionWhenReturnUrlMissingWithPaymentMethod(): void
    {
        // Arrange
        $request = new CreatePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'ORDER-123',
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            paymentMethodId: 'pm_test_123', // This triggers confirmation
            customerId: 'cus_test_123',
            returnUrl: null, // Missing return_url - this should cause an error
            metadata: ['test' => 'validation']
        );

        // Act & Assert
        $this->expectException(PaymentAdapterException::class);
        $this->expectExceptionMessage('return_url is required when confirming a PaymentIntent');

        $this->adapter->createPayment($request);
    }

    /**
     * Test that createPayment does not throw when return_url is provided
     * with payment_method_id (validation should pass).
     */
    public function testCreatePaymentValidationPassesWhenReturnUrlProvidedWithPaymentMethod(): void
    {
        // Arrange
        $request = new CreatePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'ORDER-123',
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            paymentMethodId: 'pm_test_123',
            customerId: 'cus_test_123',
            returnUrl: 'https://example.com/return', // return_url provided - validation should pass
            metadata: ['test' => 'validation']
        );

        // Create a fake Stripe PaymentIntent object using anonymous class
        $mockPaymentIntent = new class {
            public string $id = 'pi_test_123';
            public string $status = 'requires_capture';
            public int $amount = 10000;
            public string $currency = 'eur';
            public string $client_secret = 'pi_test_123_secret';
            public $next_action = null;
            public int $created;

            public function __construct() {
                $this->created = time();
            }

            public function toArray(): array {
                return [
                    'id' => $this->id,
                    'status' => $this->status,
                    'amount' => $this->amount,
                    'currency' => $this->currency,
                ];
            }
        };

        // Mock the StripeClient paymentIntents service
        $mockPaymentIntentsService = $this->createMock(\Stripe\Service\PaymentIntentService::class);
        $mockPaymentIntentsService->method('create')
            ->with($this->callback(function ($params) {
                // Verify that return_url is included in the API call
                return isset($params['return_url'])
                    && $params['return_url'] === 'https://example.com/return';
            }))
            ->willReturn($mockPaymentIntent);

        $this->stripeClient->paymentIntents = $mockPaymentIntentsService;

        // Act - should not throw exception
        $response = $this->adapter->createPayment($request);

        // Assert - validation passed, no exception thrown
        $this->assertEquals('pi_test_123', $response->providerPaymentId);
    }

    /**
     * Test that createPayment does not throw when payment_method_id is not provided
     * (no confirmation), even without return_url.
     */
    public function testCreatePaymentValidationPassesWithoutReturnUrlWhenNotConfirming(): void
    {
        // Arrange
        $request = new CreatePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'ORDER-123',
            shopId: '1',
            paymentMethod: 'card',
            directCapture: false,
            paymentMethodId: null, // No payment method = no confirmation
            returnUrl: null, // return_url not required when not confirming
            metadata: ['test' => 'validation']
        );

        // Create a fake Stripe PaymentIntent object using anonymous class
        $mockPaymentIntent = new class {
            public string $id = 'pi_test_456';
            public string $status = 'requires_payment_method';
            public int $amount = 10000;
            public string $currency = 'eur';
            public string $client_secret = 'pi_test_456_secret';
            public $next_action = null;
            public int $created;

            public function __construct() {
                $this->created = time();
            }

            public function toArray(): array {
                return [
                    'id' => $this->id,
                    'status' => $this->status,
                    'amount' => $this->amount,
                    'currency' => $this->currency,
                ];
            }
        };

        // Mock the StripeClient paymentIntents service
        $mockPaymentIntentsService = $this->createMock(\Stripe\Service\PaymentIntentService::class);
        $mockPaymentIntentsService->method('create')
            ->with($this->callback(function ($params) {
                // Verify that confirm is NOT set (no payment method)
                return !isset($params['confirm']);
            }))
            ->willReturn($mockPaymentIntent);

        $this->stripeClient->paymentIntents = $mockPaymentIntentsService;

        // Act - should not throw exception
        $response = $this->adapter->createPayment($request);

        // Assert - validation passed, no exception thrown
        $this->assertEquals('pi_test_456', $response->providerPaymentId);
    }

    /**
     * Test that authorizePayment throws exception when return_url is missing
     * but payment_method_id is provided (which triggers confirmation).
     */
    public function testAuthorizePaymentThrowsExceptionWhenReturnUrlMissingWithPaymentMethod(): void
    {
        // Arrange
        $request = new AuthorizePaymentRequest(
            amount: 150.00,
            currency: 'USD',
            orderId: 'ORDER-456',
            shopId: '1',
            paymentMethod: 'card',
            paymentMethodId: 'pm_test_789', // This triggers confirmation
            customerId: 'cus_test_456',
            returnUrl: null, // Missing return_url - this should cause an error
            metadata: ['test' => 'authorization']
        );

        // Act & Assert
        $this->expectException(PaymentAdapterException::class);
        $this->expectExceptionMessage('return_url is required when confirming a PaymentIntent');

        $this->adapter->authorizePayment($request);
    }

    /**
     * Test that authorizePayment does not throw when return_url is provided
     * with payment_method_id (validation should pass).
     */
    public function testAuthorizePaymentValidationPassesWhenReturnUrlProvidedWithPaymentMethod(): void
    {
        // Arrange
        $request = new AuthorizePaymentRequest(
            amount: 150.00,
            currency: 'USD',
            orderId: 'ORDER-456',
            shopId: '1',
            paymentMethod: 'card',
            paymentMethodId: 'pm_test_789',
            customerId: 'cus_test_456',
            returnUrl: 'https://example.com/return', // return_url provided - validation should pass
            metadata: ['test' => 'authorization']
        );

        // Create a fake Stripe PaymentIntent object using anonymous class
        $mockPaymentIntent = new class {
            public string $id = 'pi_test_auth_123';
            public string $status = 'requires_capture';
            public int $amount = 15000;
            public string $currency = 'usd';
            public string $client_secret = 'pi_test_auth_123_secret';
            public $next_action = null;
            public int $created;

            public function __construct() {
                $this->created = time();
            }

            public function toArray(): array {
                return [
                    'id' => $this->id,
                    'status' => $this->status,
                    'amount' => $this->amount,
                    'currency' => $this->currency,
                ];
            }
        };

        // Mock the StripeClient paymentIntents service
        $mockPaymentIntentsService = $this->createMock(\Stripe\Service\PaymentIntentService::class);
        $mockPaymentIntentsService->method('create')
            ->with($this->callback(function ($params) {
                // Verify that return_url is included in the API call
                return isset($params['return_url'])
                    && $params['return_url'] === 'https://example.com/return';
            }))
            ->willReturn($mockPaymentIntent);

        $this->stripeClient->paymentIntents = $mockPaymentIntentsService;

        // Act - should not throw exception
        $response = $this->adapter->authorizePayment($request);

        // Assert - validation passed, no exception thrown
        $this->assertEquals('pi_test_auth_123', $response->authorizationId);
    }

    /**
     * Test that exception context includes helpful debugging information.
     */
    public function testExceptionContextIncludesDebugInfo(): void
    {
        // Arrange
        $request = new CreatePaymentRequest(
            amount: 100.00,
            currency: 'EUR',
            orderId: 'DEBUG-ORDER-123',
            shopId: '2',
            paymentMethod: 'card',
            directCapture: false,
            paymentMethodId: 'pm_debug_123',
            returnUrl: null,
            metadata: ['debug' => 'test']
        );

        // Act & Assert
        try {
            $this->adapter->createPayment($request);
            $this->fail('Expected PaymentAdapterException was not thrown');
        } catch (PaymentAdapterException $e) {
            // Verify exception has proper error code using getter methods
            $this->assertEquals('missing_return_url', $e->getErrorCode());
            $this->assertEquals('stripe', $e->getProviderName());

            // Verify context includes payment_method_id and order_id for debugging
            $context = $e->getContext();
            $this->assertArrayHasKey('payment_method_id', $context);
            $this->assertArrayHasKey('order_id', $context);
            $this->assertEquals('pm_debug_123', $context['payment_method_id']);
            $this->assertEquals('DEBUG-ORDER-123', $context['order_id']);
        }
    }
}
