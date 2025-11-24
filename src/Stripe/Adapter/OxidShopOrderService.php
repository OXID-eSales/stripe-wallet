<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Adapter;

use DateTimeImmutable;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Component\Adapter\ShopOrderServiceInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreateOrderRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\OrderResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\ShopOrderException;

/**
 * OXID eShop implementation of ShopOrderServiceInterface.
 *
 * Wraps OXID-specific order operations to provide platform-agnostic interface.
 *
 * Phase 1: Order Creation
 * - Handles order creation via Order::finalizeOrder()
 * - Maps OXID order states to normalized status
 * - Provides transaction ID association
 *
 * @since 1.0.0
 */
final class OxidShopOrderService implements ShopOrderServiceInterface
{
    /**
     * @inheritDoc
     */
    public function createOrder(CreateOrderRequest $request): OrderResponse
    {
        try {
            // 1. Get basket and user from session
            $session = Registry::getSession();
            $basket = $session->getBasket();
            $user = $basket?->getBasketUser();

            if (!$basket) {
                throw new ShopOrderException(
                    message: 'Basket not found in session',
                    errorCode: 'basket_not_found',
                    context: ['basket_id' => $request->basketId]
                );
            }

            if (!$user || !$user->getId()) {
                throw new ShopOrderException(
                    message: 'User not found',
                    errorCode: 'user_not_found',
                    context: ['user_id' => $request->userId]
                );
            }

            // 2. Set payment transaction ID if provided
            if ($request->paymentTransactionId !== null) {
                $basket->setOrderPaymentTransactionId($request->paymentTransactionId);
            }

            // 3. Set order remark if provided
            if ($request->orderRemark !== null) {
                $session->setVariable('ordRem', $request->orderRemark);
            }

            // 4. Create order using OXID's standard method
            $order = oxNew(Order::class);
            $orderState = $order->finalizeOrder($basket, $user);

            // 5. Validate order creation
            if (!in_array($orderState, [Order::ORDER_STATE_OK, Order::ORDER_STATE_ORDEREXISTS], true)) {
                throw new ShopOrderException(
                    message: 'Order finalization failed',
                    errorCode: $this->mapOrderStateToErrorCode($orderState),
                    context: [
                        'order_state' => $orderState,
                        'basket_id' => $request->basketId,
                        'user_id' => $request->userId,
                    ]
                );
            }

            // 6. Store metadata if provided
            if (!empty($request->metadata)) {
                $this->storeOrderMetadata($order, $request->metadata);
            }

            // 7. Build and return response
            return new OrderResponse(
                orderId: $order->getId(),
                orderNumber: $order->getFieldData('oxordernr'),
                userId: $user->getId(),
                totalAmount: (float) $order->getTotalOrderSum(),
                currency: $basket->getBasketCurrency()->name,
                status: $this->mapOrderStateToStatus($orderState),
                paymentId: $request->paymentId,
                paymentTransactionId: $request->paymentTransactionId,
                createdAt: $this->getOrderCreationDate($order),
                metadata: $request->metadata,
                shopData: [
                    'oxid_order_state' => $orderState,
                    'oxid_order_id' => $order->getId(),
                    'oxid_order_nr' => $order->getFieldData('oxordernr'),
                ]
            );

        } catch (ShopOrderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShopOrderException(
                message: 'Unexpected error during order creation: ' . $e->getMessage(),
                errorCode: 'unexpected_error',
                context: [
                    'exception_class' => get_class($e),
                    'basket_id' => $request->basketId,
                ],
                previous: $e
            );
        }
    }

    /**
     * Map OXID order state to normalized status string.
     */
    private function mapOrderStateToStatus(int $orderState): string
    {
        return match ($orderState) {
            Order::ORDER_STATE_OK => 'completed',
            Order::ORDER_STATE_ORDEREXISTS => 'completed',
            Order::ORDER_STATE_MAILINGERROR => 'completed', // Order created but email failed
            Order::ORDER_STATE_PAYMENTERROR => 'payment_error',
            Order::ORDER_STATE_BELOWMINPRICE => 'below_minimum',
            Order::ORDER_STATE_INVALIDPAYMENT => 'invalid_payment',
            Order::ORDER_STATE_INVALIDDELIVERY => 'invalid_delivery',
            Order::ORDER_STATE_INVALIDDELADDRESSCHANGED => 'invalid_delivery',
            Order::ORDER_STATE_VOUCHERERROR => 'voucher_error',
            default => 'unknown',
        };
    }

    /**
     * Map OXID order state to error code.
     */
    private function mapOrderStateToErrorCode(int $orderState): string
    {
        return match ($orderState) {
            Order::ORDER_STATE_PAYMENTERROR => 'payment_error',
            Order::ORDER_STATE_BELOWMINPRICE => 'below_minimum_price',
            Order::ORDER_STATE_INVALIDPAYMENT => 'invalid_payment_method',
            Order::ORDER_STATE_INVALIDDELIVERY => 'invalid_delivery_method',
            Order::ORDER_STATE_INVALIDDELADDRESSCHANGED => 'invalid_delivery_address',
            Order::ORDER_STATE_VOUCHERERROR => 'voucher_error',
            default => 'order_creation_failed',
        };
    }

    /**
     * Get order creation date.
     */
    private function getOrderCreationDate(Order $order): DateTimeImmutable
    {
        $dateStr = $order->getFieldData('oxorderdate');

        if ($dateStr) {
            try {
                return new DateTimeImmutable($dateStr);
            } catch (\Exception $e) {
                // Fallback to current time if date parsing fails
            }
        }

        return new DateTimeImmutable();
    }

    /**
     * Store additional metadata with the order.
     *
     * This can be extended to store custom data in oxorder table
     * or in a separate metadata table.
     *
     * @param Order $order
     * @param array<string, mixed> $metadata
     */
    private function storeOrderMetadata(Order $order, array $metadata): void
    {
        // Option 1: Store in oxorder.oxremark field
        // $order->oxorder__oxremark = new Field(json_encode($metadata));
        // $order->save();

        // Option 2: Store in custom table (implement if needed)
        // $this->metadataRepository->store($order->getId(), $metadata);

        // For now, just log the metadata
        Registry::getLogger()->debug('Order metadata', [
            'order_id' => $order->getId(),
            'metadata' => $metadata,
        ]);
    }
}
