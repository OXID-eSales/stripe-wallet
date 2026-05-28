<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\RefundService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.10b: Characterization tests for RefundService after DTO migration.
 *
 * Verifies that RefundService correctly handles the charge/refund flow via DTOs:
 * - getChargeIdFromPaymentIntent reads latestChargeId from StripePaymentIntentDto
 * - handleRefundResponse reads from StripeRefundDto
 *
 * @covers \OxidEsales\Payments\Stripe\Service\RefundService
 */
final class RefundServiceDtoCharacterizationTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $adapter;
    private StockRestorationServiceInterface&MockObject $stockService;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapter        = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);
        $this->stockService   = $this->createMock(StockRestorationServiceInterface::class);
    }

    public function testProcessRefundSucceedsAndReturnsCorrectAmountFromRefundDto(): void
    {
        // Arrange — PI with a charge string ID, then a refund is created
        $piDto = new StripePaymentIntentDto(
            id: 'pi_refund_test',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_refund_abc',
        );
        $refundDto = new StripeRefundDto(
            id: 'rf_test_123',
            amount: 5000,
            currency: 'eur',
            status: 'succeeded',
            reason: null,
            createdAt: 1700000100,
        );

        $this->adapter->method('retrievePaymentIntent')->willReturn($piDto);
        $this->adapter->method('createRefundByCharge')->willReturn($refundDto);
        $this->stockService->method('restoreStockForOrder')->willReturn(1);

        $service = new RefundService($this->adapterFactory, $this->stockService);

        // Act
        $result = $service->processRefund('order_123', 'pi_refund_test', 'requested_by_customer');

        // Assert
        self::assertTrue($result->isSuccessful());
        self::assertSame('rf_test_123', $result->refundId);
        self::assertSame(50.0, $result->amountRefunded); // 5000 cents / 100
        self::assertSame('eur', $result->currency);
        self::assertSame('succeeded', $result->status);
    }

    public function testProcessRefundReturnsFailureWhenNoChargeOnPI(): void
    {
        // Arrange — PI with no latest_charge
        $piDto = new StripePaymentIntentDto(
            id: 'pi_no_charge',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: null,
        );

        $this->adapter->method('retrievePaymentIntent')->willReturn($piDto);

        $service = new RefundService($this->adapterFactory, $this->stockService);

        // Act
        $result = $service->processRefund('order_456', 'pi_no_charge');

        // Assert
        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('No charge', $result->errorMessage);
    }

    public function testProcessRefundJpyPreservesZeroDecimalAmount(): void
    {
        // Arrange — JPY: 1000 yen refund — minor units == yen (no /100)
        $piDto = new StripePaymentIntentDto(
            id: 'pi_jpy_test',
            status: 'succeeded',
            amount: 1000,
            currency: 'jpy',
            created: 1700000000,
            latestChargeId: 'ch_jpy_abc',
        );
        $refundDto = new StripeRefundDto(
            id: 'rf_jpy_1',
            amount: 500,
            currency: 'jpy',
            status: 'succeeded',
            reason: null,
            createdAt: 1700000100,
        );

        $this->adapter->method('retrievePaymentIntent')->willReturn($piDto);
        $this->adapter->method('createRefundByCharge')->willReturn($refundDto);

        $service = new RefundService($this->adapterFactory, $this->stockService);

        // Act
        $result = $service->processRefund('order_jpy', 'pi_jpy_test');

        // Assert — 500 JPY minor units → 500.0 major units (no division by 100)
        self::assertTrue($result->isSuccessful());
        self::assertSame(500.0, $result->amountRefunded);
        self::assertSame('jpy', $result->currency);
    }
}
