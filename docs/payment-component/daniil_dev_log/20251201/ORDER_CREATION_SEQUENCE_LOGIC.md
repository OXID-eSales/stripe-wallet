# Order Creation Sequence Logic

## The Question

In the deleted legacy code (`OrderController_legacy.php`), the OXID order was created **BEFORE** sending the PaymentIntent to Stripe, so an actual `order_id` and `order_number` could be sent in the Stripe metadata.

**Question:** What is sent to Stripe now if the order is only created at the end?

---

## The Answer: Contract ID Instead of Order ID

The current architecture uses a **Contract-First** pattern where a `PaymentContract` (not an OXID Order) is created before payment. The **contract_id** is sent to Stripe instead of an order_id.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    LEGACY vs CURRENT ARCHITECTURE                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  LEGACY (Deleted):                    CURRENT (Contract-First):             │
│  ─────────────────                    ─────────────────────────             │
│                                                                             │
│  1. Create OXID Order                 1. Create PaymentContract             │
│  2. Send order_id to Stripe           2. Send contract_id to Stripe         │
│  3. Customer pays                     3. Customer pays                      │
│  4. Update order status               4. Verify payment                     │
│                                       5. Create OXID Order                  │
│                                       6. Link contract to order             │
│                                                                             │
│  Problem: Order exists even           Benefit: Order only exists            │
│  if payment fails!                    after successful payment!             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## What is Sent to Stripe?

### Current Implementation (StripeCheckoutSessionHandler.php)

```php
// Create Checkout Session with CONTRACT reference (not order!)
$checkoutSession = $stripeClient->checkout->sessions->create([
    'mode' => 'payment',
    'line_items' => $lineItems,
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'metadata' => [
        'contract_id' => $contractId,    // <-- CONTRACT ID, not order ID
        'shop_id' => $shopIdString,
    ],
    'payment_intent_data' => [
        'capture_method' => $captureMode,
        'metadata' => [
            'contract_id' => $contractId,  // <-- Also in PaymentIntent
        ],
    ],
]);
```

### Stripe Dashboard View

When you look at a payment in Stripe Dashboard, you'll see:

```
Metadata:
  contract_id: "abc123-def456-..."
  shop_id: "1"
```

Instead of (legacy):
```
Metadata:
  order_id: "oxid_order_xyz"
  order_number: "12345"
```

---

## The Flow Explained

### Step 1: Customer Clicks "Pay with Stripe"

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ StripeContractCreationHandler (Priority: 100)                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Creates PaymentContract:                                                   │
│  {                                                                          │
│    id: "contract_abc123",                                                   │
│    userId: "user_xyz",                                                      │
│    state: "DRAFT",                                                          │
│    basketSnapshot: { items: [...], total: 64.97 },                          │
│    metadata: {                                                              │
│      delivery_address_hash: "e09dae058fb2..."  // For OXID validation      │
│    }                                                                        │
│  }                                                                          │
│                                                                             │
│  NOTE: NO OXID ORDER EXISTS YET!                                            │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ StripeCheckoutSessionHandler (Priority: 0)                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Creates Stripe Checkout Session:                                           │
│  {                                                                          │
│    id: "cs_xxx",                                                            │
│    url: "https://checkout.stripe.com/...",                                  │
│    metadata: {                                                              │
│      contract_id: "contract_abc123"   // <-- THIS is sent to Stripe        │
│    }                                                                        │
│  }                                                                          │
│                                                                             │
│  NOTE: Stripe now has CONTRACT_ID, not ORDER_ID                             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Step 2: Customer Pays on Stripe

Customer is redirected to Stripe, enters payment details, and pays.

### Step 3: Customer Returns to Shop

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ StripeCheckoutReturnHandler                                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. Retrieve Checkout Session from Stripe                                   │
│  2. Extract contract_id from metadata                                       │
│  3. Load PaymentContract from database                                      │
│  4. Verify payment status is "paid"                                         │
│  5. Restore delivery address hash to session (CRITICAL!)                    │
│  6. Dispatch PaymentAuthorizedEvent                                         │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ PaymentAuthorizedEventHandler                                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. Transition contract: DRAFT → PENDING                                    │
│  2. Fulfill condition: "payment_authorized"                                 │
│  3. Auto-transition: PENDING → READY_TO_COMMIT (all conditions met)         │
│  4. Dispatch ContractReadyToCommitEvent                                     │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ StripeOrderCreationHandler                                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  NOW THE OXID ORDER IS CREATED:                                             │
│                                                                             │
│  1. Get basket from session                                                 │
│  2. Create order via OxidShopOrderService.createOrder()                     │
│     - Calls Order::finalizeOrder()                                          │
│     - Generates order number                                                │
│     - Creates oxorder record                                                │
│  3. Update contract: commitToOrder(orderId)                                 │
│  4. Contract state: READY_TO_COMMIT → COMMITTED                             │
│                                                                             │
│  Order ID is now linked to contract:                                        │
│  contract.orderId = "oxid_order_xyz"                                        │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Why This Is Better

