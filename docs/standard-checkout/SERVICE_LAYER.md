# Service Layer Implementation

**Payment Service and Business Logic**
**Version:** 1.0.0
**Date:** 2025-11-13

---

## Overview

The service layer contains all business logic for payment processing, keeping controllers thin and focused on HTTP request/response handling. This document covers the complete implementation of the Stripe payment service.

---

## Architecture

```
┌────────────────────────────────────────────────────────┐
│                  Controller Layer                       │
│   (HTTP handling, validation, response formatting)     │
└────────────────────────────────────────────────────────┘
                         │
                         ▼
┌────────────────────────────────────────────────────────┐
│                   Service Layer                         │
│  ┌──────────────────────────────────────────────────┐ │
│  │  StripePaymentService                             │ │
│  │  - createPaymentIntent()                          │ │
│  │  - confirmPaymentIntent()                         │ │
│  │  - handlePaymentSuccess()                         │ │
│  │  - handle3DSecure()                               │ │
│  │  - storeTransaction()                             │ │
│  └──────────────────────────────────────────────────┘ │
│  ┌──────────────────────────────────────────────────┐ │
│  │  StripeCustomerService                            │ │
│  │  - getOrCreateStripeCustomer()                    │ │
│  │  - syncCustomerData()                             │ │
│  └──────────────────────────────────────────────────┘ │
│  ┌──────────────────────────────────────────────────┐ │
│  │  PaymentTransactionService                        │ │
│  │  - createTransaction()                            │ │
│  │  - updateTransaction()                            │ │
│  │  - getTransactionByOrderId()                      │ │
│  └──────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────┘
                         │
                         ▼
┌────────────────────────────────────────────────────────┐
│                  Repository Layer                       │
│            (Database access, queries)                   │
└────────────────────────────────────────────────────────┘
                         │
                         ▼
┌────────────────────────────────────────────────────────┐
│                    Stripe API                           │
└────────────────────────────────────────────────────────┘
```

---

## StripePaymentService

Complete implementation of the main payment service.

### File: `src/Service/StripePaymentService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Service;

use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidSolutionCatalysts\Stripe\Repository\PaymentTransactionRepository;

/**
 * Stripe payment processing service
 */
class StripePaymentService
{
    private StripeClient $stripe;
    private StripeConfigurationService $config;
    private StripeCustomerService $customerService;
    private PaymentTransactionRepository $transactionRepo;

    public function __construct(
        StripeConfigurationService $config,
        StripeCustomerService $customerService,
        PaymentTransactionRepository $transactionRepo
    ) {
        $this->config = $config;
        $this->customerService = $customerService;
        $this->transactionRepo = $transactionRepo;
        $this->initializeStripeClient();
    }

    /**
     * Initialize Stripe client with API key
     */
    private function initializeStripeClient(): void
    {
        $this->stripe = new StripeClient($this->config->getSecretKey());
    }

