# Payment Component: Charge and Refund Capabilities Analysis

**Document Version:** 1.0
**Date:** 2025-11-19
**Status:** Comprehensive Analysis
**Based on:** Payment Component Documentation v4.0.0

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Payment Authorization Models](#payment-authorization-models)
3. [Immediate vs Delayed Capture](#immediate-vs-delayed-capture)
4. [Configuration Options](#configuration-options)
5. [Partial Capture Capabilities](#partial-capture-capabilities)
6. [Refund Capabilities](#refund-capabilities)
7. [Trigger Mechanisms](#trigger-mechanisms)
8. [Implementation Examples](#implementation-examples)
9. [Provider-Specific Details](#provider-specific-details)
10. [Recommendations](#recommendations)

---

## Executive Summary

### ✅ YES - The Payment Component Supports:

1. **Immediate Charge After Authorization** ✅
   - Direct capture mode (`capture_method: 'automatic'` in Stripe)
   - Money is charged immediately when payment is authorized
   - Default recommended mode for most e-commerce scenarios

2. **Delayed Charge (Authorize Now, Capture Later)** ✅
   - Two-step authorization/capture flow (`capture_method: 'manual'`)
   - Authorization reserves funds without charging
   - Capture triggered later via:
     - Webhook notifications
     - Manual action from shop admin
     - Programmatic API calls
     - Automated business rules

3. **Partial Capture** ✅
   - Capture less than the authorized amount
   - Useful for split shipments, cancelled items, or out-of-stock scenarios
   - Remaining authorization can be voided

4. **Full and Partial Refunds** ✅
   - Full refund: Return entire payment amount
   - Partial refund: Return portion of payment (per-item refunds)
   - Multiple partial refunds supported (up to captured amount)

---

## Payment Authorization Models

### Model 1: Immediate Charge (Direct Capture)

**Flow:**
```
Customer places order → Payment authorized → Money IMMEDIATELY captured → Order confirmed
```

**Characteristics:**
- Single-step process
- Money moves from customer to merchant immediately
- Lower risk of authorization expiration
- Recommended for digital goods, services, ready-to-ship items

**Configuration:**
```php
$request = new CreatePaymentRequest(
    amount: 99.99,
    currency: 'EUR',
    orderId: 'order-123',
    shopId: '1',
    paymentMethod: 'card',
    directCapture: true  // ✅ Immediate charge
);
```

**Stripe API Translation:**
```php
// Component converts to:
$intent = $client->paymentIntents->create([
    'amount' => 9999,  // cents
    'currency' => 'eur',
    'capture_method' => 'automatic'  // ✅ Immediate capture
]);
```

---

### Model 2: Authorize Now, Capture Later (Two-Step)

**Flow:**
```
Customer places order → Payment authorized (funds reserved) →
[Later] → Shop admin/webhook/automation captures → Money transferred
```

**Characteristics:**
- Two-step process: Authorization → Capture
- Funds reserved but not transferred
- Authorization typically expires after 7 days (provider-dependent)
- Recommended for:
  - Pre-orders
  - Custom manufacturing
  - Items requiring verification
  - High-value transactions requiring fraud review

**Configuration:**
```php
$request = new CreatePaymentRequest(
    amount: 99.99,
    currency: 'EUR',
    orderId: 'order-123',
    shopId: '1',
    paymentMethod: 'card',
    directCapture: false  // ✅ Delayed capture (authorization only)
);
```

**Stripe API Translation:**
```php
// Component converts to:
$intent = $client->paymentIntents->create([
    'amount' => 9999,
    'currency' => 'eur',
    'capture_method' => 'manual'  // ✅ Authorization only
]);
```

---

## Immediate vs Delayed Capture

### Comparison Matrix

| Feature | Immediate Capture | Delayed Capture |
|---------|------------------|-----------------|
| **Steps** | 1 (authorize + capture) | 2 (authorize, then capture) |
| **Money Transfer** | Immediate | When captured |
| **Authorization Expiry** | N/A | 7 days (typical) |
| **Risk of Expiration** | None | Medium |
| **Fraud Review Window** | Before authorization | Between auth and capture |
| **Inventory Management** | Reserve after payment | Reserve during authorization |
| **Partial Capture** | N/A | ✅ Supported |
| **Cancel Without Refund** | ❌ (must refund) | ✅ (void authorization) |
| **Use Cases** | Standard e-commerce | Pre-orders, custom items |

### When to Use Immediate Capture

✅ **Use immediate capture when:**
- Products are in stock and ready to ship
- Digital goods/services with instant delivery
- Low fraud risk transactions
- Simple fulfillment process
- You want to minimize authorization expiration risk

**Example Scenarios:**
- Standard online retail (Amazon-like)
- Digital downloads
- Software licenses
- Event tickets
- Gift cards

---

### When to Use Delayed Capture

✅ **Use delayed capture when:**
- Products not immediately available (pre-orders, backorders)
- Custom manufacturing or personalization required
- High-value transactions requiring manual fraud review
- Multi-warehouse fulfillment with uncertain stock
- Split shipments over time
- You need to verify product availability before charging

**Example Scenarios:**
- Pre-orders for upcoming products
- Made-to-order furniture
- Custom engraved jewelry
- High-value electronics (fraud review)
- Wholesale/B2B orders
- Dropshipping with uncertain supplier stock

---

## Configuration Options

### Component-Level Configuration

The payment component can be configured globally or per-transaction:

#### Global Configuration (YAML)

```yaml
# config/payment-component.yaml

payment_component:

  # Default capture behavior
  default_capture_mode: "immediate"  # Options: immediate, delayed

  # Capture settings
  capture:
    # Auto-capture authorized payments after N hours
    # (for delayed capture mode)
    auto_capture_after_hours: 72

    # Allow partial captures
    allow_partial_capture: true

    # Require admin approval for captures > threshold
    approval_threshold: 1000.00

    # Maximum time to capture (before authorization expires)
    max_capture_window_hours: 168  # 7 days

  # Refund settings
  refund:
    # Allow refunds within N days of capture
    refund_window_days: 90

    # Allow partial refunds
    allow_partial_refund: true

    # Require reason for refunds > threshold
    reason_required_threshold: 100.00

    # Maximum refund amount
    max_refund_percentage: 100
```

---

### Per-Transaction Configuration

You can override the default behavior per transaction:

```php
// Immediate capture for this specific order
$paymentService->initiatePayment(
    orderId: 'order-123',
    shopId: '1',
    amount: 99.99,
    currency: 'EUR',
    paymentMethod: 'card',
    directCapture: true  // Override: immediate capture
);

// Delayed capture for this specific order
$paymentService->initiatePayment(
    orderId: 'order-456',
    shopId: '1',
    amount: 499.99,
    currency: 'EUR',
    paymentMethod: 'card',
    directCapture: false  // Override: delayed capture
);
```

---

### Admin Configuration UI

The shop admin can configure capture behavior in the OXID admin panel:

**Location:** Admin → Settings → Payment Component → Capture Settings

**Available Options:**
- Default capture mode (immediate/delayed)
- Auto-capture delay (0-168 hours)
- Enable/disable partial captures
- Set approval thresholds
- Configure refund policies

---

## Partial Capture Capabilities

### What is Partial Capture?

**Scenario:** Customer orders 3 items totaling €150, but 1 item is out of stock.

**Traditional Approach:**
- Cancel entire order
- Refund customer
- Create new order for 2 items

**Partial Capture Approach:**
- Authorize €150 (3 items)
- Capture €100 (2 items that shipped)
- Void remaining €50 authorization (1 out-of-stock item)
- ✅ No refund needed, customer charged correct amount

---

### Partial Capture Flow

```
1. Authorization Phase:
   Customer authorizes €150 for 3 items

2. Fulfillment Check:
   - Item A: €50 ✅ In stock
   - Item B: €50 ✅ In stock
   - Item C: €50 ❌ Out of stock

3. Partial Capture:
   Capture €100 (Item A + Item B only)

4. Void Remaining Authorization:
   Void €50 (Item C)

5. Result:
   Customer charged €100
   No refund required
   Clean transaction history
```

---

### Implementing Partial Capture

#### GraphQL API

```graphql
mutation {
  capturePayment(input: {
    orderId: "ORD-12345"
    amount: 100.00  # ✅ Less than authorized €150
    reason: "Partial shipment: 2 of 3 items"
    idempotencyKey: "capture-ORD-12345-20251119-143022"
  }) {
    success
    captureId
    amountCaptured  # Returns 100.00
    orderStatus
  }
}
```

#### PHP Service

```php
// Capture partial amount
$captureResult = $paymentService->capturePayment(
    orderId: 'order-123',
    amount: 100.00,  // Partial amount
    reason: 'Partial shipment: 2 of 3 items',
    idempotencyKey: 'capture-order-123-' . time()
);

// Later, void remaining authorization
$voidResult = $paymentService->voidPayment(
    orderId: 'order-123',
    reason: 'Item out of stock'
);
```

---

### Partial Capture Configuration

```yaml
payment_component:
  capture:
    # Enable partial captures
    allow_partial_capture: true

    # Minimum capture amount (prevent tiny captures)
    min_capture_amount: 10.00

    # Minimum capture percentage (must capture at least 20% of authorized)
    min_capture_percentage: 20

    # Allow multiple partial captures
    allow_multiple_captures: true

    # Maximum number of partial captures per order
    max_captures_per_order: 5
```

---

## Refund Capabilities

### Full Refund

**Scenario:** Customer returns entire order after delivery.

```php
// Full refund
$refundResult = $paymentService->refundPayment(
    orderId: 'order-123',
    amount: 150.00,  // Full order amount
    reason: 'Customer returned all items',
    idempotencyKey: 'refund-order-123-' . time()
);
```

**Result:**
- Order status: `REFUNDED`
- Customer receives €150 back
- Transaction type: `refund`

---

### Partial Refund

**Scenario:** Customer returns 1 of 3 items.

```php
// Partial refund for 1 item
$refundResult = $paymentService->refundPayment(
    orderId: 'order-123',
    amount: 50.00,  // 1 item only
    reason: 'Customer returned 1 item',
    idempotencyKey: 'refund-order-123-item-1-' . time()
);
```

**Result:**
- Order status: `PARTIALLY_REFUNDED`
- Customer receives €50 back
- Remaining refundable: €100
- Transaction type: `partial_refund`

---

### Multiple Partial Refunds

**Scenario:** Customer returns items over multiple occasions.

```php
// First partial refund (item 1)
$refund1 = $paymentService->refundPayment(
    orderId: 'order-123',
    amount: 50.00,
    reason: 'Returned item 1',
    idempotencyKey: 'refund-order-123-1'
);
// Status: PARTIALLY_REFUNDED, Refundable: €100

// Second partial refund (item 2)
$refund2 = $paymentService->refundPayment(
    orderId: 'order-123',
    amount: 50.00,
    reason: 'Returned item 2',
    idempotencyKey: 'refund-order-123-2'
);
// Status: PARTIALLY_REFUNDED, Refundable: €50

// Third partial refund (item 3)
$refund3 = $paymentService->refundPayment(
    orderId: 'order-123',
    amount: 50.00,
    reason: 'Returned item 3',
    idempotencyKey: 'refund-order-123-3'
);
// Status: REFUNDED (fully refunded), Refundable: €0
```

---

### Refund Validation

The component automatically validates refund requests:

```php
class PaymentRefundHandler
{
    public function handle(RefundRequestedEvent $event): void
    {
        $order = $this->orderRepository->getById($event->getOrderId());

        // ✅ Validation 1: Order must be captured
        if (!$order->canBeRefunded()) {
            throw new InvalidStateException('Order cannot be refunded');
        }

        // ✅ Validation 2: Check refundable amount
        $requestedAmount = $event->getAmount();
        $maxRefundable = $order->getRefundableAmount();

        if ($requestedAmount > $maxRefundable) {
            throw new InvalidAmountException(
                "Cannot refund {$requestedAmount}. Max refundable: {$maxRefundable}"
            );
        }

        // ✅ Validation 3: Check refund window
        $capturedAt = $order->getCapturedAt();
        $refundWindowDays = $this->config->getRefundWindowDays();

        if ($capturedAt->diff(new DateTime())->days > $refundWindowDays) {
            throw new RefundWindowExpiredException(
                "Refund window expired (max {$refundWindowDays} days)"
            );
        }

        // Process refund...
    }
}
```

---

## Trigger Mechanisms

The payment component supports **4 trigger channels** for capture and refund operations:

### 1. Webhook-Triggered (Automatic)

**Use Case:** Provider processes payment and notifies via webhook.

**Flow:**
```
Stripe webhook → WebhookController → PaymentCapturedEvent → Handlers update order
```

**Example Webhook Events:**
- Stripe: `payment_intent.succeeded`, `charge.captured`, `charge.refunded`
- PayPal: `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.REFUNDED`
- Adyen: `CAPTURE`, `REFUND`

**Configuration:**
```yaml
payment_component:
  webhook:
    # Enable automatic capture via webhooks
    auto_capture_on_webhook: true

    # Retry failed webhook processing
    retry_failed: true
    retry_attempts: 3
    retry_delay_seconds: 60
```

---

### 2. Backend-Triggered (Manual Admin Action)

**Use Case:** Shop admin manually captures or refunds from admin panel.

**Flow:**
```
Admin clicks "Capture" → GraphQL Mutation → CaptureRequestedEvent → Handler calls provider API
```

**Admin UI Actions:**
- View authorized payments awaiting capture
- Capture full amount
- Capture partial amount (with reason)
- Refund full amount
- Refund partial amount (with reason)
- View transaction history

**Example:**
```php
// Admin panel action handler
class AdminCaptureController
{
    public function captureAction(Request $request): Response
    {
        $orderId = $request->get('orderId');
        $amount = $request->get('amount'); // Optional for partial

        // Validate admin permissions
        if (!$this->admin->hasPermission('CAPTURE_PAYMENT')) {
            throw new AuthorizationException('Permission denied');
        }

        // Emit capture event
        $this->eventDispatcher->dispatch(
            new CaptureRequestedEvent(
                orderId: $orderId,
                amount: $amount,
                reason: 'Manual admin capture',
                triggeredBy: 'admin',
                adminUserId: $this->admin->getId()
            )
        );

        return new JsonResponse(['success' => true]);
    }
}
```

---

### 3. Programmatic (API/GraphQL)

**Use Case:** Third-party system or mobile app triggers capture/refund.

**GraphQL Examples:**

```graphql
# Capture authorized payment
mutation CapturePayment {
  capturePayment(input: {
    orderId: "ORD-12345"
    amount: 99.99  # Optional for partial capture
    reason: "Goods shipped"
    idempotencyKey: "capture-12345-2025-11-19"
  }) {
    success
    captureId
    amountCaptured
    orderStatus
    errorMessage
  }
}

# Refund captured payment
mutation RefundPayment {
  refundPayment(input: {
    orderId: "ORD-12345"
    amount: 50.00  # Partial refund
    reason: "Customer returned 1 item"
    idempotencyKey: "refund-12345-item-1"
  }) {
    success
    refundId
    amountRefunded
    orderStatus
    errorMessage
  }
}

# Query refundable amount
query GetRefundableAmount {
  order(id: "ORD-12345") {
    id
    totalAmount
    capturedAmount
    refundedAmount
    refundableAmount  # Calculated: captured - refunded
    paymentStatus
  }
}
```

**REST API Examples:**

```bash
# Capture payment
curl -X POST https://shop.com/api/payments/capture \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "orderId": "ORD-12345",
    "amount": 99.99,
    "reason": "Goods shipped",
    "idempotencyKey": "capture-12345-2025-11-19"
  }'

# Refund payment
curl -X POST https://shop.com/api/payments/refund \
  -H "Authorization: Bearer $JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "orderId": "ORD-12345",
    "amount": 50.00,
    "reason": "Customer returned item",
    "idempotencyKey": "refund-12345-item-1"
  }'
```

---

### 4. Agentic (AI Agent/Automation)

**Use Case:** AI agent autonomously processes captures/refunds based on business rules.

**MCP Protocol Examples:**

```json
// Auto-capture after shipping label created
POST /mcp/tools
{
  "tool": "capture_payment",
  "parameters": {
    "order_id": "ORD-12345",
    "amount": 99.99,
    "reason": "Automatic capture after shipping label created",
    "triggered_by": "shipping_agent"
  }
}

// Auto-refund if return received within window
POST /mcp/tools
{
  "tool": "refund_payment",
  "parameters": {
    "order_id": "ORD-12345",
    "amount": 50.00,
    "reason": "Automatic refund - return received and verified",
    "triggered_by": "returns_agent"
  }
}
```

**Automation Use Cases:**
- ✅ Auto-capture after shipping label created
- ✅ Auto-refund if return received within window
- ✅ Smart partial captures based on inventory availability
- ✅ Fraud-detection triggered refunds
- ✅ Time-based capture (e.g., 72 hours after authorization)
- ✅ Event-based capture (e.g., goods picked for shipping)

**Configuration:**
```yaml
payment_component:
  mcp:
    enabled: true
    tools:
      - capture_payment
      - refund_payment
      - check_capture_status
      - check_refund_status

  automation:
    # Auto-capture rules
    auto_capture_rules:
      - trigger: "shipping_label_created"
        action: "capture_full_amount"
        enabled: true

      - trigger: "72_hours_after_authorization"
        action: "capture_full_amount"
        enabled: true

      - trigger: "partial_shipment_ready"
        action: "capture_partial_amount"
        enabled: true

    # Auto-refund rules
    auto_refund_rules:
      - trigger: "return_received_and_verified"
        action: "refund_returned_items"
        enabled: true
        max_amount: 1000.00

      - trigger: "fraud_detected"
        action: "refund_full_amount"
        enabled: true
        require_admin_approval: true
```

---

## Implementation Examples

### Scenario 1: Standard E-commerce (Immediate Capture)

**Configuration:**
```yaml
payment_component:
  default_capture_mode: "immediate"
  capture:
    auto_capture_after_hours: 0  # Immediate
```

**Checkout Flow:**
```php
// Customer completes checkout
$paymentService->initiatePayment(
    orderId: 'order-123',
    amount: 99.99,
    currency: 'EUR',
    paymentMethod: 'card',
    directCapture: true  // ✅ Immediate charge
);

// Result: Money captured immediately, order confirmed
```

**Timeline:**
- T+0: Customer authorizes payment → Money captured → Order confirmed
- T+1: Order ships
- T+2: Customer receives goods

---

### Scenario 2: Pre-order (Delayed Capture with Webhook)

**Configuration:**
```yaml
payment_component:
  default_capture_mode: "delayed"
  capture:
    auto_capture_after_hours: 0  # Manual/webhook trigger
```

**Checkout Flow:**
```php
// Customer pre-orders upcoming product
$paymentService->initiatePayment(
    orderId: 'preorder-456',
    amount: 499.99,
    currency: 'EUR',
    paymentMethod: 'card',
    directCapture: false  // ✅ Authorization only
);

// Result: Payment authorized, funds reserved, NO charge yet
```

**Capture Flow (later, when product available):**
```php
// Option A: Manual admin capture
$adminService->capturePayment(
    orderId: 'preorder-456',
    reason: 'Product now available'
);

// Option B: Webhook capture (after provider confirms)
// Webhook handler automatically captures when provider sends "ready_to_capture" event

// Option C: Automated capture on business event
$automationService->on('product_available', function($event) {
    $this->capturePayment(
        orderId: $event->getOrderId(),
        reason: 'Product available for shipment'
    );
});
```

**Timeline:**
- T+0: Customer authorizes payment → Funds reserved (not captured)
- T+14 days: Product becomes available
- T+14 days: Webhook/admin/automation captures payment → Order confirmed
- T+15 days: Order ships

---

### Scenario 3: Partial Shipment (Partial Capture)

**Configuration:**
```yaml
payment_component:
  default_capture_mode: "delayed"
  capture:
    allow_partial_capture: true
    allow_multiple_captures: true
```

**Order Details:**
- Item A: €50 (in stock)
- Item B: €50 (in stock)
- Item C: €50 (backordered)
- **Total authorized:** €150

**Implementation:**
```php
// Step 1: Authorize full amount
$paymentService->initiatePayment(
    orderId: 'order-789',
    amount: 150.00,
    currency: 'EUR',
    paymentMethod: 'card',
    directCapture: false  // ✅ Authorization for €150
);

// Step 2: First shipment (Items A + B available)
$paymentService->capturePayment(
    orderId: 'order-789',
    amount: 100.00,  // ✅ Partial capture: €100
    reason: 'First shipment: Items A + B',
    idempotencyKey: 'capture-order-789-shipment-1'
);
// Customer charged €100

// Step 3: Second shipment (Item C available later)
$paymentService->capturePayment(
    orderId: 'order-789',
    amount: 50.00,  // ✅ Second partial capture: €50
    reason: 'Second shipment: Item C',
    idempotencyKey: 'capture-order-789-shipment-2'
);
// Customer charged additional €50
// Total captured: €150
```

**Timeline:**
- T+0: Customer authorizes €150 → Funds reserved
- T+1: Items A + B ship → Capture €100
- T+7: Item C ships → Capture €50
- **Total charged:** €150 (as originally authorized)

---

### Scenario 4: Out of Stock Item (Partial Capture + Void)

**Order Details:**
- Item A: €50 (in stock)
- Item B: €50 (in stock)
- Item C: €50 (out of stock - discontinued)
- **Total authorized:** €150

**Implementation:**
```php
// Step 1: Authorize full amount
$paymentService->initiatePayment(
    orderId: 'order-999',
    amount: 150.00,
    currency: 'EUR',
    paymentMethod: 'card',
    directCapture: false
);

// Step 2: Capture available items only
$paymentService->capturePayment(
    orderId: 'order-999',
    amount: 100.00,  // ✅ Only Items A + B
    reason: 'Partial capture: Item C out of stock',
    idempotencyKey: 'capture-order-999-available'
);

// Step 3: Void remaining authorization
$paymentService->voidPayment(
    orderId: 'order-999',
    reason: 'Item C discontinued',
    idempotencyKey: 'void-order-999-item-c'
);
// Remaining €50 authorization released
```

**Result:**
- Customer charged: €100 (Items A + B)
- Customer NOT charged: €50 (Item C)
- No refund needed ✅
- Clean transaction history ✅

---

### Scenario 5: Return with Partial Refund

**Order Details:**
- 3 items, total paid: €150
- Customer returns 1 item worth €50

**Implementation:**
```php
// Original order already captured
// Order status: COMPLETED
// Amount captured: €150

// Customer initiates return
$returnService->processReturn(
    orderId: 'order-555',
    returnedItems: ['item-c'],
    returnReason: 'Changed mind'
);

// Shop verifies return and issues refund
$paymentService->refundPayment(
    orderId: 'order-555',
    amount: 50.00,  // ✅ Partial refund for 1 item
    reason: 'Return accepted: Item C',
    idempotencyKey: 'refund-order-555-item-c'
);

// Result:
// - Customer receives €50 back
// - Order status: PARTIALLY_REFUNDED
// - Refundable amount: €100 (remaining)
```

**Timeline:**
- T+0: Order completed, €150 captured
- T+5: Customer receives order
- T+10: Customer initiates return for 1 item
- T+12: Shop receives returned item
- T+12: Shop refunds €50
- T+15: Customer receives €50 refund

---

## Provider-Specific Details

### Stripe

**Capture Configuration:**
```php
// Immediate capture
$intent = $client->paymentIntents->create([
    'amount' => 9999,
    'currency' => 'eur',
    'capture_method' => 'automatic'  // ✅ Immediate
]);

// Delayed capture
$intent = $client->paymentIntents->create([
    'amount' => 9999,
    'currency' => 'eur',
    'capture_method' => 'manual'  // ✅ Delayed
]);

// Later, capture manually
$client->paymentIntents->capture($intent->id, [
    'amount_to_capture' => 5000  // ✅ Partial capture (€50 of €99.99)
]);
```

**Refund:**
```php
// Full refund
$client->refunds->create([
    'payment_intent' => $intent->id
]);

// Partial refund
$client->refunds->create([
    'payment_intent' => $intent->id,
    'amount' => 5000  // ✅ Partial refund (€50)
]);
```

**Authorization Expiry:**
- Cards: 7 days
- ACH/SEPA: 90 days (varies by bank)

---

### PayPal

**Capture Configuration:**
```php
// Immediate capture (CAPTURE intent)
$order = $client->orders->create([
    'intent' => 'CAPTURE',  // ✅ Immediate
    'purchase_units' => [...]
]);

// Delayed capture (AUTHORIZE intent)
$order = $client->orders->create([
    'intent' => 'AUTHORIZE',  // ✅ Delayed
    'purchase_units' => [...]
]);

// Later, capture manually
$client->payments->authorize->capture($authorizationId, [
    'amount' => [
        'value' => '50.00',  // ✅ Partial capture
        'currency_code' => 'EUR'
    ]
]);
```

**Refund:**
```php
// Full refund
$client->payments->capture->refund($captureId);

// Partial refund
$client->payments->capture->refund($captureId, [
    'amount' => [
        'value' => '50.00',
        'currency_code' => 'EUR'
    ]
]);
```

**Authorization Expiry:**
- Standard: 3 days
- Extended: Up to 29 days (requires additional configuration)

---

### Unzer

**Capture Configuration:**
```php
// Immediate capture
$charge = $sdk->charge(
    $amount,
    $currency,
    $paymentType,
    $returnUrl,
    $customer,
    $orderId
);

// Delayed capture (authorization)
$authorization = $sdk->authorize(
    $amount,
    $currency,
    $paymentType,
    $returnUrl,
    $customer,
    $orderId
);

// Later, capture manually
$charge = $sdk->chargeAuthorization(
    $authorizationId,
    $amount  // ✅ Partial capture supported
);
```

**Refund:**
```php
// Full refund
$refund = $sdk->cancelCharge($chargeId);

// Partial refund
$refund = $sdk->cancelChargePartially(
    $chargeId,
    $amount  // ✅ Partial amount
);
```

**Authorization Expiry:**
- Cards: 7 days
- Invoice: 90 days

---

## Recommendations

### For Standard E-commerce Shops

✅ **Recommended Configuration:**
```yaml
payment_component:
  default_capture_mode: "immediate"
  capture:
    auto_capture_after_hours: 0
  refund:
    allow_partial_refund: true
    refund_window_days: 30
```

**Rationale:**
- Immediate capture reduces authorization expiration risk
- Partial refunds support item-level returns
- 30-day refund window aligns with most return policies

---

### For Pre-order/Custom Manufacturing

✅ **Recommended Configuration:**
```yaml
payment_component:
  default_capture_mode: "delayed"
  capture:
    auto_capture_after_hours: 0  # Manual trigger
    allow_partial_capture: true
  webhook:
    auto_capture_on_webhook: true  # Capture when ready
```

**Rationale:**
- Delayed capture prevents charging before goods ready
- Webhook triggers automatic capture when goods available
- Partial capture supports split shipments

---

### For High-Value/Fraud-Prone Items

✅ **Recommended Configuration:**
```yaml
payment_component:
  default_capture_mode: "delayed"
  capture:
    auto_capture_after_hours: 24  # 24-hour fraud review window
    approval_threshold: 500.00     # Manual approval >€500
  refund:
    allow_partial_refund: true
```

**Rationale:**
- Delayed capture allows fraud review before charging
- Manual approval for high-value transactions
- Can void authorization if fraud detected (no refund needed)

---

### For Multi-warehouse/Dropshipping

✅ **Recommended Configuration:**
```yaml
payment_component:
  default_capture_mode: "delayed"
  capture:
    allow_partial_capture: true
    allow_multiple_captures: true
    max_captures_per_order: 5
  automation:
    auto_capture_rules:
      - trigger: "partial_shipment_ready"
        action: "capture_partial_amount"
        enabled: true
```

**Rationale:**
- Delayed capture until stock confirmed
- Partial captures as items ship from different warehouses
- Automation reduces manual work

---

## Summary

### ✅ Capabilities Confirmed

| Capability | Supported | Configuration |
|-----------|-----------|---------------|
| **Immediate Charge** | ✅ Yes | `directCapture: true` |
| **Delayed Charge** | ✅ Yes | `directCapture: false` |
| **Authorization Only** | ✅ Yes | `capture_method: 'manual'` |
| **Manual Capture (Admin)** | ✅ Yes | Admin UI + GraphQL |
| **Webhook Capture** | ✅ Yes | Webhook handler |
| **Programmatic Capture** | ✅ Yes | GraphQL API / REST |
| **Automated Capture** | ✅ Yes | MCP / Business rules |
| **Partial Capture** | ✅ Yes | `amount: 50.00` (partial) |
| **Multiple Partial Captures** | ✅ Yes | `allow_multiple_captures: true` |
| **Full Refund** | ✅ Yes | `amount: [full amount]` |
| **Partial Refund** | ✅ Yes | `amount: 50.00` (partial) |
| **Multiple Partial Refunds** | ✅ Yes | Up to captured amount |
| **Void Authorization** | ✅ Yes | `voidPayment()` |

---

### Configuration Quick Reference

```yaml
# Immediate capture (default e-commerce)
payment_component:
  default_capture_mode: "immediate"

# Delayed capture (pre-orders, custom items)
payment_component:
  default_capture_mode: "delayed"
  capture:
    auto_capture_after_hours: 72  # or 0 for manual

# Enable partial captures
payment_component:
  capture:
    allow_partial_capture: true
    allow_multiple_captures: true

# Enable partial refunds
payment_component:
  refund:
    allow_partial_refund: true
    refund_window_days: 90
```

---

**Document Complete** ✅
All charge and refund capabilities documented and confirmed supported by the Payment Component.
