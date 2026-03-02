<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentComponent\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\OxidShopOrderService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\Payments\Stripe\Adapter\OxidShopOrderService
 */
final class OxidShopOrderServiceTest extends TestCase
{
    private OxidShopOrderService $service;
    private TransactionRepositoryInterface $transactionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock dependencies
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);

        $this->service = new OxidShopOrderService(
            $this->transactionRepository
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

    // ==========================================
    // CreateOrderRequest initialStatus TESTS
    // ==========================================

    public function testCreateOrderRequestAcceptsInitialStatus(): void
    {
        $request = new CreateOrderRequest(
            sessionId: 'sess123',
            userId: 'user123',
            paymentId: 'oe_payments_stripe_wallet',
            initialStatus: 'NOT_FINISHED'
        );

        $this->assertSame('NOT_FINISHED', $request->initialStatus);
    }

    public function testCreateOrderRequestInitialStatusDefaultsToNull(): void
    {
        $request = new CreateOrderRequest(
            sessionId: 'sess123',
            userId: 'user123',
            paymentId: 'oe_payments_stripe_wallet'
        );

        $this->assertNull($request->initialStatus);
    }

    public function testServiceImplementsDeleteNotFinishedOrder(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'deleteNotFinishedOrder'),
            'OxidShopOrderService must implement deleteNotFinishedOrder()'
        );
    }
}
