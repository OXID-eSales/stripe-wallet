# Webhook Handling Guide

**Processing Stripe Webhook Events**
**Version:** 1.0.0
**Date:** 2025-11-13

---

## Overview

Webhooks are server-to-server notifications from Stripe about events that happen in your account. This guide covers implementing webhook handling to keep your orders synchronized with Stripe payment states.

---

## Why Webhooks?

### Problem Without Webhooks

```
Customer completes payment
       ↓
Browser closes before redirect
       ↓
Order shows as "pending"
       ↓
Payment captured in Stripe
       ↓
Order NEVER updated!
```

### Solution With Webhooks

```
Customer completes payment
       ↓
Stripe sends webhook: payment_intent.succeeded
       ↓
Your server updates order status
       ↓
Order marked as "paid"
       ↓
Even if browser closes!
```

**Key Benefits:**
- ✅ Reliable payment confirmation (independent of user's browser)
- ✅ Asynchronous payment updates
- ✅ Handles edge cases (browser closed, network issues)
- ✅ Required for delayed payment methods (bank transfers, etc.)
- ✅ Audit trail of all payment events

---

## Webhook Architecture

```
┌─────────────────┐
│  Stripe  API    │
└────────┬────────┘
         │ HTTP POST
         │ Event: payment_intent.succeeded
         │ Signature: whsec_xxx...
         ▼
┌─────────────────┐
│  Your Server    │
│  /webhook       │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Webhook Controller                  │
│  1. Verify signature                 │
│  2. Parse payload                    │
│  3. Check idempotency                │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Webhook Processing Service          │
│  - Route to event handler            │
│  - Update order status               │
│  - Store transaction data            │
│  - Log event                         │
└─────────────────────────────────────┘
```

---

## Webhook Processing Service

### File: `src/Service/WebhookProcessingService.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Stripe\Service;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Application\Model\Order;
use Stripe\Event;

/**
 * Webhook event processing service
 */
class WebhookProcessingService
{
    private PaymentTransactionService $transactionService;

    public function __construct(PaymentTransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Process Stripe webhook event
     *
     * @param Event $event Stripe event object
     * @throws \RuntimeException
     */
    public function processEvent(Event $event): void
    {
        // Check if event already processed (idempotency)
        if ($this->isEventProcessed($event->id)) {
            Registry::getLogger()->info('Webhook event already processed', [
                'event_id' => $event->id,
            ]);
            return;
        }

        // Log webhook receipt
        $this->logWebhookEvent($event, 'received');

        try {
            // Route to appropriate handler
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSucceeded($event);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handlePaymentIntentFailed($event);
                    break;

                case 'payment_intent.requires_action':
                    $this->handlePaymentIntentRequiresAction($event);
                    break;

                case 'payment_intent.canceled':
                    $this->handlePaymentIntentCanceled($event);
                    break;

                case 'charge.refunded':
                    $this->handleChargeRefunded($event);
                    break;

                case 'charge.dispute.created':
                    $this->handleDisputeCreated($event);
                    break;

                default:
                    Registry::getLogger()->info('Unhandled webhook event type', [
                        'type' => $event->type,
                    ]);
            }

            // Mark event as processed
            $this->updateWebhookLog($event->id, 'processed');

        } catch (\Exception $e) {
            Registry::getLogger()->error('Webhook processing failed', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            // Log error
            $this->updateWebhookLog($event->id, 'failed', $e->getMessage());

            throw new \RuntimeException(
                'Webhook processing failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Handle payment_intent.succeeded event
     */
    private function handlePaymentIntentSucceeded(Event $event): void
    {
        $paymentIntent = $event->data->object;

        Registry::getLogger()->info('Processing payment_intent.succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
        ]);

        // Find order by PaymentIntent ID
        $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

        if (!$order) {
            // Order might not exist yet if webhook arrives before order creation
            Registry::getLogger()->warning('Order not found for PaymentIntent', [
                'payment_intent_id' => $paymentIntent->id,
            ]);
            return;
        }

        // Update order status to paid
        $this->updateOrderStatus($order, 'paid');

        // Update transaction record
        $this->transactionService->updateTransactionStatus(
            $order->getId(),
            'succeeded'
        );

        // Update payment order state
        $this->updatePaymentOrderState($order->getId(), [
            'oxpaymentstate' => 'paid',
            'oxcaptured' => 1,
            'oxcapturedamount' => $paymentIntent->amount / 100,
            'oxcapturedat' => date('Y-m-d H:i:s'),
        ]);

        Registry::getLogger()->info('Order marked as paid from webhook', [
            'order_id' => $order->getId(),
            'payment_intent_id' => $paymentIntent->id,
        ]);
    }

    /**
     * Handle payment_intent.payment_failed event
     */
    private function handlePaymentIntentFailed(Event $event): void
    {
        $paymentIntent = $event->data->object;

        Registry::getLogger()->warning('Processing payment_intent.payment_failed', [
            'payment_intent_id' => $paymentIntent->id,
            'error' => $paymentIntent->last_payment_error->message ?? 'Unknown error',
        ]);

        $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

        if ($order) {
            // Update order status
            $this->updateOrderStatus($order, 'payment_failed');

            // Update transaction
            $this->transactionService->updateTransactionStatus(
                $order->getId(),
                'failed',
                $paymentIntent->last_payment_error->message ?? null
            );
        }
    }

    /**
     * Handle payment_intent.requires_action event
     */
    private function handlePaymentIntentRequiresAction(Event $event): void
    {
        $paymentIntent = $event->data->object;

        Registry::getLogger()->info('Processing payment_intent.requires_action', [
            'payment_intent_id' => $paymentIntent->id,
        ]);

        // Usually handled client-side, but log for tracking
        $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

        if ($order) {
            $this->transactionService->updateTransactionStatus(
                $order->getId(),
                'requires_action'
            );
        }
    }

    /**
     * Handle payment_intent.canceled event
     */
    private function handlePaymentIntentCanceled(Event $event): void
    {
        $paymentIntent = $event->data->object;

        Registry::getLogger()->info('Processing payment_intent.canceled', [
            'payment_intent_id' => $paymentIntent->id,
        ]);

        $order = $this->findOrderByPaymentIntentId($paymentIntent->id);

        if ($order) {
            $this->updateOrderStatus($order, 'canceled');

            $this->transactionService->updateTransactionStatus(
                $order->getId(),
                'canceled'
            );
        }
    }

    /**
     * Handle charge.refunded event
     */
    private function handleChargeRefunded(Event $event): void
    {
        $charge = $event->data->object;

        Registry::getLogger()->info('Processing charge.refunded', [
            'charge_id' => $charge->id,
            'amount_refunded' => $charge->amount_refunded,
        ]);

        // Find order by charge ID (transaction ID)
        $order = $this->findOrderByChargeId($charge->id);

        if ($order) {
            $refundedAmount = $charge->amount_refunded / 100;
            $totalAmount = $charge->amount / 100;
            $isFullRefund = $refundedAmount >= $totalAmount;

            // Update payment state
            $this->updatePaymentOrderState($order->getId(), [
                'oxpaymentstate' => $isFullRefund ? 'refunded' : 'partially_refunded',
                'oxrefunded' => 1,
                'oxrefundedamount' => $refundedAmount,
                'oxrefundedat' => date('Y-m-d H:i:s'),
            ]);

            // Store refund transaction
            $this->storeRefundTransaction($order, $charge);
        }
    }

    /**
     * Handle charge.dispute.created event
     */
    private function handleDisputeCreated(Event $event): void
    {
        $dispute = $event->data->object;

        Registry::getLogger()->warning('Dispute created', [
            'dispute_id' => $dispute->id,
            'charge_id' => $dispute->charge,
            'amount' => $dispute->amount,
            'reason' => $dispute->reason,
        ]);

        $order = $this->findOrderByChargeId($dispute->charge);

        if ($order) {
            // Update order status to disputed
            $this->updateOrderStatus($order, 'disputed');

            // Send notification email to admin
            $this->sendDisputeNotification($order, $dispute);
        }
    }

    /**
     * Find order by PaymentIntent ID
     */
    private function findOrderByPaymentIntentId(string $paymentIntentId): ?Order
    {
        $db = DatabaseProvider::getDb();

        $orderId = $db->getOne(
            "SELECT OXORDERID FROM oe_payments_transaction WHERE OXPROVIDERORDERID = ?",
            [$paymentIntentId]
        );

        if (!$orderId) {
            return null;
        }

        $order = oxNew(Order::class);

        if ($order->load($orderId)) {
            return $order;
        }

        return null;
    }

    /**
     * Find order by Charge ID
     */
    private function findOrderByChargeId(string $chargeId): ?Order
    {
        $db = DatabaseProvider::getDb();

        $orderId = $db->getOne(
            "SELECT OXORDERID FROM oe_payments_transaction WHERE OXPROVIDERTRANSACTIONID = ?",
            [$chargeId]
        );

        if (!$orderId) {
            return null;
        }

        $order = oxNew(Order::class);

        if ($order->load($orderId)) {
            return $order;
        }

        return null;
    }

    /**
     * Update order status
     */
    private function updateOrderStatus(Order $order, string $status): void
    {
        // Map internal status to OXID order states
        $orderState = match($status) {
            'paid' => Order::ORDER_STATE_OK,
            'payment_failed', 'canceled', 'disputed' => Order::ORDER_STATE_PAYMENTERROR,
            default => $order->getFieldData('oxtransstatus'),
        };

        $order->assign([
            'oxtransstatus' => $orderState,
        ]);
        $order->save();
    }

    /**
     * Update payment order state
     */
    private function updatePaymentOrderState(string $orderId, array $data): void
    {
        $db = DatabaseProvider::getDb();

        $setParts = [];
        $values = [];

        foreach ($data as $field => $value) {
            $setParts[] = "$field = ?";
            $values[] = $value;
        }

        $values[] = $orderId;

        $sql = "UPDATE oe_payments_order_state SET " .
               implode(', ', $setParts) . ", OXUPDATED = NOW() WHERE OXORDERID = ?";

        $db->execute($sql, $values);
    }

    /**
     * Store refund transaction
     */
    private function storeRefundTransaction(Order $order, $charge): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "INSERT INTO oe_payments_transaction
                (OXID, OXORDERID, OXUSERID, OXPROVIDER, OXPROVIDERORDERID, OXPROVIDERTRANSACTIONID,
                 OXAMOUNT, OXCURRENCY, OXSTATUS, OXTYPE, OXCREATED)
                VALUES (?, ?, ?, 'stripe', ?, ?, ?, ?, 'refunded', 'refund', NOW())";

        $db->execute($sql, [
            \OxidEsales\Eshop\Core\UtilsObject::getInstance()->generateUId(),
            $order->getId(),
            $order->getFieldData('oxuserid'),
            $charge->payment_intent,
            $charge->id,
            $charge->amount_refunded / 100,
            strtoupper($charge->currency),
        ]);
    }

    /**
     * Check if webhook event already processed
     */
    private function isEventProcessed(string $eventId): bool
    {
        $db = DatabaseProvider::getDb();

        $exists = $db->getOne(
            "SELECT OXID FROM oe_payments_webhook_log WHERE OXEVENTID = ?",
            [$eventId]
        );

        return (bool) $exists;
    }

    /**
     * Log webhook event
     */
    private function logWebhookEvent(Event $event, string $status): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "INSERT INTO oe_payments_webhook_log
                (OXID, OXEVENTID, OXEVENTTYPE, OXPROVIDER, OXPAYLOAD, OXSTATUS, OXCREATED)
                VALUES (?, ?, ?, 'stripe', ?, ?, NOW())";

        $db->execute($sql, [
            \OxidEsales\Eshop\Core\UtilsObject::getInstance()->generateUId(),
            $event->id,
            $event->type,
            json_encode($event->data->object),
            $status,
        ]);
    }

    /**
     * Update webhook log status
     */
    private function updateWebhookLog(string $eventId, string $status, ?string $error = null): void
    {
        $db = DatabaseProvider::getDb();

        $sql = "UPDATE oe_payments_webhook_log
                SET OXSTATUS = ?, OXPROCESSEDAT = NOW(), OXERROR = ?
                WHERE OXEVENTID = ?";

        $db->execute($sql, [$status, $error, $eventId]);
    }

    /**
     * Send dispute notification to admin
     */
    private function sendDisputeNotification(Order $order, $dispute): void
    {
        // Implementation for sending email notification to shop admin
        $email = Registry::get(\OxidEsales\Eshop\Core\Email::class);

        // Send email with dispute details
        // ... implementation
    }
}
```

---

## Webhook URL Configuration

### Step 1: Set Up Webhook Endpoint in Stripe Dashboard

1. Login to [Stripe Dashboard](https://dashboard.stripe.com)
2. Go to **Developers** → **Webhooks**
3. Click **Add endpoint**
4. Enter your webhook URL:
   ```
   https://your-shop.com/index.php?cl=stripe_webhook
   ```
5. Select events to listen to:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `payment_intent.requires_action`
   - `payment_intent.canceled`
   - `charge.refunded`
   - `charge.dispute.created`
6. Click **Add endpoint**
7. Copy the **Signing secret** (starts with `whsec_`)

### Step 2: Configure Webhook Secret in OXID

Add to OXID admin settings:
```
Module: Stripe Payment
Setting: stripeWebhookSecret
Value: whsec_xxxxxxxxxxxxxxxxx
```

---

## Testing Webhooks Locally

### Using Stripe CLI

1. **Install Stripe CLI:**
   ```bash
   # Mac
   brew install stripe/stripe-cli/stripe

   # Linux
   wget https://github.com/stripe/stripe-cli/releases/download/v1.17.0/stripe_1.17.0_linux_x86_64.tar.gz
   tar -xvf stripe_1.17.0_linux_x86_64.tar.gz
   ```

2. **Login to Stripe:**
   ```bash
   stripe login
   ```

3. **Forward webhooks to local server:**
   ```bash
   stripe listen --forward-to https://localhost/your-oxid/index.php?cl=stripe_webhook
   ```

4. **Trigger test events:**
   ```bash
   stripe trigger payment_intent.succeeded
   ```

### Testing Webhook Signature Verification

```php
// Test webhook with sample payload
$payload = '{
  "id": "evt_test_webhook",
  "object": "event",
  "type": "payment_intent.succeeded",
  "data": {
    "object": {
      "id": "pi_test_12345",
      "amount": 1000,
      "currency": "usd",
      "status": "succeeded"
    }
  }
}';

$sigHeader = 't=1492774577,v1=abcdef1234567890,v0=9876543210fedcba';

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        'whsec_test_secret'
    );

    echo "Signature verified!";
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    echo "Signature verification failed: " . $e->getMessage();
}
```

---

## Webhook Events Reference

### payment_intent.succeeded

**When:** Payment successfully captured
**Action:** Mark order as paid, update transaction status
**Critical:** Yes - Orders depend on this

```json
{
  "type": "payment_intent.succeeded",
  "data": {
    "object": {
      "id": "pi_xxx",
      "amount": 1000,
      "currency": "usd",
      "status": "succeeded",
      "charges": {
        "data": [{
          "id": "ch_xxx",
          "amount": 1000,
          "paid": true
        }]
      }
    }
  }
}
```

### payment_intent.payment_failed

**When:** Payment attempt failed
**Action:** Mark order as failed, notify customer
**Critical:** Yes - Customer needs to know

```json
{
  "type": "payment_intent.payment_failed",
  "data": {
    "object": {
      "id": "pi_xxx",
      "status": "requires_payment_method",
      "last_payment_error": {
        "code": "card_declined",
        "message": "Your card was declined."
      }
    }
  }
}
```

### charge.refunded

**When:** Refund processed
**Action:** Update order status, record refund transaction
**Critical:** Yes - For accounting

```json
{
  "type": "charge.refunded",
  "data": {
    "object": {
      "id": "ch_xxx",
      "amount": 1000,
      "amount_refunded": 1000,
      "refunded": true,
      "refunds": {
        "data": [{
          "id": "re_xxx",
          "amount": 1000,
          "status": "succeeded"
        }]
      }
    }
  }
}
```

---

## Error Handling

### Webhook Processing Failures

```php
try {
    $this->processEvent($event);
} catch (\Exception $e) {
    // Log error
    Registry::getLogger()->error('Webhook processing failed', [
        'event_id' => $event->id,
        'error' => $e->getMessage(),
    ]);

    // Mark as failed in log
    $this->updateWebhookLog($event->id, 'failed', $e->getMessage());

    // Stripe will retry failed webhooks
    // Return 500 to trigger retry
    http_response_code(500);
}
```

### Webhook Retry Logic

Stripe automatically retries failed webhooks:
- **Retry Schedule:** Up to 3 days
- **Retry Intervals:** Exponential backoff
- **Maximum Attempts:** ~25 attempts

**Handle Retries:**
- Use idempotency checks (`isEventProcessed()`)
- Log all webhook attempts
- Return 200 OK once successfully processed

---

## Monitoring Webhooks

### Webhook Log Query

```sql
-- View recent webhooks
SELECT
    OXEVENTTYPE,
    OXSTATUS,
    OXCREATED,
    OXPROCESSEDAT
