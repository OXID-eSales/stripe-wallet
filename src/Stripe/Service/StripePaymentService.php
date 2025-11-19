<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\UtilsObject;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\OrderCreatedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentCapturedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentRefundedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentFailedEvent;
use OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Transaction\Transaction;

/**
 * Stripe payment processing service
 *
 * This is the core payment processing service that orchestrates the entire payment
 * lifecycle from payment intent creation through order finalization.
 *
 * Responsibilities:
 * - Creates Stripe PaymentIntents for shopping baskets
 * - Retrieves and monitors payment status from Stripe
 * - Creates OXID orders after successful payment using Order::finalizeOrder()
 * - Stores transaction records in database
 * - Handles payment capture (automatic and manual modes)
 * - Processes refunds through Stripe API
 * - Converts currency amounts between EUR and cents
 * - Extracts charge details and card information
 *
 * Component Integration:
 * ✅ Uses Component EventSystem for event-driven architecture
 * ✅ Uses Component Transaction repository for database persistence
 * ✅ Dispatches Component events: PaymentInitiatedEvent, OrderCreatedEvent,
 *    PaymentCapturedEvent, PaymentRefundedEvent
 * ✅ Provides fallback for installations without Component
 *
 * Architecture:
 * - Uses OXID's standard Order::finalizeOrder() method for order creation
 * - Maintains transaction audit trail
 * - Supports both automatic and manual capture modes
 * - Integrates with StripeCustomerService for customer management
 *
 * Initialization:
 * This service supports lazy initialization and can be constructed without API keys.
 * It will initialize automatically when first used if configuration is available.
 *
 * @package OxidSolutionCatalysts\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 */
class StripePaymentService implements InitializableServiceInterface
{
    use InitializableServiceTrait;

    private ?StripeClient $stripe = null;
    private ModuleConfigurationService $config;
    private StripeCustomerService $customerService;
    private ?EventDispatcherInterface $eventDispatcher;
    private ?TransactionRepositoryInterface $transactionRepository;

