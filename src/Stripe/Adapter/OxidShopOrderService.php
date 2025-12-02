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
use OxidSolutionCatalysts\Payments\Stripe\Module;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;
use OxidEsales\Eshop\Core\ShopVersion;

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
        private readonly StripePaymentDetailsRepository $stripeDetailsRepository,
        private readonly ModuleConfigurationService $moduleConfig
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
            // Note: Using false for $blRecalculatingOrder ensures:
            // - Order ID is set from session
            // - Order validation runs
            // - Products and customer data are properly saved
            // - executePayment() is called BUT overridden in Stripe\Model\Order to skip payment gateway
            $order = oxNew(Order::class);
            $orderState = $order->finalizeOrder($basket, $user, false);

            // 4. Validate order creation
            if (!in_array($orderState, [Order::ORDER_STATE_OK, Order::ORDER_STATE_ORDEREXISTS], true)) {
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

            // 5. Set order folder to NEW (default for new orders)
            $order->oxorder__oxfolder = new \OxidEsales\Eshop\Core\Field(
                'ORDERFOLDER_NEW',
                \OxidEsales\Eshop\Core\Field::T_RAW
            );

            // 6. Set payment transaction ID on order if provided
            if ($request->paymentTransactionId !== null) {
                $order->oxorder__oxtransid = new \OxidEsales\Eshop\Core\Field(
                    $request->paymentTransactionId,
                    \OxidEsales\Eshop\Core\Field::T_RAW
                );
            }

            // 7. Save order with folder and optional transaction ID
            $order->save();

            // 8. Ensure order number is always set after successful finalization
            // This MUST be called before accessing oxordernr field
            // The Stripe Order extension overrides setOrderNumber() to ensure it's always set
            $order->setOrderNumber();

            // 9. Store metadata if provided
            if (!empty($request->metadata)) {
                $this->storeOrderMetadata($order, $request->metadata);
            }

            // 10. Build and return response
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
            $existingOrder = oxNew(Order::class);
            if ($existingOrder->load($existingOrderId)) {
                Registry::getLogger()->info('Reusing existing order for Stripe Checkout', [
                    'order_id' => $existingOrderId,
                    'order_number' => $existingOrder->getFieldData('oxordernr')
                ]);

                // Return existing order details
                return new OrderResponse(
                    orderId: $existingOrder->getId(),
                    orderNumber: (int)$existingOrder->getFieldData('oxordernr'),
                    userId: $existingOrder->getFieldData('oxuserid'),
                    totalAmount: (float)$existingOrder->getFieldData('oxtotalordersum'),
                    currency: $existingOrder->getFieldData('oxcurrency'),
                    status: 'pending',
                    paymentId: $existingOrder->getFieldData('oxpaymenttype'),
                    paymentTransactionId: $existingOrder->getFieldData('oxtransid') ?: null,
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

    /**
     * Set order folder status.
     *
     * @param string $orderId OXID order ID
     * @param string $folder Folder identifier (e.g., 'ORDERFOLDER_NEW', 'ORDERFOLDER_FINISHED')
     * @return void
     */
    private function setOrderFolder(string $orderId, string $folder): void
    {
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            Registry::getLogger()->warning('Order not found for folder update', [
                'order_id' => $orderId
            ]);
            return;
        }

        $order->oxorder__oxfolder = new \OxidEsales\Eshop\Core\Field(
            $folder,
            \OxidEsales\Eshop\Core\Field::T_RAW
        );
        $order->save();

        Registry::getLogger()->debug('Order folder updated', [
            'order_id' => $orderId,
            'folder' => $folder
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
     * @param bool $includeVersionInfo Whether to include module/OXID version info for debugging
     * @return array<string, string|int> Metadata array for Stripe
     */
    public function buildStripeMetadata(string $orderId, bool $includeVersionInfo = false): array
    {
        // Load order object
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            // If order not found, return minimal metadata
            return [
                'order_id' => $orderId,
                'order_number' => 0,
                'error' => 'order_not_found'
            ];
        }

        // Extract order number
        $orderNumber = (int) $order->getFieldData('oxordernr');

        // Ensure order number is set
        if ($orderNumber === 0) {
            $order->setOrderNumber();
            $orderNumber = (int) $order->getFieldData('oxordernr');
        }

        // Build base metadata
        $metadata = [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'shop_id' => Registry::getConfig()->getShopId(),
        ];

        // Add version information if requested (useful for debugging/support)
        if ($includeVersionInfo) {
            $module = oxNew(\OxidEsales\Eshop\Core\Module\Module::class);
            $module->load(Module::MODULE_ID);

            $metadata['module_version'] = $module->getInfo('version') ?: 'unknown';
            $metadata['oxid_version'] = ShopVersion::getVersion();
        }

        return $metadata;
    }

    /**
     * @deprecated Use buildStripeMetadata() instead
     * @see buildStripeMetadata()
     */
    public function getCustomIdParameter(string $orderId): string
    {
        $metadata = $this->buildStripeMetadata($orderId, false);
        return (string) $metadata['order_number'];
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

        if ($deliveryAddressId) {
            $deliveryAddress = oxNew(\OxidEsales\Eshop\Application\Model\Address::class);
            if ($deliveryAddress->load($deliveryAddressId)) {
                $addressData .= $deliveryAddress->getEncodedDeliveryAddress();
            }
        }

        // Return MD5 hash as the method name implies
        return $addressData;
    }
}
