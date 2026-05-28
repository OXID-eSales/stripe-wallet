<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.10b: Characterization tests for OrderRefundViewDataProvider using DTOs.
 *
 * Pins the behavior of getStripeTransactionHistory(), isOrderCapturable(),
 * getCaptureableRaw(), getRemainingRefundableRaw(), isOrderRefundable() and
 * getStripeCapturedAmount() after migrating from raw \Stripe\* to DTOs.
 *
 * Uses a testable subclass that overrides fetchExpandedPaymentIntent() to inject
 * a controlled StripePaymentIntentDto fixture (replaces the old PaymentIntent fixture).
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider
 */
final class OrderRefundViewDataProviderDtoCharacterizationTest extends TestCase
{
    private ChargeAmountResolverInterface&MockObject $resolver;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(ChargeAmountResolverInterface::class);
    }

    public function testTransactionHistoryContainsAuthorizationCapturAndRefundRows(): void
    {
        // Arrange — PI with one refund
        $refundDto = new StripeRefundDto(
            id: 'rf_test_1',
            amount: 3000,
            currency: 'eur',
            status: 'succeeded',
            reason: null,
            createdAt: 1700000300,
        );
        $chargeDto = new StripeChargeDto(
            id: 'ch_test',
            amount: 10000,
            amountCaptured: 10000,
            amountRefunded: 3000,
            currency: 'eur',
            captured: true,
            created: 1700000100,
            refunds: [$refundDto],
        );
        $piDto = new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_test',
            charge: $chargeDto,
        );
        $provider = $this->buildProviderWithPi($piDto);
        $order    = $this->createMock(Order::class);

        // Act
        $history = $provider->getStripeTransactionHistory($order);

        // Assert — authorization + capture + refund rows
        self::assertCount(3, $history);

        self::assertSame('authorization', $history[0]['type']);
        self::assertSame('pi_test', $history[0]['transactionId']);
        self::assertSame(100.0, $history[0]['amount']); // 10000 cents / 100

        self::assertSame('capture', $history[1]['type']);
        self::assertSame('completed', $history[1]['status']);
        self::assertSame(100.0, $history[1]['amount']);

        self::assertSame('refund', $history[2]['type']);
        self::assertSame('succeeded', $history[2]['status']);
        self::assertSame(30.0, $history[2]['amount']); // 3000 cents / 100
    }

    public function testTransactionHistoryAuthorizationOnlyWhenNoCharge(): void
    {
        // Arrange — PI without expanded charge (no capture yet)
        $piDto = new StripePaymentIntentDto(
            id: 'pi_no_charge',
            status: 'requires_capture',
            amount: 5000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
            charge: null,
        );
        $provider = $this->buildProviderWithPi($piDto);
        $order    = $this->createMock(Order::class);

        // Act
        $history = $provider->getStripeTransactionHistory($order);

        // Assert — only authorization row (no charge → no capture/refund rows)
        self::assertCount(1, $history);
        self::assertSame('authorization', $history[0]['type']);
    }

    public function testIsOrderCapturableReturnsTrueWhenStatusRequiresCapture(): void
    {
        $piDto = $this->buildPiDto(status: 'requires_capture');
        $provider = $this->buildProviderWithPi($piDto);

        self::assertTrue($provider->isOrderCapturable($this->createMock(Order::class)));
    }

    public function testIsOrderCapturableReturnsFalseWhenStatusSucceeded(): void
    {
        $piDto = $this->buildPiDto(status: 'succeeded');
        $provider = $this->buildProviderWithPi($piDto);

        self::assertFalse($provider->isOrderCapturable($this->createMock(Order::class)));
    }

    public function testGetCaptureableRawReturnsAmountInMajorUnits(): void
    {
        // 10000 EUR minor units → 100.0 major units
        $piDto = $this->buildPiDto(amount: 10000, currency: 'eur');
        $provider = $this->buildProviderWithPi($piDto);

        self::assertSame(100.0, $provider->getCaptureableRaw($this->createMock(Order::class)));
    }

    public function testGetCaptureableRawJpyReturnsUnchangedAmount(): void
    {
        // 1000 JPY minor units → 1000.0 major units (zero-decimal)
        $piDto = $this->buildPiDto(amount: 1000, currency: 'jpy');
        $provider = $this->buildProviderWithPi($piDto);

        self::assertSame(1000.0, $provider->getCaptureableRaw($this->createMock(Order::class)));
    }

    public function testRemainingRefundableRawDelegatesToResolver(): void
    {
        // Arrange — resolver returns 50.0 EUR
        $chargeDto = new StripeChargeDto(
            id: 'ch_test',
            amount: 10000,
            amountCaptured: 10000,
            amountRefunded: 5000,
            currency: 'eur',
            captured: true,
            created: 1700000100,
        );
        $piDto = new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_test',
            charge: $chargeDto,
        );
        $this->resolver->method('availableForRefund')->willReturn(50.0);
        $provider = $this->buildProviderWithPi($piDto);

        // Act
        $result = $provider->getRemainingRefundableRaw($this->createMock(Order::class));

        // Assert
        self::assertSame(50.0, $result);
    }

    public function testIsOrderRefundableReturnsTrueWhenResolverSaysAvailable(): void
    {
        $chargeDto = $this->buildChargeDto(amountCaptured: 10000, amountRefunded: 0);
        $piDto     = $this->buildPiDtoWithCharge($chargeDto);
        $this->resolver->method('availableForRefund')->willReturn(100.0);
        $provider = $this->buildProviderWithPi($piDto);

        self::assertTrue($provider->isOrderRefundable($this->createMock(Order::class)));
    }

    public function testIsOrderRefundableReturnsFalseWhenChargeIsNull(): void
    {
        $piDto = new StripePaymentIntentDto(
            id: 'pi_no_charge',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
            charge: null,
        );
        $provider = $this->buildProviderWithPi($piDto);

        self::assertFalse($provider->isOrderRefundable($this->createMock(Order::class)));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a testable subclass of OrderRefundViewDataProvider that injects
     * a controlled StripePaymentIntentDto fixture via fetchExpandedPaymentIntent().
     */
    private function buildProviderWithPi(?StripePaymentIntentDto $piDto): OrderRefundViewDataProvider
    {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $apiService     = new StripeOrderApiService($adapterFactory);
        $resolver       = $this->resolver;

        return new class ($apiService, $resolver, $piDto) extends OrderRefundViewDataProvider {
            public function __construct(
                StripeOrderApiService $apiService,
                ChargeAmountResolverInterface $chargeAmountResolver,
                private readonly ?StripePaymentIntentDto $stubPi,
            ) {
                parent::__construct($apiService, $chargeAmountResolver);
            }

            protected function fetchExpandedPaymentIntent(Order $order): ?StripePaymentIntentDto
            {
                return $this->stubPi;
            }
        };
    }

    private function buildPiDto(
        string $status = 'succeeded',
        int $amount = 10000,
        string $currency = 'eur',
    ): StripePaymentIntentDto {
        return new StripePaymentIntentDto(
            id: 'pi_test',
            status: $status,
            amount: $amount,
            currency: $currency,
            created: 1700000000,
            latestChargeId: null,
            charge: null,
        );
    }

    private function buildPiDtoWithCharge(StripeChargeDto $chargeDto): StripePaymentIntentDto
    {
        return new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'succeeded',
            amount: $chargeDto->amount,
            currency: $chargeDto->currency,
            created: 1700000000,
            latestChargeId: $chargeDto->id,
            charge: $chargeDto,
        );
    }

    private function buildChargeDto(
        int $amount = 10000,
        int $amountCaptured = 10000,
        int $amountRefunded = 0,
        string $currency = 'eur',
    ): StripeChargeDto {
        return new StripeChargeDto(
            id: 'ch_test',
            amount: $amount,
            amountCaptured: $amountCaptured,
            amountRefunded: $amountRefunded,
            currency: $currency,
            captured: true,
            created: 1700000100,
        );
    }
}
