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
use OxidEsales\Eshop\Core\UtilsObject;
use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentComponent\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentComponent\Adapter\Response\OrderResponse;
use OxidEsales\PaymentComponent\Adapter\Response\PaymentDetailsResponse;
use OxidEsales\PaymentComponent\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentComponent\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentComponent\Contract\Transaction;
use OxidEsales\Payments\Stripe\Module;

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
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository
    ) {
    }

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

            // 10. Build and return response
            // Use basket total directly as source of truth to avoid field loading issues
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

        $order->save();
        $order->setOrderNumber(); // @phpstan-ignore method.notFound

        if (!empty($request->metadata)) {
            $this->storeOrderMetadata($order, $request->metadata);
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
     * Create order for Stripe Checkout session (before payment confirmation).
     *
     * This method creates the order upfront so we can pass the order number
     * to Stripe in the payment intent metadata. The order is created with a
     * pending payment status.
     *
     * Implements idempotency: if an order already exists for this session,
     * it returns the existing order instead of creating a duplicate.
     *
     * @param CreateOrderRequest $request Order creation parameters
     * @param string|null $existingOrderId Optional order ID to check for idempotency
     * @return OrderResponse Created or existing order details
     * @throws ShopOrderException
     */
    public function createOrderForCheckout(CreateOrderRequest $request, ?string $existingOrderId = null): OrderResponse
    {
        // Idempotency check: verify if order already exists
        if ($existingOrderId) {
            /** @var Order $existingOrder */
            $existingOrder = oxNew(Order::class);
            if ($existingOrder->load($existingOrderId)) {
                Registry::getLogger()->info('Reusing existing order for Stripe Checkout', [
                    'order_id' => $existingOrderId,
                    'order_number' => $existingOrder->getFieldData('oxordernr')
                ]);

                $transId = $this->getStringField($existingOrder, 'oxtransid');
                // Return existing order details
                return new OrderResponse(
                    orderId: (string) $existingOrder->getId(),
                    orderNumber: $this->getIntField($existingOrder, 'oxordernr'),
                    userId: $this->getStringField($existingOrder, 'oxuserid'),
                    totalAmount: $this->getFloatField($existingOrder, 'oxtotalordersum'),
                    currency: $this->getStringField($existingOrder, 'oxcurrency') ?: 'EUR',
                    status: 'pending',
                    paymentId: $this->getStringField($existingOrder, 'oxpaymenttype'),
                    paymentTransactionId: $transId !== '' ? $transId : null,
                    createdAt: $this->getOrderCreationDate($existingOrder),
                    metadata: array_merge($request->metadata, ['reused' => true])
                );
            }
        }

        // Create new order using standard flow
        return $this->createOrder($request);
    }

    /**
     * Update existing order with payment transaction ID.
     *
     * Used after Stripe Checkout payment is confirmed to link the payment
     * to the previously created order.
     *
     * @param string $orderId OXID order ID
     * @param string $paymentTransactionId Payment intent ID from Stripe
     * @return void
     * @throws ShopOrderException
     */
    public function updateOrderPaymentTransaction(string $orderId, string $paymentTransactionId): void
    {
        try {
            /** @var Order $order */
            $order = oxNew(Order::class);
            if (!$order->load($orderId)) {
                throw new ShopOrderException(
                    message: 'Order not found for payment transaction update',
                    errorCode: 'order_not_found',
                    context: ['order_id' => $orderId]
                );
            }

            // Update transaction ID
            $order->oxorder__oxtransid = new \OxidEsales\Eshop\Core\Field(
                $paymentTransactionId,
                \OxidEsales\Eshop\Core\Field::T_RAW
            );
            $order->save();

            Registry::getLogger()->info('Order payment transaction updated', [
                'order_id' => $orderId,
                'transaction_id' => $paymentTransactionId
            ]);
        } catch (ShopOrderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ShopOrderException(
                message: 'Failed to update order payment transaction: ' . $e->getMessage(),
                errorCode: 'update_failed',
                context: ['order_id' => $orderId],
                previous: $e
            );
        }
    }

    /**
     * Store transaction and Stripe-specific payment details.
     *
     * Stores payment-related data using Component-level persistence:
     * - Component transaction entity (provider-agnostic)
     * - Stripe-specific payment details (provider-specific)
     * - Order payment date (OXPAID) when payment is captured
     *
     * The Transaction entity already contains all necessary payment state information
     * (status, amount, currency, provider order ID) making separate order state
     * persistence redundant at this level.
     *
     * @param Order $order OXID order object
     * @param PaymentDetailsResponse $paymentDetails Payment details from adapter
     * @return void
     */
    public function storePaymentDetails(Order $order, PaymentDetailsResponse $paymentDetails): void
    {
        // 1. Create and save transaction via Component repository (provider-agnostic)
        $transaction = new Transaction(
            id: UtilsObject::getInstance()->generateUId(),
            shopId: (int) Registry::getConfig()->getShopId(),
            orderId: $order->getId(),
            contractId: null,
            provider: 'stripe',
            type: $paymentDetails->isCaptured ? 'capture' : 'authorization',
            status: $paymentDetails->status,
            amount: $paymentDetails->amount,
            currency: $paymentDetails->currency
        );

        $transaction->setProviderOrderId($paymentDetails->providerPaymentId);
        $paymentMethodId = $order->getFieldData('oxpaymenttype');
        $transaction->setPaymentMethodId(is_string($paymentMethodId) ? $paymentMethodId : null);

        // Extract transaction ID and payment method type from charges
        /** @var array{charges?: array{data?: array<int, array{id?: string, payment_method_details?: array{type?: string}}>}} $providerData */
        $providerData = $paymentDetails->providerData;
        $charges = $providerData['charges']['data'] ?? [];
        $charge = $charges[0] ?? null;
        if ($charge !== null) {
            $transactionId = $charge['id'] ?? null;
            $paymentMethodType = $charge['payment_method_details']['type'] ?? 'card';
            $transaction->setTransactionId(is_string($transactionId) ? $transactionId : null); // @phpstan-ignore function.alreadyNarrowedType
            $transaction->setPaymentMethodType(is_string($paymentMethodType) ? $paymentMethodType : null); // @phpstan-ignore function.alreadyNarrowedType
        }

        // Save transaction - this is the single source of truth for payment state
        $this->transactionRepository->save($transaction);

        // 2. Update order payment date if payment is captured
        if ($paymentDetails->isCaptured && $paymentDetails->capturedAt) {
            $order->oxorder__oxpaid = new \OxidEsales\Eshop\Core\Field(
                $paymentDetails->capturedAt->format('Y-m-d H:i:s'),
                \OxidEsales\Eshop\Core\Field::T_RAW
            );
            $order->save();
        }

        Registry::getLogger()->debug('Payment details stored via Component-level transaction', [
            'order_id' => $order->getId(),
            'transaction_id' => $transaction->getId(),
            'provider_payment_id' => $paymentDetails->providerPaymentId,
            'status' => $paymentDetails->status,
            'amount' => $paymentDetails->amount,
            'currency' => $paymentDetails->currency,
            'is_captured' => $paymentDetails->isCaptured,
            'captured_at' => $paymentDetails->capturedAt?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Build Stripe metadata from order information.
     *
     * Generates metadata to be sent to Stripe's metadata system for tracking
     * and debugging purposes. Stripe's metadata accepts key-value pairs,
     * allowing each piece of information to be stored separately.
     *
     * @param string $orderId OXID order ID
     * @return array<string, string|int> Metadata array for Stripe
     */
    public function buildStripeMetadata(string $orderId): array
    {
        /** @var Order $order */
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            return [
                'order_id' => $orderId,
                'order_number' => 0,
                'error' => 'order_not_found'
            ];
        }

        $orderNumber = $this->getIntField($order, 'oxordernr');
        if ($orderNumber === 0) {
            $order->setOrderNumber(); // @phpstan-ignore method.notFound
            $orderNumber = $this->getIntField($order, 'oxordernr');
        }

        return [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'shop_id' => Registry::getConfig()->getShopId(),
        ];
    }

    /**
     * Get a string field value from an OXID model safely.
     */
    private function getStringField(Order $order, string $fieldName): string
    {
        $value = $order->getFieldData($fieldName);
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Get an int field value from an OXID model safely.
     */
    private function getIntField(Order $order, string $fieldName): int
    {
        $value = $order->getFieldData($fieldName);
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Get a float field value from an OXID model safely.
     */
    private function getFloatField(Order $order, string $fieldName): float
    {
        $value = $order->getFieldData($fieldName);
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Calculate MD5 hash of delivery address for change detection.
     *
     * This method generates a hash of the user's billing and delivery addresses
     * to detect if the address changed during checkout (e.g., between payment
     * initiation and order finalization).
     *
     * @param User $user The user whose addresses to hash
     * @param string|null $deliveryAddressId Optional delivery address ID (if different from billing)
     * @return string MD5 hash of the combined address data
     */
    public function getDeliveryAddressMD5(User $user, ?string $deliveryAddressId = null): string
    {
        // Start with billing address
        $addressData = $user->getEncodedDeliveryAddress();

        // Add delivery address if specified
        $deliveryAddressId = $deliveryAddressId ?? Registry::getSession()->getVariable('deladrid');

        if ($deliveryAddressId && is_string($deliveryAddressId)) {
            /** @var \OxidEsales\Eshop\Application\Model\Address $deliveryAddress */
            $deliveryAddress = oxNew(\OxidEsales\Eshop\Application\Model\Address::class);
            if ($deliveryAddress->load($deliveryAddressId)) {
                $addressData .= $deliveryAddress->getEncodedDeliveryAddress();
            }
        }

        // Return MD5 hash as the method name implies
        return $addressData;
    }
}