    public function __construct(
        ModuleConfigurationService $config,
        StripeCustomerService $customerService,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?TransactionRepositoryInterface $transactionRepository = null
    ) {
        $this->config = $config;
        $this->customerService = $customerService;
        $this->eventDispatcher = $eventDispatcher;
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * @inheritDoc
     */
    public function canInitialize(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * @inheritDoc
     */
    protected function doInitialize(): void
    {
        $secretKey = $this->config->getToken();

        if (empty($secretKey)) {
            throw new \RuntimeException('Stripe secret key is not configured');
        }

        $this->stripe = new StripeClient($secretKey);
    }

    /**
     * Create Stripe PaymentIntent for basket
     *
     * @param Basket $basket
     * @param User $user
     * @return array PaymentIntent data
     * @throws \RuntimeException
     */
    public function createPaymentIntent(Basket $basket, User $user): array
    {
        $this->ensureInitialized();

        try {
            // Get or create Stripe customer
            $stripeCustomerId = $this->customerService->getOrCreateStripeCustomer($user);

            // Calculate amount in cents
            $amount = $this->convertToCents($basket->getPrice()->getBruttoPrice());
            $currency = strtolower($basket->getBasketCurrency()->name);

            // Prepare metadata
            $metadata = [
                'oxid_user_id' => $user->getId(),
                'customer_email' => $user->getFieldData('oxusername'),
                'customer_name' => $user->getFieldData('oxfname') . ' ' . $user->getFieldData('oxlname'),
            ];

            // Create PaymentIntent
            $paymentIntent = $this->stripe->paymentIntents->create([
'PaymentMethodHere!!!' => 123,
                'amount' => $amount,
                'currency' => $currency,
                'customer' => $stripeCustomerId,
                'metadata' => $metadata,
                'description' => 'Order from ' . Registry::getConfig()->getActiveShop()->getFieldData('oxname'),
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                'capture_method' => $this->config->getCaptureMode(),
            ]);

            Registry::getLogger()->info('Stripe PaymentIntent created', [
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $amount,
                'currency' => $currency,
            ]);

            // Dispatch PaymentInitiatedEvent
            $this->dispatchPaymentInitiatedEvent($basket, $user, $paymentIntent);

            return [
                'id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
            ];

        } catch (ApiErrorException $e) {
            Registry::getLogger()->error('Stripe PaymentIntent creation failed', [
                'error' => $e->getMessage(),
                'code' => $e->getStripeCode(),
            ]);

            throw new \RuntimeException(
                'Failed to create payment: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Retrieve PaymentIntent details
     *
     * @param string $paymentIntentId
     * @return array
     * @throws \RuntimeException
     */
    public function getPaymentIntent(string $paymentIntentId): array
    {
        $this->ensureInitialized();

        try {
            $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);

            return [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'charges' => $this->extractCharges($paymentIntent),
                'next_action' => $paymentIntent->next_action,
                'client_secret' => $paymentIntent->client_secret,
            ];

        } catch (ApiErrorException $e) {
            Registry::getLogger()->error('Failed to retrieve PaymentIntent', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Failed to retrieve payment: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Create order using standard OXID method
     * ✅ Uses Order::finalizeOrder() for compatibility
     *
     * @param Basket $basket
     * @param User $user
     * @param string $paymentIntentId
     * @return Order
     * @throws \RuntimeException
     */
    public function createOrderAfterPayment(
        Basket $basket,
        User $user,
        string $paymentIntentId
    ): Order {
        // Verify payment succeeded
        $paymentIntent = $this->getPaymentIntent($paymentIntentId);

        if ($paymentIntent['status'] !== 'succeeded') {
            throw new \RuntimeException(
                'Payment not successful: ' . $paymentIntent['status']
            );
        }

        // Set payment method on basket
        $basket->setPayment('osc_stripe_card');

        // ✅ USE STANDARD OXID METHOD
        $order = oxNew(Order::class);
        $orderState = $order->finalizeOrder($basket, $user);

        // Check order creation result
        if ($orderState !== Order::ORDER_STATE_OK) {
            throw new \RuntimeException(
                'Order creation failed with state: ' . $orderState
            );
        }

        // Store transaction data
        $this->storeTransaction($order, $paymentIntent);

        // Update payment order state
        $this->updatePaymentOrderState($order->getId(), $paymentIntent);

        Registry::getLogger()->info('Order created successfully', [
            'order_id' => $order->getId(),
            'order_number' => $order->getFieldData('oxordernr'),
            'payment_intent_id' => $paymentIntentId,
        ]);

        // Dispatch OrderCreatedEvent
        $this->dispatchOrderCreatedEvent($order, $basket, $user, $paymentIntentId);

        // Dispatch PaymentCapturedEvent (payment was captured during order creation)
        $this->dispatchPaymentCapturedEvent($order, $paymentIntent);

        return $order;
    }

    /**
     * Store transaction record using Component Transaction repository
     * ✅ Uses Component Transaction entity and repository
     *
     * @param Order $order
     * @param array $paymentIntent
     */
    public function storeTransaction(Order $order, array $paymentIntent): void
    {
        $charges = $paymentIntent['charges'] ?? [];
        $charge = $charges[0] ?? null;

        // Create Component Transaction entity
        $transaction = new Transaction(
            id: UtilsObject::getInstance()->generateUId(),
            shopId: (int) Registry::getConfig()->getShopId(),
            orderId: $order->getId(),
            contractId: null, // Standard checkout doesn't use contracts
            provider: 'stripe',
            type: 'payment',
            status: $paymentIntent['status'],
            amount: $this->convertFromCents((int) $paymentIntent['amount']),
            currency: strtoupper($paymentIntent['currency'])
        );

        // Set optional fields
        $transaction->setProviderOrderId($paymentIntent['id']);

        if ($charge) {
            $transaction->setTransactionId($charge['id']);
        }

        $transaction->setPaymentMethodId('osc_stripe_card');
        $transaction->setPaymentMethodType($charge['payment_method_details']['type'] ?? 'card');

        // Save using Component repository if available
        if ($this->transactionRepository) {
            $this->transactionRepository->save($transaction);
        } else {
            // Fallback to raw SQL if repository not available (backward compatibility)
            $this->storeTransactionFallback($transaction);
        }

        // Store Stripe-specific details in separate table
        if ($charge) {
            $this->storeStripeDetails($transaction->getId(), $charge);
        }
    }

    /**
     * Store Stripe-specific payment details
     * Separated from main transaction to follow provider-agnostic pattern
     *
     * @param string $transactionId
     * @param array $charge
     */
    private function storeStripeDetails(string $transactionId, array $charge): void
    {
        $db = DatabaseProvider::getDb();

        $card = $charge['payment_method_details']['card'] ?? null;
        $threeDSecure = $card['three_d_secure'] ?? null;

        $sql = "INSERT INTO osc_stripe_payment_details
                (OXID, OXTRANSACTIONID, OXCARDLAST4, OXCARDBRAND, OXCARDEXPMONTH, OXCARDEXPYEAR,
                 OXCARDFUNDING, OXCARDCOUNTRY, OX3DSECURE, OX3DSVERSION, OX3DSAUTHENTICATED,
                 OXRISKSCORE, OXRISKLEVEL, OXCREATED)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $db->execute($sql, [
            UtilsObject::getInstance()->generateUId(),
            $transactionId,
            $card['last4'] ?? null,
            $card['brand'] ?? null,
            $card['exp_month'] ?? null,
            $card['exp_year'] ?? null,
            $card['funding'] ?? null,
            $card['country'] ?? null,
            $threeDSecure ? 1 : 0,
            $threeDSecure['version'] ?? null,
            $threeDSecure['authenticated'] ?? null,
            $charge['outcome']['risk_score'] ?? null,
            $charge['outcome']['risk_level'] ?? null,
        ]);
    }

    /**
     * Fallback method to store transaction using raw SQL
     * Used when Component repository is not available
     *
     * @param Transaction $transaction
     */
    private function storeTransactionFallback(Transaction $transaction): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "INSERT INTO osc_payment_transaction
                (OXID, OXSHOPID, OXORDERID, OXCONTRACTID, OXPROVIDER, OXPROVIDERORDERID,
                 OXTRANSACTIONID, OXTYPE, OXSTATUS, OXAMOUNT, OXCURRENCY,
                 OXPAYMENTMETHODID, OXPAYMENTMETHODTYPE, OXPARENTTRANSACTIONID,
                 OXCREATED, OXUPDATED)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $db->execute($sql, [
            $transaction->getId(),
            $transaction->getShopId(),
            $transaction->getOrderId(),
            $transaction->getContractId(),
            $transaction->getProvider(),
            $transaction->getProviderOrderId(),
            $transaction->getTransactionId(),
            $transaction->getType(),
            $transaction->getStatus(),
            $transaction->getAmount(),
            $transaction->getCurrency(),
            $transaction->getPaymentMethodId(),
            $transaction->getPaymentMethodType(),
            $transaction->getParentTransactionId(),
        ]);
    }

    /**
     * Update order payment state
     *
     * @param string $orderId
     * @param array $paymentIntent
     */
    private function updatePaymentOrderState(string $orderId, array $paymentIntent): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "INSERT INTO osc_payment_order_state
                (OXID, OXORDERID, OXPAYMENTSTATE, OXPAYMENTMETHOD, OXCAPTURED,
                 OXCAPTUREDAMOUNT, OXCAPTUREDAT, OXCREATED)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                OXPAYMENTSTATE = VALUES(OXPAYMENTSTATE),
                OXCAPTURED = VALUES(OXCAPTURED),
                OXCAPTUREDAMOUNT = VALUES(OXCAPTUREDAMOUNT),
                OXCAPTUREDAT = VALUES(OXCAPTUREDAT),
                OXUPDATED = NOW()";

        $db->execute($sql, [
            UtilsObject::getInstance()->generateUId(),
            $orderId,
            'paid',
            'stripe',
            1,
            $this->convertFromCents((int) $paymentIntent['amount']),
        ]);
    }

    /**
     * Create refund for a payment
     *
     * @param string $paymentIntentId
     * @param float|null $amount
     * @param string|null $reason
     * @return array
     * @throws \RuntimeException
     */
    public function createRefund(
        string $paymentIntentId,
        ?float $amount = null,
        ?string $reason = null
    ): array {
        $this->ensureInitialized();

        try {
            $params = [
                'payment_intent' => $paymentIntentId,
            ];

            if ($amount !== null) {
                $params['amount'] = $this->convertToCents($amount);
            }

            if ($reason) {
                $params['reason'] = $reason;
            }

            $refund = $this->stripe->refunds->create($params);

            Registry::getLogger()->info('Stripe refund created', [
                'refund_id' => $refund->id,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $refund->amount,
            ]);

            return [
                'id' => $refund->id,
                'amount' => $this->convertFromCents($refund->amount),
                'currency' => $refund->currency,
                'status' => $refund->status,
                'reason' => $refund->reason,
            ];

        } catch (ApiErrorException $e) {
            Registry::getLogger()->error('Stripe refund failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Refund failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Extract charges from PaymentIntent
     *
     * @param \Stripe\PaymentIntent $paymentIntent
     * @return array
     */
    private function extractCharges($paymentIntent): array
    {
        if (!isset($paymentIntent->charges->data)) {
            return [];
        }

        $charges = [];

        foreach ($paymentIntent->charges->data as $charge) {
            $charges[] = [
                'id' => $charge->id,
                'amount' => $charge->amount,
                'status' => $charge->status,
                'paid' => $charge->paid,
                'payment_method_details' => [
                    'type' => $charge->payment_method_details->type ?? null,
                    'card' => [
                        'brand' => $charge->payment_method_details->card->brand ?? null,
                        'last4' => $charge->payment_method_details->card->last4 ?? null,
                        'exp_month' => $charge->payment_method_details->card->exp_month ?? null,
                        'exp_year' => $charge->payment_method_details->card->exp_year ?? null,
                        'three_d_secure' => $charge->payment_method_details->card->three_d_secure ?? null,
                    ],
                ],
            ];
        }

        return $charges;
    }

    /**
     * Convert amount to cents (Stripe format)
     *
     * @param float $amount
     * @return int
     */
    private function convertToCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Convert amount from cents to decimal
     *
     * @param int $cents
     * @return float
     */
    private function convertFromCents(int $cents): float
    {
        return $cents / 100;
    }

    /**
     * Dispatch PaymentInitiatedEvent
     *
     * @param Basket $basket
     * @param User $user
     * @param \Stripe\PaymentIntent $paymentIntent
     * @return void
     */
    private function dispatchPaymentInitiatedEvent(Basket $basket, User $user, $paymentIntent): void
    {
        if (!$this->eventDispatcher) {
            return;
        }

        $context = new EventContext([
            'basket' => $basket,
            'user' => $user,
            'paymentIntentId' => $paymentIntent->id,
        ]);

        $event = new PaymentInitiatedEvent(
            context: $context,
            paymentMethodId: 'osc_stripe_card',
            amount: $this->convertFromCents($paymentIntent->amount),
            currency: strtoupper($paymentIntent->currency),
            returnUrl: Registry::getConfig()->getShopUrl() . 'index.php?cl=order&fnc=return3DS',
            cancelUrl: Registry::getConfig()->getShopUrl() . 'index.php?cl=payment'
        );

        $event->setProviderOrderId($paymentIntent->id);

        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Dispatch OrderCreatedEvent
     *
     * @param Order $order
     * @param Basket $basket
     * @param User $user
     * @param string $paymentIntentId
     * @return void
     */
    private function dispatchOrderCreatedEvent(
        Order $order,
        Basket $basket,
        User $user,
        string $paymentIntentId
    ): void {
        if (!$this->eventDispatcher) {
            return;
        }

        $context = new EventContext([
            'basket' => $basket,
            'user' => $user,
            'orderId' => $order->getId(),
            'paymentIntentId' => $paymentIntentId,
        ]);

        // Note: Component OrderCreatedEvent expects contractId
        // For standard checkout without contracts, we pass empty string
        $event = new OrderCreatedEvent(
            context: $context,
            orderId: $order->getId(),
            contractId: '' // Standard checkout doesn't use contracts
        );

        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Dispatch PaymentCapturedEvent
     *
     * @param Order $order
     * @param array $paymentIntent
     * @return void
     */
    private function dispatchPaymentCapturedEvent(Order $order, array $paymentIntent): void
    {
        if (!$this->eventDispatcher) {
            return;
        }

        $charges = $paymentIntent['charges'] ?? [];
        $charge = $charges[0] ?? null;

        if (!$charge) {
            return;
        }

        $context = new EventContext([
            'orderId' => $order->getId(),
            'paymentIntentId' => $paymentIntent['id'],
        ]);

        $event = new PaymentCapturedEvent(
            context: $context,
            authorizationId: $paymentIntent['id'],
            captureId: $charge['id'],
            capturedAmount: $this->convertFromCents($paymentIntent['amount']),
            currency: strtoupper($paymentIntent['currency'])
        );

        $this->eventDispatcher->dispatch($event);
    }

    /**
     * Dispatch PaymentRefundedEvent
     *
     * @param Order $order
     * @param array $refund
     * @return void
     */
    private function dispatchPaymentRefundedEvent(Order $order, array $refund): void
    {
        if (!$this->eventDispatcher) {
            return;
        }

        $context = new EventContext([
            'orderId' => $order->getId(),
            'refundId' => $refund['id'],
        ]);

        $event = new PaymentRefundedEvent(
            context: $context,
            refundId: $refund['id'],
         //   refundedAmount: $this->convertFromCents($refund['amount']),
            currency: strtoupper($refund['currency']),
          //  reason: $refund['reason'] ?? null
        );

        $this->eventDispatcher->dispatch($event);
    }
}