### Problem with Legacy Approach

```
Customer clicks "Pay" → Order created (order_id: 12345)
       │
       ▼
Customer redirected to Stripe
       │
       ▼
Customer abandons payment OR payment fails
       │
       ▼
PROBLEM: Order 12345 exists in database but was never paid!
         - Inventory reserved but never sold
         - Order statistics polluted with abandoned orders
         - Need cleanup jobs to delete unpaid orders
```

### Solution with Contract-First

```
Customer clicks "Pay" → Contract created (contract_id: abc123)
       │
       ▼
Customer redirected to Stripe
       │
       ▼
Customer abandons payment OR payment fails
       │
       ▼
OK: Only contract exists, no order created
    - No inventory reserved
    - No order statistics pollution
    - Contracts can have separate cleanup rules
```

---

## Mapping Contract to Order

The link between Stripe payment and OXID order is maintained through the contract:

```
┌───────────────────┐     ┌───────────────────┐     ┌───────────────────┐
│   Stripe Payment  │     │  PaymentContract  │     │    OXID Order     │
├───────────────────┤     ├───────────────────┤     ├───────────────────┤
│                   │     │                   │     │                   │
│ payment_intent_id ├────►│ providerOrderId   │     │ OXID              │
│ cs_xxx            │     │ = cs_xxx          │     │                   │
│                   │     │                   │     │                   │
│ metadata:         │     │ orderId ─────────────► │ oxid_order_xyz    │
│   contract_id ────┼────►│ = contract_abc123 │     │                   │
│                   │     │                   │     │ OXTRANSID =       │
│                   │     │                   │     │   pi_xxx          │
│                   │     │                   │     │                   │
└───────────────────┘     └───────────────────┘     └───────────────────┘
```

### Finding the Order from Stripe

To find an OXID order from a Stripe payment:

1. Get `contract_id` from Stripe metadata
2. Load `PaymentContract` from database
3. Get `orderId` from contract (if committed)
4. Load OXID Order

```php
// In Stripe webhook handler:
$contractId = $stripeEvent->data->object->metadata->contract_id;
$contract = $contractRepository->findById($contractId);
$orderId = $contract->getOrderId();  // Only set after successful payment
```

---

## Database Tables

### osc_stripe_contracts (PaymentContract)

| Column | Type | Description |
|--------|------|-------------|
| OXID | char(32) | Contract ID (sent to Stripe) |
| OXUSERID | char(32) | User ID |
| OXSTATE | varchar(32) | DRAFT → PENDING → READY_TO_COMMIT → COMMITTED |
| OXORDERID | char(32) | OXID Order ID (set after order creation) |
| OXPROVIDERORDERID | varchar(255) | Stripe session/intent ID |
| OXMETADATA | text | JSON with delivery_address_hash, etc. |
| OXBASKETDATA | text | Serialized basket snapshot |

### oxorder (OXID Order) - Only created after payment!

| Column | Type | Description |
|--------|------|-------------|
| OXID | char(32) | Order ID |
| OXORDERNR | int | Order number (auto-generated) |
| OXTRANSID | varchar(64) | Payment transaction ID |

---

## Summary

| Aspect | Legacy | Current |
|--------|--------|---------|
| **What's sent to Stripe** | `order_id`, `order_number` | `contract_id` |
| **When order is created** | Before payment | After payment confirmed |
| **Abandoned checkouts** | Create orphan orders | Only contracts, no orders |
| **Linking mechanism** | Direct order ID | Contract → Order ID |
| **Data integrity** | Orders may be unpaid | Orders are always paid |

**The contract_id is the new order_id from Stripe's perspective.** It uniquely identifies the payment intent and can be used to trace back to the actual OXID order once payment is complete.

---

**Last Updated:** 2025-12-01
