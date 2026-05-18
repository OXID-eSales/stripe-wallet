<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Charge;

/**
 * Sprint 103: Pins getRemainingRefundableRaw() and isOrderRefundable() against
 * the partial-capture fixture that previously produced −197.0.
 *
 * StripeOrderApiService is final — mocking it directly is not possible. The test
 * uses a testable subclass of OrderRefundViewDataProvider that overrides
 * getLastCharge() to return a controlled fixture charge, eliminating any real
 * API or framework dependency. The ChargeAmountResolverInterface is injected
 * via constructor and stubbed per-test.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider
 */
final class OrderRefundViewDataProviderTest extends TestCase
{
    private ChargeAmountResolverInterface&MockObject $resolver;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(ChargeAmountResolverInterface::class);
    }

    public function testRemainingRefundableRawForFullCaptureNoRefundReturnsCapturedAmount(): void
    {
        // Arrange — full capture, no refund: available = 100.0
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 0);
        $this->resolver->method('availableForRefund')->willReturn(100.0);
        $provider = $this->createProviderWithCharge($charge);

        // Act
        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        // Assert
        self::assertSame(100.0, $result);
    }

    public function testRemainingRefundableRawForFullCaptureWithCustomerRefundReturnsResidual(): void
    {
        // Arrange — full capture, 30 EUR customer refund: available = 70.0
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 3000);
        $this->resolver->method('availableForRefund')->willReturn(70.0);
        $provider = $this->createProviderWithCharge($charge);

        // Act
        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        // Assert
        self::assertSame(70.0, $result);
    }

    public function testRemainingRefundableRawForPartialCaptureNoCustomerRefundReturnsCapturedAmount(): void
    {
        // Arrange — partial capture 397→100, Stripe encodes 297 release as amount_refunded.
        // Pre-fix: (10000 − 29700) / 100 = −197.0. Post-fix: available = 100.0.
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 29700);
        $this->resolver->method('availableForRefund')->willReturn(100.0);
        $provider = $this->createProviderWithCharge($charge);

        // Act
        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        // Assert — regression case: must return 100.0, not −197.0
        self::assertSame(100.0, $result);
    }

    public function testRemainingRefundableRawForPartialCaptureWithCustomerRefundReturnsResidual(): void
    {
        // Arrange — partial capture 397→100, then 50 EUR customer refund: available = 50.0
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 34700);
        $this->resolver->method('availableForRefund')->willReturn(50.0);
        $provider = $this->createProviderWithCharge($charge);

        // Act
        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        // Assert
        self::assertSame(50.0, $result);
    }

    public function testIsOrderRefundableTrueForPartialCaptureNoCustomerRefund(): void
    {
        // Arrange — partial capture, no customer refund: available = 100.0 → refundable
        $charge = $this->buildCharge(amount: 39700, amountCaptured: 10000, amountRefunded: 29700);
        $this->resolver->method('availableForRefund')->willReturn(100.0);
        $provider = $this->createProviderWithCharge($charge);

        // Act
        $result = $provider->isOrderRefundable($this->createMock(Order::class));

        // Assert
        self::assertTrue($result);
    }

    public function testIsOrderRefundableFalseWhenCaptureFullyRefundedToCustomer(): void
    {
        // Arrange — full capture, full customer refund: available = 0.0 → not refundable
        $charge = $this->buildCharge(amount: 10000, amountCaptured: 10000, amountRefunded: 10000);
        $this->resolver->method('availableForRefund')->willReturn(0.0);
        $provider = $this->createProviderWithCharge($charge);

        // Act
        $result = $provider->isOrderRefundable($this->createMock(Order::class));

        // Assert
        self::assertFalse($result);
    }

    public function testIsOrderRefundableFalseWhenChargeIsNull(): void
    {
        // Arrange — no charge available (network error or no transaction ID)
        $provider = $this->createProviderWithCharge(null);

        // Act
        $result = $provider->isOrderRefundable($this->createMock(Order::class));

        // Assert
        self::assertFalse($result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a testable subclass of OrderRefundViewDataProvider that returns
     * a fixed charge from getLastCharge(), bypassing the final StripeOrderApiService.
     *
     * StripeOrderApiService is final and cannot be mocked with PHPUnit. We build
     * a real instance with a mocked factory (the factory interface IS mockable) and
     * then override getLastCharge() so the service is never actually called. This
     * pattern follows CLAUDE.md §"Final class mocking".
     */
    private function createProviderWithCharge(?Charge $charge): OrderRefundViewDataProvider
    {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->createMock(StripeAdapterInterface::class));
        $apiService = new StripeOrderApiService($adapterFactory);
        $resolver   = $this->resolver;

        return new class ($apiService, $resolver, $charge) extends OrderRefundViewDataProvider {
            public function __construct(
                StripeOrderApiService $apiService,
                ChargeAmountResolverInterface $chargeAmountResolver,
                private readonly ?Charge $stubCharge,
            ) {
                parent::__construct($apiService, $chargeAmountResolver);
            }

            public function getLastCharge(Order $order, bool $refresh = false): ?Charge
            {
                return $this->stubCharge;
            }
        };
    }

    private function buildCharge(int $amount, int $amountCaptured, int $amountRefunded): Charge
    {
        return Charge::constructFrom([
            'id'              => 'ch_test',
            'amount'          => $amount,
            'amount_captured' => $amountCaptured,
            'amount_refunded' => $amountRefunded,
            'currency'        => 'eur',
        ]);
    }
}
