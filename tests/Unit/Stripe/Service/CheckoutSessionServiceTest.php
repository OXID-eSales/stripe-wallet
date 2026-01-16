<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\DTO\CheckoutSessionResult;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionService;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\PaymentComponent\Adapter\Exception\PaymentAdapterException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;

/**
 * TDD Tests for CheckoutSessionService.
 *
 * Sprint 21: Extract business logic from StripeCheckoutSessionHandler.
 */
class CheckoutSessionServiceTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->adapterFactory
            ->method('getStripeAdapter')
            ->willReturn($this->stripeAdapter);
    }

    private function createService(): CheckoutSessionService
    {
        return new CheckoutSessionService(
            $this->adapterFactory,
            $this->logger
        );
    }

    private function createBasketSnapshot(array $items = [], float $totalGross = 100.0): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => $items ?: [
                ['title' => 'Test Product', 'unitPrice' => 50.00, 'quantity' => 2],
            ],
            'discounts' => [],
            'totalGross' => $totalGross,
            'totalNet' => $totalGross * 0.84,
            'totalVat' => $totalGross * 0.16,
            'currency' => 'EUR',
        ]);
    }

    // --- CheckoutSessionResult DTO Tests ---

    public function testCheckoutSessionResultSuccessCreation(): void
    {
        $result = CheckoutSessionResult::success('cs_test_123', 'https://checkout.stripe.com/pay/cs_test_123');

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('cs_test_123', $result->getSessionId());
        $this->assertEquals('https://checkout.stripe.com/pay/cs_test_123', $result->getCheckoutUrl());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testCheckoutSessionResultFailureCreation(): void
    {
        $result = CheckoutSessionResult::failure('Invalid currency', 'invalid_currency');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getSessionId());
        $this->assertNull($result->getCheckoutUrl());
        $this->assertEquals('Invalid currency', $result->getErrorMessage());
        $this->assertEquals('invalid_currency', $result->getErrorCode());
    }

    // --- Service Interface Tests ---

    public function testServiceImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(CheckoutSessionServiceInterface::class, $service);
    }

    // --- createSession Tests ---

    public function testCreateSessionSuccessful(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();
        $session = Session::constructFrom([
            'id' => 'cs_test_abc123',
            'url' => 'https://checkout.stripe.com/pay/cs_test_abc123',
        ]);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createCheckoutSession')
            ->willReturn($session);

        // Act
        $service = $this->createService();
        $result = $service->createSession(
            'contract_123',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel',
            '1',
            'automatic'
        );

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('cs_test_abc123', $result->getSessionId());
        $this->assertEquals('https://checkout.stripe.com/pay/cs_test_abc123', $result->getCheckoutUrl());
    }

    public function testCreateSessionWithManualCapture(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();
        $session = Session::constructFrom([
            'id' => 'cs_manual',
            'url' => 'https://checkout.stripe.com/pay/cs_manual',
        ]);

        $capturedParams = null;
        $this->stripeAdapter
            ->expects($this->once())
            ->method('createCheckoutSession')
            ->willReturnCallback(function ($params) use ($session, &$capturedParams) {
                $capturedParams = $params;
                return $session;
            });

        // Act
        $service = $this->createService();
        $service->createSession(
            'contract_manual',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel',
            '1',
            'manual'
        );

        // Assert
        $this->assertIsArray($capturedParams);
        $this->assertEquals('manual', $capturedParams['payment_intent_data']['capture_method']);
    }

    public function testCreateSessionIncludesContractIdInMetadata(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();
        $session = Session::constructFrom([
            'id' => 'cs_metadata',
            'url' => 'https://checkout.stripe.com/pay/cs_metadata',
        ]);

        $capturedParams = null;
        $this->stripeAdapter
            ->method('createCheckoutSession')
            ->willReturnCallback(function ($params) use ($session, &$capturedParams) {
                $capturedParams = $params;
                return $session;
            });

        // Act
        $service = $this->createService();
        $service->createSession(
            'contract_xyz',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel',
            '42'
        );

        // Assert
        $this->assertEquals('contract_xyz', $capturedParams['metadata']['contract_id']);
        $this->assertEquals('42', $capturedParams['metadata']['shop_id']);
        $this->assertEquals('contract_xyz', $capturedParams['payment_intent_data']['metadata']['contract_id']);
    }

    public function testCreateSessionIncludesOrderNumberInMetadata(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();
        $session = Session::constructFrom([
            'id' => 'cs_order_num',
            'url' => 'https://checkout.stripe.com/pay/cs_order_num',
        ]);

        $capturedParams = null;
        $this->stripeAdapter
            ->method('createCheckoutSession')
            ->willReturnCallback(function ($params) use ($session, &$capturedParams) {
                $capturedParams = $params;
                return $session;
            });

        // Act
        $service = $this->createService();
        $service->createSession(
            'contract_123',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel',
            '1',
            'automatic',
            'order_abc',
            '1001'
        );

        // Assert - order_id and order_number should be in metadata
        $this->assertEquals('order_abc', $capturedParams['metadata']['order_id']);
        $this->assertEquals('1001', $capturedParams['metadata']['order_number']);
        $this->assertEquals('order_abc', $capturedParams['payment_intent_data']['metadata']['order_id']);
        $this->assertEquals('1001', $capturedParams['payment_intent_data']['metadata']['order_number']);
    }

    public function testCreateSessionWithoutOrderNumberOmitsFromMetadata(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();
        $session = Session::constructFrom([
            'id' => 'cs_no_order',
            'url' => 'https://checkout.stripe.com/pay/cs_no_order',
        ]);

        $capturedParams = null;
        $this->stripeAdapter
            ->method('createCheckoutSession')
            ->willReturnCallback(function ($params) use ($session, &$capturedParams) {
                $capturedParams = $params;
                return $session;
            });

        // Act
        $service = $this->createService();
        $service->createSession(
            'contract_456',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel'
        );

        // Assert - order_id and order_number should not be present when not provided
        $this->assertArrayNotHasKey('order_id', $capturedParams['metadata']);
        $this->assertArrayNotHasKey('order_number', $capturedParams['metadata']);
    }

    public function testCreateSessionHandlesStripeError(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();
        $exception = new PaymentAdapterException(
            'stripe',
            'invalid_request',
            'Invalid line items'
        );

        $this->stripeAdapter
            ->method('createCheckoutSession')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error');

        // Act
        $service = $this->createService();
        $result = $service->createSession(
            'contract_error',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel'
        );

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Invalid line items', $result->getErrorMessage());
        $this->assertEquals('invalid_request', $result->getErrorCode());
    }

    // --- buildLineItems Tests ---

    public function testBuildLineItemsSingleProduct(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot([
            ['title' => 'Widget', 'unitPrice' => 29.99, 'quantity' => 1],
        ]);

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($basketSnapshot);

        // Assert
        $this->assertCount(1, $lineItems);
        $this->assertEquals('Widget', $lineItems[0]['price_data']['product_data']['name']);
        $this->assertEquals(2999, $lineItems[0]['price_data']['unit_amount']); // 29.99 EUR in cents
        $this->assertEquals('eur', $lineItems[0]['price_data']['currency']);
        $this->assertEquals(1, $lineItems[0]['quantity']);
    }

    public function testBuildLineItemsMultipleProducts(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot([
            ['title' => 'Product A', 'unitPrice' => 10.00, 'quantity' => 2],
            ['title' => 'Product B', 'unitPrice' => 25.50, 'quantity' => 1],
            ['title' => 'Product C', 'unitPrice' => 5.00, 'quantity' => 5],
        ]);

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($basketSnapshot);

        // Assert
        $this->assertCount(3, $lineItems);
        $this->assertEquals('Product A', $lineItems[0]['price_data']['product_data']['name']);
        $this->assertEquals(1000, $lineItems[0]['price_data']['unit_amount']);
        $this->assertEquals(2, $lineItems[0]['quantity']);
        $this->assertEquals('Product B', $lineItems[1]['price_data']['product_data']['name']);
        $this->assertEquals(2550, $lineItems[1]['price_data']['unit_amount']);
        $this->assertEquals('Product C', $lineItems[2]['price_data']['product_data']['name']);
        $this->assertEquals(500, $lineItems[2]['price_data']['unit_amount']);
        $this->assertEquals(5, $lineItems[2]['quantity']);
    }

    public function testBuildLineItemsHandlesMissingTitle(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot([
            ['unitPrice' => 10.00, 'quantity' => 1], // No title
        ]);

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($basketSnapshot);

        // Assert
        $this->assertEquals('Product', $lineItems[0]['price_data']['product_data']['name']);
    }

    public function testBuildLineItemsHandlesMissingQuantity(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot([
            ['title' => 'Test', 'unitPrice' => 10.00], // No quantity
        ]);

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($basketSnapshot);

        // Assert
        $this->assertEquals(1, $lineItems[0]['quantity']);
    }

    public function testBuildLineItemsConvertsToLowercaseCurrency(): void
    {
        // Arrange
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [['title' => 'Test', 'unitPrice' => 10.00, 'quantity' => 1]],
            'discounts' => [],
            'totalGross' => 10.0,
            'totalNet' => 8.4,
            'totalVat' => 1.6,
            'currency' => 'USD', // Uppercase
        ]);

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($basketSnapshot);

        // Assert
        $this->assertEquals('usd', $lineItems[0]['price_data']['currency']);
    }

    // --- buildSuccessUrl Tests ---

    public function testBuildSuccessUrl(): void
    {
        // Act
        $service = $this->createService();
        $url = $service->buildSuccessUrl(
            'https://shop.example.com/',
            'contract_abc',
            'token_xyz'
        );

        // Assert
        $this->assertStringContainsString('cl=order', $url);
        $this->assertStringContainsString('fnc=checkoutSuccess', $url);
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $url);
        $this->assertStringContainsString('contract_id=contract_abc', $url);
        $this->assertStringContainsString('contract_token=token_xyz', $url);
    }

    public function testBuildSuccessUrlEncodesSpecialCharacters(): void
    {
        // Act
        $service = $this->createService();
        $url = $service->buildSuccessUrl(
            'https://shop.example.com/',
            'contract/with/slashes',
            'token+with+plus'
        );

        // Assert - special characters should be URL encoded
        $this->assertStringContainsString('contract_id=contract%2Fwith%2Fslashes', $url);
        $this->assertStringContainsString('contract_token=token%2Bwith%2Bplus', $url);
    }

    // --- Logging Tests ---

    public function testSuccessfulSessionCreationIsLogged(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();
        $session = Session::constructFrom([
            'id' => 'cs_logged',
            'url' => 'https://checkout.stripe.com/pay/cs_logged',
        ]);

        $this->stripeAdapter
            ->method('createCheckoutSession')
            ->willReturn($session);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Checkout session created'),
                $this->callback(function ($context) {
                    return isset($context['session_id']) && $context['session_id'] === 'cs_logged';
                })
            );

        // Act
        $service = $this->createService();
        $service->createSession(
            'contract_log',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel'
        );
    }
}
