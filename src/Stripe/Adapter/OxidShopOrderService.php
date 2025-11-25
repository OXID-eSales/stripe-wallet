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
use OxidEsales\Eshop\Core\UtilsObject;
use OxidSolutionCatalysts\Payments\Component\Adapter\ShopOrderServiceInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreateOrderRequest;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\OrderResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse;
use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\ShopOrderException;
use OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Transaction\Transaction;
use OxidSolutionCatalysts\Payments\Stripe\Repository\StripePaymentDetailsRepository;

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
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly StripePaymentDetailsRepository $stripeDetailsRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createOrder(CreateOrderRequest $request): OrderResponse
    {
        try {
            // 1. Get basket and user from request
            $basket = $request->getBasket();
            $user = $basket?->getBasketUser();

            if (!$basket) {
                throw new ShopOrderException(
                    message: 'Basket not found in session',
                    errorCode: 'basket_not_found',
                    context: ['session_id' => $request->sessionId]
                );
            }

            if (!$user || !$user->getId()) {
                throw new ShopOrderException(
                    message: 'User not found',
                    errorCode: 'user_not_found',
                    context: ['user_id' => $request->userId]
                );
            }

            // 2. Set order remark if provided
            if ($request->orderRemark !== null) {
                Registry::getSession()->setVariable('ordRem', $request->orderRemark);
            }

            // 3. Create order using OXID's standard method
            $order = oxNew(Order::class);
            $orderState = $order->finalizeOrder($basket, $user);

            // 4. Validate order creation
            if (!in_array($orderState, [Order::ORDER_STATE_OK, Order::ORDER_STATE_ORDEREXISTS], true)) {
                throw new ShopOrderException(
                    message: 'Order finalization failed',
                    errorCode: $this->mapOrderStateToErrorCode($orderState),
                    context: [
                        'order_state' => $orderState,
                        'session_id' => $request->sessionId,
                        'user_id' => $request->userId,
                    ]
                );
            }

            // 5. Set payment transaction ID on order if provided
            if ($request->paymentTransactionId !== null) {
                $order->oxorder__oxtransid = new \OxidEsales\Eshop\Core\Field(
                    $request->paymentTransactionId,
                    \OxidEsales\Eshop\Core\Field::T_RAW
                );
                $order->save();
            }

            // 6. Ensure order number is always set after successful finalization
            // This MUST be called before accessing oxordernr field
            // The Stripe Order extension overrides setOrderNumber() to ensure it's always set
            $order->setOrderNumber();

            // 7. Store metadata if provided
            if (!empty($request->metadata)) {
                $this->storeOrderMetadata($order, $request->metadata);
            }

            // 8. Build and return response
            // Use basket total directly as source of truth to avoid field loading issues
            return new OrderResponse(
                orderId: $order->getId(),
                orderNumber: $order->getFieldData('oxordernr'),
                userId: $user->getId(),
                totalAmount: (float) $basket->getPrice()->getBruttoPrice(),
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
                    'session_id' => $request->sessionId,
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
        $transaction->setPaymentMethodId($order->getFieldData('oxpaymenttype'));

        // Extract transaction ID and payment method type from charges
        $charge = $paymentDetails->providerData['charges']['data'][0] ?? null;
        if ($charge) {
            $transaction->setTransactionId($charge['id']);
            $transaction->setPaymentMethodType($charge['payment_method_details']['type'] ?? 'card');
        }

        // Save transaction - this is the single source of truth for payment state
        $this->transactionRepository->save($transaction);

        // 2. Store Stripe-specific payment details (provider-specific metadata)
        if ($charge) {
            $this->stripeDetailsRepository->storePaymentDetails($transaction->getId(), $charge);
        }

        // 3. Update order payment date if payment is captured
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
}