FROM oe_payments_webhook_log
ORDER BY OXCREATED DESC
LIMIT 100;

-- Check for failed webhooks
SELECT
    OXEVENTID,
    OXEVENTTYPE,
    OXERROR,
    OXCREATED
FROM oe_payments_webhook_log
WHERE OXSTATUS = 'failed'
ORDER BY OXCREATED DESC;
```

### Monitoring Dashboard

Track webhook health:
- ✅ Total webhooks received (last 24h)
- ✅ Successfully processed
- ❌ Failed webhooks
- ⏱️ Average processing time
- 🔄 Retry count

---

## Security Best Practices

### 1. Always Verify Signature

```php
// ❌ BAD: Trust any webhook
$payload = file_get_contents('php://input');
$event = json_decode($payload);
$this->processEvent($event); // DANGEROUS!

// ✅ GOOD: Verify signature first
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'];

$event = \Stripe\Webhook::constructEvent(
    $payload,
    $sigHeader,
    $webhookSecret // Verifies authenticity
);

$this->processEvent($event); // Safe!
```

### 2. Use HTTPS Only

```apache
# .htaccess - Force HTTPS for webhook endpoint
RewriteCond %{HTTPS} off
RewriteCond %{REQUEST_URI} ^/index\.php?cl=stripe_webhook
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 3. Keep Webhook Secret Secure

```php
// ❌ BAD: Hard-coded secret
$secret = 'whsec_1234567890';

// ✅ GOOD: Environment variable or config
$secret = getenv('STRIPE_WEBHOOK_SECRET');

// ✅ BETTER: OXID module configuration
$secret = $this->config->getWebhookSecret();
```

---

## Next Steps

1. Read [ERROR_HANDLING.md](ERROR_HANDLING.md) for error scenarios
2. Read [SECURITY_GUIDE.md](SECURITY_GUIDE.md) for security best practices
3. Read [TESTING_GUIDE.md](TESTING_GUIDE.md) for testing strategies

