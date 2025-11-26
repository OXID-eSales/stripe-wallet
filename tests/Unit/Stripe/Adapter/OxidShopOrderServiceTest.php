<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Adapter;

use OxidEsales\Eshop\Application\Model\Order;
use OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\OxidShopOrderService;
use OxidSolutionCatalysts\Payments\Stripe\Repository\StripePaymentDetailsRepository;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Adapter\OxidShopOrderService
 */
final class OxidShopOrderServiceTest extends TestCase
{
    private OxidShopOrderService $service;
    private TransactionRepositoryInterface $transactionRepository;
    private StripePaymentDetailsRepository $stripeDetailsRepository;
    private ModuleConfigurationService $moduleConfig;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->stripeDetailsRepository = $this->createMock(StripePaymentDetailsRepository::class);
        $this->moduleConfig = $this->createMock(ModuleConfigurationService::class);

        $this->service = new OxidShopOrderService(
            $this->transactionRepository,
            $this->stripeDetailsRepository,
            $this->moduleConfig
        );
    }

    /**
     * @dataProvider orderStateProvider
     */
    public function testOrderStateMapping(int $orderState, string $expectedStatus): void
    {
        // Use reflection to test private mapping method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('mapOrderStateToStatus');
        $method->setAccessible(true);

        // Act
        $actualStatus = $method->invoke($this->service, $orderState);

        // Assert
        $this->assertSame($expectedStatus, $actualStatus);
    }

    public static function orderStateProvider(): array
    {
        return [
            'OK' => [Order::ORDER_STATE_OK, 'completed'],
            'Order exists' => [Order::ORDER_STATE_ORDEREXISTS, 'completed'],
            'Mailing error' => [Order::ORDER_STATE_MAILINGERROR, 'completed'],
            'Payment error' => [Order::ORDER_STATE_PAYMENTERROR, 'payment_error'],
            'Invalid payment' => [Order::ORDER_STATE_INVALIDPAYMENT, 'invalid_payment'],
            'Invalid delivery' => [Order::ORDER_STATE_INVALIDDELIVERY, 'invalid_delivery'],
            'Below minimum' => [Order::ORDER_STATE_BELOWMINPRICE, 'below_minimum'],
            'Invalid delivery address' => [Order::ORDER_STATE_INVALIDDELADDRESSCHANGED, 'invalid_delivery'],
            'Voucher error' => [Order::ORDER_STATE_VOUCHERERROR, 'voucher_error'],
            'Unknown state' => [999, 'unknown'],
        ];
    }

    /**
     * @dataProvider errorCodeProvider
     */
    public function testErrorCodeMapping(int $orderState, string $expectedErrorCode): void
    {
        // Use reflection to test private mapping method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('mapOrderStateToErrorCode');
        $method->setAccessible(true);

        // Act
        $actualErrorCode = $method->invoke($this->service, $orderState);

        // Assert
        $this->assertSame($expectedErrorCode, $actualErrorCode);
    }

    public static function errorCodeProvider(): array
    {
        return [
            'Payment error' => [Order::ORDER_STATE_PAYMENTERROR, 'payment_error'],
            'Below minimum' => [Order::ORDER_STATE_BELOWMINPRICE, 'below_minimum_price'],
            'Invalid payment' => [Order::ORDER_STATE_INVALIDPAYMENT, 'invalid_payment_method'],
            'Invalid delivery' => [Order::ORDER_STATE_INVALIDDELIVERY, 'invalid_delivery_method'],
            'Invalid delivery address' => [Order::ORDER_STATE_INVALIDDELADDRESSCHANGED, 'invalid_delivery_address'],
            'Voucher error' => [Order::ORDER_STATE_VOUCHERERROR, 'voucher_error'],
            'Unknown error' => [999, 'order_creation_failed'],
        ];
    }
}