    /**
     * Create Stripe PaymentIntent for basket
     *
     * @param Basket $basket Customer basket
     * @param User $user Customer user object
     * @return array PaymentIntent data
     * @throws \RuntimeException
     */
    public function createPaymentIntent(Basket $basket, User $user): array
    {
        try {
            // Get or create Stripe customer
            $stripeCustomerId = $this->customerService->getOrCreateStripeCustomer($user);

            // Calculate amount in cents
            $amount = $this->convertToCents($basket->getPrice()->getBruttoPrice());
            $currency = strtolower($basket->getBasketCurrency()->name);

            // Prepare metadata
            $metadata = [
                'oxid_user_id' => $user->getId(),
                'oxid_basket_id' => $basket->getBasketId(),
                'customer_email' => $user->getFieldData('oxusername'),
                'customer_name' => $user->getFieldData('oxfname') . ' ' . $user->getFieldData('oxlname'),
            ];

            // Create PaymentIntent
            $paymentIntent = $this->stripe->paymentIntents->create([
                'amount' => $amount,
                'currency' => $currency,
                'customer' => $stripeCustomerId,
                'metadata' => $metadata,
                'description' => 'Order from ' . Registry::getConfig()->getActiveShop()->getFieldData('oxname'),

                // Automatic payment methods (card, etc.)
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],

                // Capture mode
                'capture_method' => $this->config->getCaptureMode(),

                // Setup future usage (optional)
                // 'setup_future_usage' => 'off_session',
            ]);

            Registry::getLogger()->info('Stripe PaymentIntent created', [
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $amount,
                'currency' => $currency,
            ]);

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
     * Confirm PaymentIntent after customer authorization
     *
     * @param string $paymentIntentId Stripe PaymentIntent ID
     * @param string|null $paymentMethodId Payment method ID (if not already attached)
     * @return array Payment result
     * @throws \RuntimeException
     */
    public function confirmPaymentIntent(string $paymentIntentId, ?string $paymentMethodId = null): array
    {
        try {
            $params = [];

            // Attach payment method if provided
            if ($paymentMethodId) {
                $params['payment_method'] = $paymentMethodId;
            }

            // Confirm the PaymentIntent
            $paymentIntent = $this->stripe->paymentIntents->confirm($paymentIntentId, $params);

            Registry::getLogger()->info('Stripe PaymentIntent confirmed', [
                'payment_intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
            ]);

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
            Registry::getLogger()->error('Stripe PaymentIntent confirmation failed', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
                'code' => $e->getStripeCode(),
            ]);

            throw new \RuntimeException(
                'Payment confirmation failed: ' . $e->getMessage(),
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
        try {
            $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);

            return [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'charges' => $this->extractCharges($paymentIntent),
                'next_action' => $paymentIntent->next_action,
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
     * Handle successful payment and create order
     *
     * @param string $paymentIntentId
     * @return Order
     * @throws \RuntimeException
     */
    public function handlePaymentSuccess(string $paymentIntentId): Order
    {
        $paymentIntent = $this->getPaymentIntent($paymentIntentId);

        // Verify payment succeeded
        if ($paymentIntent['status'] !== 'succeeded') {
            throw new \RuntimeException(
                sprintf('Payment not successful. Status: %s', $paymentIntent['status'])
            );
        }

        $session = Registry::getSession();
        $basket = $session->getBasket();
        $user = $basket->getBasketUser();

        if (!$user || !$user->getId()) {
            throw new \RuntimeException('User not found in session');
        }

        // Create OXID order
        $order = oxNew(Order::class);

        // Set payment ID
        $basket->setPayment('osc_stripe_card');

        // Finalize order
        $orderState = $order->finalizeOrder($basket, $user);

        if ($orderState === Order::ORDER_STATE_OK) {
            // Store transaction data
            $this->storeTransaction($order, $paymentIntent);

            // Update order payment state
            $this->updateOrderPaymentState($order->getId(), $paymentIntent);

            Registry::getLogger()->info('Order created successfully', [
                'order_id' => $order->getId(),
                'payment_intent_id' => $paymentIntentId,
            ]);

            return $order;
        }

        throw new \RuntimeException(
            sprintf('Order creation failed with state: %d', $orderState)
        );
    }

    /**
     * Store transaction record
     *
     * @param Order $order
     * @param array $paymentIntent
     */
    public function storeTransaction(Order $order, array $paymentIntent): void
    {
        $charges = $paymentIntent['charges'] ?? [];
        $charge = $charges[0] ?? null;

        $transactionData = [
            'oxorderid' => $order->getId(),
            'oxuserid' => $order->getFieldData('oxuserid'),
            'oxprovider' => 'stripe',
            'oxproviderorderid' => $paymentIntent['id'],
            'oxprovidertransactionid' => $charge['id'] ?? null,
            'oxamount' => $this->convertFromCents($paymentIntent['amount']),
            'oxcurrency' => strtoupper($paymentIntent['currency']),
            'oxstatus' => $paymentIntent['status'],
            'oxtype' => 'payment',
            'oxpaymentmethod' => $charge['payment_method_details']['type'] ?? 'card',
            'oxcardlast4' => $charge['payment_method_details']['card']['last4'] ?? null,
            'oxcardbrand' => $charge['payment_method_details']['card']['brand'] ?? null,
            'ox3dsecure' => isset($charge['payment_method_details']['card']['three_d_secure']) ? 1 : 0,
            'oxcreated' => date('Y-m-d H:i:s'),
        ];

        $this->transactionRepo->createTransaction($transactionData);
    }

    /**
     * Update order payment state
     *
     * @param string $orderId
     * @param array $paymentIntent
     */
    private function updateOrderPaymentState(string $orderId, array $paymentIntent): void
    {
        $db = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();

        $sql = "INSERT INTO osc_payment_order_state
                (OXID, OXORDERID, OXPAYMENTSTATE, OXPAYMENTMETHOD, OXCAPTURED, OXCAPTUREDAMOUNT, OXCAPTUREDAT, OXCREATED)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                OXPAYMENTSTATE = VALUES(OXPAYMENTSTATE),
                OXCAPTURED = VALUES(OXCAPTURED),
                OXCAPTUREDAMOUNT = VALUES(OXCAPTUREDAMOUNT),
                OXCAPTUREDAT = VALUES(OXCAPTUREDAT),
                OXUPDATED = NOW()";

        $db->execute($sql, [
            \OxidEsales\Eshop\Core\UtilsObject::getInstance()->generateUId(),
            $orderId,
            'paid',
            'stripe',
            1,
            $this->convertFromCents($paymentIntent['amount']),
        ]);
    }

    /**
     * Handle 3D Secure authentication
     *
     * @param string $paymentIntentId
     * @return array 3DS data
     */
    public function handle3DSecure(string $paymentIntentId): array
    {
        $paymentIntent = $this->getPaymentIntent($paymentIntentId);

        if ($paymentIntent['status'] === 'requires_action' && $paymentIntent['next_action']) {
            return [
                'requires_action' => true,
                'redirect_url' => $paymentIntent['next_action']['redirect_to_url']['url'] ?? null,
                'client_secret' => $paymentIntent['client_secret'] ?? null,
            ];
        }

        return [
            'requires_action' => false,
        ];
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
     * Create refund for a payment
     *
     * @param string $paymentIntentId
     * @param float|null $amount Partial refund amount (null = full refund)
     * @param string|null $reason Refund reason
     * @return array Refund result
     * @throws \RuntimeException
     */
    public function createRefund(
        string $paymentIntentId,
        ?float $amount = null,
        ?string $reason = null
    ): array {
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
}
```

---

## StripeCustomerService

Service for managing Stripe customers.

### File: `src/Service/StripeCustomerService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Service;

use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Stripe customer management service
 */
class StripeCustomerService
{
    private StripeClient $stripe;
    private StripeConfigurationService $config;

    public function __construct(StripeConfigurationService $config)
    {
        $this->config = $config;
        $this->stripe = new StripeClient($config->getSecretKey());
    }

    /**
     * Get or create Stripe customer for OXID user
     *
     * @param User $user
     * @return string Stripe customer ID
     * @throws \RuntimeException
     */
    public function getOrCreateStripeCustomer(User $user): string
    {
        // Check if customer already exists
        $stripeCustomerId = $this->getStoredStripeCustomerId($user->getId());

        if ($stripeCustomerId) {
            // Verify customer still exists in Stripe
            try {
                $this->stripe->customers->retrieve($stripeCustomerId);
                return $stripeCustomerId;
            } catch (ApiErrorException $e) {
                // Customer deleted in Stripe, create new one
                Registry::getLogger()->warning('Stripe customer not found, creating new', [
                    'user_id' => $user->getId(),
                    'old_customer_id' => $stripeCustomerId,
                ]);
            }
        }

        // Create new Stripe customer
        return $this->createStripeCustomer($user);
    }

    /**
     * Create new Stripe customer
     *
     * @param User $user
     * @return string Stripe customer ID
     * @throws \RuntimeException
     */
    private function createStripeCustomer(User $user): string
    {
        try {
            $customer = $this->stripe->customers->create([
                'email' => $user->getFieldData('oxusername'),
                'name' => $user->getFieldData('oxfname') . ' ' . $user->getFieldData('oxlname'),
                'phone' => $user->getFieldData('oxfon'),
                'metadata' => [
                    'oxid_user_id' => $user->getId(),
                    'oxid_customer_number' => $user->getFieldData('oxcustnr'),
                ],
            ]);

            // Store customer ID
            $this->storeStripeCustomerId($user->getId(), $customer->id);

            Registry::getLogger()->info('Stripe customer created', [
                'user_id' => $user->getId(),
                'customer_id' => $customer->id,
            ]);

            return $customer->id;

        } catch (ApiErrorException $e) {
            Registry::getLogger()->error('Failed to create Stripe customer', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Failed to create customer: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get stored Stripe customer ID
     *
     * @param string $userId
     * @return string|null
     */
    private function getStoredStripeCustomerId(string $userId): ?string
    {
        $db = DatabaseProvider::getDb();

        $customerId = $db->getOne(
            "SELECT OXSTRIPECUSTOMERID FROM osc_payment_customer WHERE OXUSERID = ?",
            [$userId]
        );

        return $customerId ?: null;
    }

    /**
     * Store Stripe customer ID
     *
     * @param string $userId
     * @param string $stripeCustomerId
     */
    private function storeStripeCustomerId(string $userId, string $stripeCustomerId): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "INSERT INTO osc_payment_customer
                (OXID, OXUSERID, OXSTRIPECUSTOMERID, OXCREATED)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                OXSTRIPECUSTOMERID = VALUES(OXSTRIPECUSTOMERID),
                OXUPDATED = NOW()";

        $db->execute($sql, [
            \OxidEsales\Eshop\Core\UtilsObject::getInstance()->generateUId(),
            $userId,
            $stripeCustomerId,
        ]);
    }
}
```

---

## Usage Examples

### Creating Payment Intent

```php
use OxidSolutionCatalysts\Stripe\Service\StripePaymentService;

$paymentService = Registry::get(StripePaymentService::class);
$basket = Registry::getSession()->getBasket();
$user = $basket->getBasketUser();

try {
    $paymentIntent = $paymentService->createPaymentIntent($basket, $user);

    // Return client secret to frontend
    echo json_encode([
        'clientSecret' => $paymentIntent['client_secret'],
        'amount' => $paymentIntent['amount'],
    ]);

} catch (\RuntimeException $e) {
    // Handle error
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
```

### Confirming Payment and Creating Order

```php
$paymentIntentId = Registry::getRequest()->getRequestParameter('payment_intent_id');

try {
    $order = $paymentService->handlePaymentSuccess($paymentIntentId);

    // Redirect to thank you page
    Registry::getUtils()->redirect(
        Registry::getConfig()->getShopSecureHomeUrl() . 'cl=thankyou'
    );

} catch (\RuntimeException $e) {
    // Handle error
    Registry::getUtilsView()->addErrorToDisplay($e->getMessage());
}
```

---

## Error Handling

All service methods throw `\RuntimeException` on errors. Always wrap service calls in try-catch blocks:

```php
try {
    $result = $paymentService->someMethod();
} catch (\RuntimeException $e) {
    // Log error
    Registry::getLogger()->error('Payment failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    // Show user-friendly message
    Registry::getUtilsView()->addErrorToDisplay(
        'Payment processing failed. Please try again.'
    );
}
```

---

## Testing

### Unit Test Example

```php
use PHPUnit\Framework\TestCase;
use OxidSolutionCatalysts\Stripe\Service\StripePaymentService;

class StripePaymentServiceTest extends TestCase
{
    public function testConvertToCents(): void
    {
        $service = new StripePaymentService(...);

        $this->assertEquals(1000, $service->convertToCents(10.00));
        $this->assertEquals(1050, $service->convertToCents(10.50));
        $this->assertEquals(1, $service->convertToCents(0.01));
    }
}
```

---

## Next Steps

1. Read [CONTROLLER_INTEGRATION.md](CONTROLLER_INTEGRATION.md) for controller implementation
2. Read [TEMPLATE_GUIDE.md](TEMPLATE_GUIDE.md) for frontend integration
3. Read [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md) for webhook processing

