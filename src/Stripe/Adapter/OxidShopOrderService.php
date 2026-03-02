<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Adapter;

use DateTimeImmutable;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentComponent\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentComponent\Adapter\Response\OrderResponse;
use OxidEsales\PaymentComponent\Adapter\Exception\ShopOrderException;

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
    // No constructor dependencies — all OXID operations use Registry/oxNew.

    /**
     * @inheritDoc
     */
    public function createOrder(CreateOrderRequest $request): OrderResponse
    {
        try {
            [$basket, $user] = $this->validateBasketAndUser($request);

            if ($request->orderRemark !== null) {
                Registry::getSession()->setVariable('ordRem', $request->orderRemark);
            }

            /** @var Order $order */
            $order = oxNew(Order::class);
            /** @var int $orderState */
            $orderState = $order->finalizeOrder($basket, $user, false);

            $this->validateOrderState($orderState, $request, $basket);
            $this->setOrderFieldsAfterCreation($order, $request);

            return $this->buildOrderResponse($order, $basket, $user, $orderState, $request);
        } catch (ShopOrderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShopOrderException(
                message: 'Unexpected error during order creation: ' . $e->getMessage(),
                errorCode: 'unexpected_error',
                context: [
                    'exception_class' => get_class($e),
                    'session_id' => $request->sessionId,
                ],
                previous: $e
            );
        }
    }

    /**
     * Build OrderResponse from order creation results.
     */
    private function buildOrderResponse(
        Order $order,
        Basket $basket,
        User $user,
        int $orderState,
        CreateOrderRequest $request
    ): OrderResponse {
        $price = $basket->getPrice();
        $currency = $basket->getBasketCurrency();
        /** @var string $currencyName */
        $currencyName = $currency->name ?? 'EUR';

        return new OrderResponse(
            orderId: (string) $order->getId(),
            orderNumber: $this->getIntField($order, 'oxordernr'),
            userId: (string) $user->getId(),
            totalAmount: $price ? (float) $price->getBruttoPrice() : 0.0, // @phpstan-ignore ternary.alwaysTrue
            currency: $currencyName,
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
    }

    /**
     * Validate basket and user exist in session.
     *
     * @return array{Basket, User}
     * @throws ShopOrderException
     */
    private function validateBasketAndUser(CreateOrderRequest $request): array
    {
        /** @var Basket|null $basket */
        $basket = Registry::getSession()->getBasket();
        if (!$basket) {
            throw new ShopOrderException(
                message: 'Basket not found in session',
                errorCode: 'basket_not_found',
                context: ['session_id' => $request->sessionId]
            );
        }

        /** @var User|null $user */
        $user = $basket->getBasketUser();
        if (!$user || !$user->getId()) {
            throw new ShopOrderException(
                message: 'User not found',
                errorCode: 'user_not_found',
                context: ['user_id' => $request->userId]
            );
        }

        return [$basket, $user];
    }

    /**
     * Validate order finalization state.
     *
     * @throws ShopOrderException
     */
    private function validateOrderState(int $orderState, CreateOrderRequest $request, Basket $basket): void
    {
        if (in_array($orderState, [Order::ORDER_STATE_OK, Order::ORDER_STATE_ORDEREXISTS], true)) {
            return;
        }

        $errorCode = $this->mapOrderStateToErrorCode($orderState);
        Registry::getLogger()->error('OxidShopOrderService: Order finalization failed', [
            'order_state' => $orderState,
            'error_code' => $errorCode,
            'session_id' => $request->sessionId,
            'user_id' => $request->userId,
            'payment_id' => $request->paymentId,
            'basket_count' => $basket->getProductsCount(),
            'basket_total' => $basket->getPrice()->getBruttoPrice(),
        ]);
        throw new ShopOrderException(
            message: 'Order finalization failed with state: ' . $orderState . ' (' . $errorCode . ')',
            errorCode: $errorCode,
            context: [
                'order_state' => $orderState,
                'session_id' => $request->sessionId,
                'user_id' => $request->userId,
            ]
        );
    }

    /**
     * Set order folder, transaction ID, order number, and metadata after creation.
     */
    private function setOrderFieldsAfterCreation(Order $order, CreateOrderRequest $request): void
    {
        $order->oxorder__oxfolder = new \OxidEsales\Eshop\Core\Field(
            'ORDERFOLDER_NEW',
            \OxidEsales\Eshop\Core\Field::T_RAW
        );

        if ($request->paymentTransactionId !== null) {
            $order->oxorder__oxtransid = new \OxidEsales\Eshop\Core\Field(
                $request->paymentTransactionId,
                \OxidEsales\Eshop\Core\Field::T_RAW
            );
        }

        if ($request->initialStatus !== null) {
            $order->oxorder__oxtransstatus = new \OxidEsales\Eshop\Core\Field(
                $request->initialStatus,
                \OxidEsales\Eshop\Core\Field::T_RAW
            );
        }

        $order->save();
        $order->setOrderNumber(); // @phpstan-ignore method.notFound

        if (!empty($request->metadata)) {
            $this->storeOrderMetadata($order, $request->metadata);
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteNotFinishedOrder(string $orderId): bool
    {
        /** @var Order $order */
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            return false;
        }

        $status = $order->getFieldData('oxtransstatus');
        if ($status !== 'NOT_FINISHED') {
            return false;
        }

        return (bool) $order->delete();
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

        if ($dateStr && is_string($dateStr)) {
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

    /**
     * Get an int field value from an OXID model safely.
     */
    private function getIntField(Order $order, string $fieldName): int
    {
        $value = $order->getFieldData($fieldName);
        return is_numeric($value) ? (int) $value : 0;
    }
}
