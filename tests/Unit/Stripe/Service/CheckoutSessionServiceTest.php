<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionService;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $discounts
     */
    private function createBasketSnapshot(
        array $items = [],
        float $totalGross = 100.0,
        array $discounts = [],
        string $currency = 'EUR'
    ): BasketSnapshot {
        return BasketSnapshot::fromArray([
            'items' => $items ?: [
                ['title' => 'Test Product', 'unitPrice' => 50.00, 'quantity' => 2],
            ],
            'discounts' => $discounts,
            'totalGross' => $totalGross,
            'totalNet' => $totalGross * 0.84,
            'totalVat' => $totalGross * 0.16,
            'currency' => $currency,
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
        $session = $this->buildSessionDto('cs_test_abc123', 'https://checkout.stripe.com/pay/cs_test_abc123');

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
        $session = $this->buildSessionDto('cs_manual', 'https://checkout.stripe.com/pay/cs_manual');

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
        $session = $this->buildSessionDto('cs_metadata', 'https://checkout.stripe.com/pay/cs_metadata');

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
        $session = $this->buildSessionDto('cs_order_num', 'https://checkout.stripe.com/pay/cs_order_num');

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
        $session = $this->buildSessionDto('cs_no_order', 'https://checkout.stripe.com/pay/cs_no_order');

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
        $basketSnapshot = $this->createBasketSnapshot(
            items: [['title' => 'Widget', 'unitPrice' => 29.99, 'quantity' => 1]],
            totalGross: 29.99
        );

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
        // Arrange: 10*2 + 25.50*1 + 5*5 = 70.50
        $basketSnapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Product A', 'unitPrice' => 10.00, 'quantity' => 2],
                ['title' => 'Product B', 'unitPrice' => 25.50, 'quantity' => 1],
                ['title' => 'Product C', 'unitPrice' => 5.00, 'quantity' => 5],
            ],
            totalGross: 70.50
        );

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
        $basketSnapshot = $this->createBasketSnapshot(
            items: [['unitPrice' => 10.00, 'quantity' => 1]], // No title
            totalGross: 10.00
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($basketSnapshot);

        // Assert
        $this->assertEquals('Product', $lineItems[0]['price_data']['product_data']['name']);
    }

    public function testBuildLineItemsHandlesMissingQuantity(): void
    {
        // Arrange (missing quantity defaults to 1, so total = 10.00)
        $basketSnapshot = $this->createBasketSnapshot(
            items: [['title' => 'Test', 'unitPrice' => 10.00]], // No quantity
            totalGross: 10.00
        );

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

    // --- Sprint 89: Language + Shop in return URL ---

    public function testBuildSuccessUrlIncludesLanguageAndShopParameters(): void
    {
        $service = $this->createService();
        $url = $service->buildSuccessUrl(
            'https://shop.example.com/',
            'contract_abc',
            'token_xyz',
            'session_123',
            1,
            2
        );

        $this->assertStringContainsString('&lang=1', $url);
        $this->assertStringContainsString('&shp=2', $url);
    }

    public function testBuildSuccessUrlDefaultsToLangZeroShpOne(): void
    {
        $service = $this->createService();
        $url = $service->buildSuccessUrl(
            'https://shop.example.com/',
            'contract_abc',
            'token_xyz'
        );

        $this->assertStringContainsString('&lang=0', $url);
        $this->assertStringContainsString('&shp=1', $url);
    }

    // --- Sprint 45: Stripe Customer Tests ---

    public function testCreateSessionWithStripeCustomerId(): void
    {
        $basketSnapshot = $this->createBasketSnapshot();
        $session = $this->buildSessionDto('cs_with_customer', 'https://checkout.stripe.com/pay/cs_with_customer');

        $capturedParams = null;
        $this->stripeAdapter
            ->method('createCheckoutSession')
            ->willReturnCallback(function ($params) use ($session, &$capturedParams) {
                $capturedParams = $params;
                return $session;
            });

        $service = $this->createService();
        $service->createSession(
            'contract_cust',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel',
            '1',
            'automatic',
            null,
            null,
            'cus_test_123'
        );

        $this->assertSame('cus_test_123', $capturedParams['customer']);
        $this->assertSame('enabled', $capturedParams['saved_payment_method_options']['payment_method_save']);
    }

    public function testCreateSessionWithoutStripeCustomerIdOmitsCustomerParam(): void
    {
        $basketSnapshot = $this->createBasketSnapshot();
        $session = $this->buildSessionDto('cs_no_customer', 'https://checkout.stripe.com/pay/cs_no_customer');

        $capturedParams = null;
        $this->stripeAdapter
            ->method('createCheckoutSession')
            ->willReturnCallback(function ($params) use ($session, &$capturedParams) {
                $capturedParams = $params;
                return $session;
            });

        $service = $this->createService();
        $service->createSession(
            'contract_no_cust',
            $basketSnapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel'
        );

        $this->assertArrayNotHasKey('customer', $capturedParams);
        $this->assertArrayNotHasKey('saved_payment_method_options', $capturedParams);
    }

    // --- Discount & Total Amount Tests (STRP-amount-mismatch) ---

    /**
     * When basket has discounts, line items total must match totalGross, not sum of items.
     *
     * Scenario: Products €100 + Shipping €10 - Discount €5 - Voucher €10 = €95
     * The amount sent to Stripe MUST be €95 (9500 cents), not €110.
     */
    public function testBuildLineItemsTotalMatchesTotalGrossWithDiscounts(): void
    {
        // Arrange
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Product A', 'unitPrice' => 50.00, 'quantity' => 2],
                ['title' => 'Shipping', 'unitPrice' => 10.00, 'quantity' => 1],
            ],
            totalGross: 95.00,
            discounts: [
                ['name' => 'Summer Sale', 'amount' => 5.00],
                ['name' => 'Voucher ABC', 'amount' => 10.00],
            ]
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($snapshot);

        // Assert — sum of line items in cents must equal totalGross in cents
        $totalCents = 0;
        foreach ($lineItems as $li) {
            $totalCents += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertEquals(9500, $totalCents, 'Line items total must match totalGross (€95.00 = 9500 cents)');
    }

    /**
     * With a single discount, the correct total must be sent.
     */
    public function testBuildLineItemsTotalMatchesTotalGrossWithSingleDiscount(): void
    {
        // Arrange: Product €29.99 - 10% discount (€3.00) = €26.99
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Widget', 'unitPrice' => 29.99, 'quantity' => 1],
            ],
            totalGross: 26.99,
            discounts: [
                ['name' => '10% Off', 'amount' => 3.00],
            ]
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($snapshot);

        // Assert
        $totalCents = 0;
        foreach ($lineItems as $li) {
            $totalCents += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertEquals(2699, $totalCents, 'Line items total must match totalGross (€26.99 = 2699 cents)');
    }

    /**
     * Without discounts, line items should still match totalGross and include all items.
     */
    public function testBuildLineItemsNoDiscountsStillMatchesTotalGross(): void
    {
        // Arrange: Products match total exactly
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Product A', 'unitPrice' => 10.00, 'quantity' => 3],
                ['title' => 'Shipping', 'unitPrice' => 5.00, 'quantity' => 1],
            ],
            totalGross: 35.00,
            discounts: []
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($snapshot);

        // Assert
        $totalCents = 0;
        foreach ($lineItems as $li) {
            $totalCents += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertEquals(3500, $totalCents);
        // Should still have individual items when no discounts
        $this->assertGreaterThanOrEqual(2, count($lineItems));
    }

    /**
     * Per-line gross prices rounded to cents can diverge from OXID's grouped-VAT
     * basket total by a rounding residue (STRP-157). When that happens, the amount
     * charged by Stripe MUST equal the authoritative cart total, not the itemized sum.
     *
     * Reported: 2× "Amber" — Stripe charged €168.00 while the cart showed €168.01.
     * Itemized sum: 2 × 8400 = 16800. Authoritative totalGross: 16801.
     */
    public function testBuildLineItemsFallsBackToTotalGrossOnRoundingResidue(): void
    {
        // Arrange: itemized sum (168.00) does NOT match the grouped-VAT total (168.01)
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Amber', 'unitPrice' => 84.00, 'quantity' => 2],
            ],
            totalGross: 168.01,
            discounts: []
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($snapshot);

        // Assert — charged total must equal the authoritative cart total (16801 cents)
        $totalCents = 0;
        foreach ($lineItems as $li) {
            $totalCents += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertEquals(16801, $totalCents, 'Stripe total must match the cart total (€168.01), not the itemized sum (€168.00)');
    }

    /**
     * Discounts must appear as visible line items so the customer sees them on the Stripe page.
     */
    public function testBuildLineItemsIncludesDiscountsAsVisibleItems(): void
    {
        // Arrange
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Product', 'unitPrice' => 50.00, 'quantity' => 1],
            ],
            totalGross: 40.00,
            discounts: [
                ['name' => 'Loyalty Discount', 'amount' => 10.00],
            ]
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($snapshot);

        // Assert — there should be more than just the product (discount should be visible)
        $totalCents = 0;
        foreach ($lineItems as $li) {
            $totalCents += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertEquals(4000, $totalCents, 'Total must be €40.00 (after €10 discount)');
    }

    /**
     * Multiple discounts must all be applied correctly.
     */
    public function testBuildLineItemsWithMultipleDiscountsAndShipping(): void
    {
        // Arrange: Complex basket
        // Product A: €25 x 2 = €50
        // Product B: €15 x 1 = €15
        // Shipping: €7.50
        // Discount 1: -€5.00 (percentage)
        // Discount 2: -€12.50 (voucher)
        // Total: €50 + €15 + €7.50 - €5 - €12.50 = €55.00
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Product A', 'unitPrice' => 25.00, 'quantity' => 2],
                ['title' => 'Product B', 'unitPrice' => 15.00, 'quantity' => 1],
                ['title' => 'Shipping', 'unitPrice' => 7.50, 'quantity' => 1],
            ],
            totalGross: 55.00,
            discounts: [
                ['name' => '5% Off', 'amount' => 5.00],
                ['name' => 'Welcome Voucher', 'amount' => 12.50],
            ]
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($snapshot);

        // Assert
        $totalCents = 0;
        foreach ($lineItems as $li) {
            $totalCents += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertEquals(5500, $totalCents, 'Total must be €55.00 after all discounts');
    }

    /**
     * Ensure line items sent to Stripe API in createSession use correct total with discounts.
     * End-to-end: basket with discounts → createSession → Stripe API receives correct amount.
     */
    public function testCreateSessionSendsCorrectAmountWithDiscounts(): void
    {
        // Arrange
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Product', 'unitPrice' => 100.00, 'quantity' => 1],
                ['title' => 'Shipping', 'unitPrice' => 10.00, 'quantity' => 1],
            ],
            totalGross: 85.00,
            discounts: [
                ['name' => 'Coupon A', 'amount' => 15.00],
                ['name' => 'Coupon B', 'amount' => 10.00],
            ]
        );

        $session = $this->buildSessionDto('cs_discount_test', 'https://checkout.stripe.com/pay/cs_discount_test');

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
            'contract_discount',
            $snapshot,
            'https://shop.example.com/success',
            'https://shop.example.com/cancel'
        );

        // Assert — verify the line items sent to Stripe total to €85.00
        $this->assertIsArray($capturedParams);
        $this->assertArrayHasKey('line_items', $capturedParams);

        $totalCents = 0;
        foreach ($capturedParams['line_items'] as $li) {
            $totalCents += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertEquals(8500, $totalCents, 'Stripe must receive €85.00 (8500 cents), not €110.00');
    }

    /**
     * Vouchers are now included in snapshot discounts (extracted from basket->getVouchers()).
     * When a voucher is applied, it appears in getDiscounts() and triggers totalGross mode.
     */
    public function testBuildLineItemsUsesTotalGrossWhenVoucherApplied(): void
    {
        // Arrange: Voucher of €10 applied
        // Items sum to €60, totalGross is €50 after voucher
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Product', 'unitPrice' => 50.00, 'quantity' => 1],
                ['title' => 'Shipping', 'unitPrice' => 10.00, 'quantity' => 1],
            ],
            totalGross: 50.00,
            discounts: [
                ['name' => 'Voucher: SAVE10', 'amount' => 10.00],
            ]
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($snapshot);

        // Assert — must use totalGross
        $totalCents = 0;
        foreach ($lineItems as $li) {
            $totalCents += $li['price_data']['unit_amount'] * $li['quantity'];
        }
        $this->assertEquals(5000, $totalCents, 'Must charge €50.00 (totalGross), not €60.00 (item sum)');
    }

    /**
     * When items sum exactly matches totalGross (no discounts), keep itemized display.
     */
    public function testBuildLineItemsKeepsItemizedWhenSumMatchesTotalGross(): void
    {
        // Arrange: No discounts, items sum equals totalGross exactly
        $snapshot = $this->createBasketSnapshot(
            items: [
                ['title' => 'Product A', 'unitPrice' => 25.00, 'quantity' => 2],
                ['title' => 'Shipping', 'unitPrice' => 5.00, 'quantity' => 1],
            ],
            totalGross: 55.00,
            discounts: []
        );

        // Act
        $service = $this->createService();
        $lineItems = $service->buildLineItems($snapshot);

        // Assert — should keep individual items (not collapsed to single line)
        $this->assertCount(2, $lineItems);
        $this->assertEquals('Product A', $lineItems[0]['price_data']['product_data']['name']);
        $this->assertEquals('Shipping', $lineItems[1]['price_data']['product_data']['name']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildSessionDto(string $id, string $url): StripeCheckoutSessionDto
    {
        return new StripeCheckoutSessionDto(
            id: $id,
            paymentStatus: 'paid',
            paymentIntentId: '',
            paymentIntentStatus: 'unknown',
            metadata: [],
            amountTotal: 0,
            currency: 'eur',
            url: $url,
        );
    }

    // --- Logging Tests ---

    public function testSuccessfulSessionCreationIsLogged(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();
        $session = $this->buildSessionDto('cs_logged', 'https://checkout.stripe.com/pay/cs_logged');

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
