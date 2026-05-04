# Sprint 87: Variable (Partial) Capture and Refund

**Date:** 2026-04-14
**Branch:** `b-7.4.x`
**Ticket:** STRP-120

## Problem

The admin Stripe tab only supports **full capture** and **full refund**. Stripe API supports partial amounts for both operations, but our code either hardcodes `amount: null` (full) or explicitly rejects partial amounts.

Real-world scenarios that currently require going to Stripe Dashboard:
- Partial capture: customer cancels 1 of 3 items before shipment — capture only the shipped items
- Partial refund: customer returns 1 item — refund just that item's amount
- Multiple partial refunds: first refund for item A, later refund for item B

## Current State Analysis

| Layer | Capture | Refund |
|-------|---------|--------|
| **Stripe API** | `amount_to_capture` param | `amount` param |
| **Adapter (PaymentIntentHelper/RefundHelper)** | Passes amount to Stripe | Passes amount to Stripe |
| **Request objects (CapturePaymentRequest/RefundPaymentRequest)** | `?float $amount` | `?float $amount` |
| **CaptureService** | Accepts `?float $amount` | N/A |
| **RefundService** | N/A | `processFullRefund()` only, rejects partial |
| **StripeRefundRequestHandler** | N/A | **Explicitly rejects** non-null amount (line 150) |
| **StripeRefundService::validateRefundAmount()** | N/A | **Rejects** amount != availableForRefund |
| **OrderActionDispatcher** | Hardcodes `amount: null` | Hardcodes `amount: null` |
| **Template** | No amount input | No amount input |

**Key insight:** The lower layers (adapter, request objects, Stripe API) already support partial amounts. The blocks are in the **handler**, **service validation**, **dispatcher**, and **template**.

## Stripe Business Rules

### Capture Constraints
- `amount_to_capture` must be > 0
- `amount_to_capture` must be <= `PaymentIntent.amount` (authorized amount)
- Partial capture **releases** the uncaptured remainder to the customer (one-time operation)
- Cannot capture again after partial capture — Stripe releases the rest

### Refund Constraints
- `amount` must be > 0
- Total refunded (all refunds combined) must be <= `Charge.amount_captured`
- Multiple partial refunds are allowed until captured amount is exhausted
- `Charge.amount_refunded` tracks cumulative refunded amount

## Approach

Minimal changes — unblock what's already built, add amount inputs to template.

### What We Unblock (remove artificial restrictions)
1. `OrderActionDispatcher` — pass amount from request instead of hardcoded `null`
2. `StripeRefundRequestHandler` — remove the explicit partial refund rejection (line 150-154)
3. `StripeRefundService::validateRefundAmount()` — validate `amount <= available` instead of `amount == available`

### What We Add
4. `OrderRefund` controller — new `partialRefund()` action method, `partialCapture()` not needed (capture already accepts amount)
5. Template — amount input fields for capture and refund forms
6. Amount validation — server-side in handler/service, client-side max attribute in template

### What We DON'T Change
- `CaptureService` — already accepts `?float $amount`
- `StripeCaptureRequestHandler` — already passes amount through
- `CapturePaymentRequest` / `RefundPaymentRequest` — already have amount fields
- Adapter / Helper layer — already converts to cents and passes to Stripe

## TDD Plan

### Phase 1: RED — Failing Tests

#### 1a. Unit: StripeRefundRequestHandler accepts partial amount
```
File: tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php
Test: testHandleProcessesPartialRefundAmount()
  Arrange: event with amount=50.00 (not null)
  Act: handler.handle(event)
  Assert: refund service called with amount 50.00 (not rejected)
```

#### 1b. Unit: StripeRefundService validates partial amount within limits
```
File: tests/Unit/Stripe/Service/RefundServiceTest.php (existing)
Test: testValidateRefundAmountAcceptsPartialAmount()
  Arrange: availableForRefund=100.00, requestedAmount=50.00
  Act: validateRefundAmount()
  Assert: no exception thrown

Test: testValidateRefundAmountRejectsOverRefund()
  Arrange: availableForRefund=100.00, requestedAmount=150.00
  Act: validateRefundAmount()
  Assert: RefundFailedException thrown
```

#### 1c. Unit: OrderActionDispatcher passes amount parameter
```
File: tests/Integration/Stripe/Controller/Admin/OrderRefundControllerTest.php (existing)
Test: testFullRefundWithAmountEmitsCorrectAmount()
  Arrange: order, amount=50.00
  Act: controller action with amount param
  Assert: event context 'amount' = 50.00
```

#### 1d. Unit: OrderRefund controller extracts amount from request
```
File: tests/Unit/Stripe/Controller/Admin/OrderRefundPartialTest.php (new)
Test: testPartialRefundPassesAmountToDispatcher()
Test: testPartialRefundRejectsNegativeAmount()
Test: testPartialRefundRejectsZeroAmount()
Test: testCapturePassesAmountToDispatcher()
```

### Phase 2: GREEN — Implementation

#### Step 1: Unblock refund handler (remove rejection)
**File:** `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php`
- Remove lines 150-154 (the `if (!$event->isFullRefund())` rejection block)
- The handler should pass `$event->getAmount()` through to the refund service

#### Step 2: Fix refund service validation
**File:** `src/Stripe/Service/StripeRefundService.php`
- Change `validateRefundAmount()`: instead of requiring `amount == available`, validate `amount <= available`
- Keep validation for `amount > 0`

#### Step 3: Update dispatcher to accept amount
**File:** `src/Stripe/Controller/Admin/OrderActionDispatcher.php`
- `dispatchRefund()`: add `?float $amount = null` parameter, pass to context
- `dispatchCapture()`: add `?float $amount = null` parameter, pass to context (replacing hardcoded null)

#### Step 4: Update controller for partial operations
**File:** `src/Stripe/Controller/Admin/OrderRefund.php`
- Modify `fullRefund()` → read `refund_amount` from request, pass to dispatcher
- Modify `capturePayment()` → read `capture_amount` from request, pass to dispatcher
- Add server-side validation: amount > 0, not exceeding limits
- If amount is empty/not provided, pass null (= full operation, backward compatible)

#### Step 5: Update template with amount inputs
**File:** `views/twig/admin/stripe_order_refund.html.twig`
- Capture form: add `capture_amount` input, pre-filled with capturable amount, max=capturable
- Refund form: add `refund_amount` input, pre-filled with refundable amount, max=refundable
- Use `type="number"` with `step="0.01"` and `min="0.01"`
- Label: "Amount to capture/refund" with current max shown

#### Step 6: Add translations
**Files:** `views/admin_twig/{en,de}/stripe_lang.php`
- `STRIPE_CAPTURE_AMOUNT` / `STRIPE_REFUND_AMOUNT` — "Amount"
- `STRIPE_PARTIAL_CAPTURE_TEXT` — "Enter amount to capture (max: {amount})"
- `STRIPE_PARTIAL_REFUND_TEXT` — "Enter amount to refund (max: {amount})"

### Phase 3: REFACTOR — Validation

- `./bin/pre-commit-check.sh --full` — all green
- Verify existing full refund/capture tests still pass (backward compatible)
- Verify E2E screenshots show amount inputs

## Template Design

### Capture Form (updated)
```
┌─────────────────────────────────────────────────┐
│ Capture Payment                                  │
├─────────────────────────────────────────────────┤
│                                                   │
│ Authorized amount: 130,39 EUR                     │
│                                                   │
│ Amount to capture          Reason                 │
│ ┌──────────────────┐      ┌───────────────────┐  │
│ │ 130.39       ▾   │      │ optional text      │  │
│ └──────────────────┘      └───────────────────┘  │
│ max: 130.39 EUR                                   │
│                                                   │
│ [Capture Payment]                                 │
│                                                   │
│ ⚠ Partial capture releases remaining amount       │
│   to the customer. This cannot be undone.         │
└─────────────────────────────────────────────────┘
```

### Refund Form (updated)
```
┌─────────────────────────────────────────────────┐
│ Refund                                            │
├─────────────────────────────────────────────────┤
│                                                   │
│ Available for refund: 100,00 EUR                  │
│                                                   │
│ Amount to refund                                  │
│ ┌──────────────────┐                              │
│ │ 100.00       ▾   │                              │
│ └──────────────────┘                              │
│ max: 100.00 EUR                                   │
│                                                   │
│ Reason               Description                  │
│ ┌─────────────┐     ┌──────────────────────────┐ │
│ │ -- Select --│     │ optional text             │ │
│ └─────────────┘     └──────────────────────────┘ │
│                                                   │
│ [Execute Refund]                                  │
└─────────────────────────────────────────────────┘
```

## Sub-Sprints

| Sprint | Description | Depends On | Files |
|--------|-------------|------------|-------|
| 87a | RED — Write failing tests (handler, service, controller) | — | 4 test files (2 new, 2 modified) |
| 87b | GREEN — Unblock refund handler + fix validation | 87a | `StripeRefundRequestHandler.php`, `StripeRefundService.php` |
| 87c | GREEN — Dispatcher + controller amount params | 87b | `OrderActionDispatcher.php`, `OrderRefund.php` |
| 87d | GREEN — Template amount inputs + translations | 87c | Stripe tab template, EN/DE lang files |
| 87e | GREEN — Order overview: Factual Captured + Refunded amounts | 87d | Order model, overview template, lang files |
| 87f | REFACTOR — Pre-commit + E2E screenshot | 87e | — |

## Sprint 87e: Order Overview — Factual Captured Amount + Refunded Amount

### Problem

The order overview tab shows the order total from OXID (`oxorder.oxtotalordersum`), but this may differ from what was actually captured by Stripe (partial capture releases the rest). Admins have no visibility into the real captured/refunded amounts without switching to the Stripe tab.

### What to Add

Two new lines below "Sum total" on the order overview tab, visible only for Stripe orders:

```
Sum total                130,39 EUR
────────────────────────────────────
Factual Captured Amount  100,00 EUR    ← always shown for Stripe orders
Refunded Amount           10,00 EUR    ← only shown if amount_refunded > 0
```

### Implementation

**1. Extend `Order` model** (`src/Stripe/Model/Order.php`)

Add two public methods (callable from Twig via `edit.methodName()`):

```php
public function getStripeCapturedAmount(): string
```
- Fetches `PaymentIntent` → `Charge.amount_captured / 100`
- Returns formatted price string (using order currency)
- Returns empty string if not a Stripe order or no charge

```php
public function getStripeRefundedAmount(): string
```
- Fetches `Charge.amount_refunded / 100`
- Returns formatted price string
- Returns empty string if not a Stripe order or no refunds

```php
public function hasStripeRefunds(): bool
```
- Returns `Charge.amount_refunded > 0`

**Note:** These methods need access to the Stripe API. Since `Order` model doesn't support constructor DI (OXID virtual parent), use `ContainerFactory::getInstance()->getContainer()->get(StripeOrderApiService::class)` (same pattern as existing admin controllers).

**2. Override `order_info.html.twig` block**

OXID admin supports template overrides via module template paths. The `order_info.html.twig` has a `{% block admin_order_overview_info_sumtotal %}` block (line 83).

**File:** `views/admin_twig/tpl/include/order_info.html.twig`

```twig
{% extends "include/order_info.html.twig" %}

{% block admin_order_overview_info_sumtotal %}
  {{ parent() }}
  {% if edit.getStripeCapturedAmount() %}
  <tr>
    <td class="edittext" height="15">
      {{ translate({ ident: "STRIPE_FACTUAL_CAPTURED_AMOUNT" }) }}&nbsp;&nbsp;
    </td>
    <td class="edittext" align="right">
      <b>{{ edit.getStripeCapturedAmount() }}</b>
    </td>
    <td class="edittext">&nbsp;<b>
      {% if edit.oxorder__oxcurrency.value %}
        {{ edit.oxorder__oxcurrency.value }}
      {% else %}
        {{ currency.name }}
      {% endif %}
    </b></td>
  </tr>
  {% endif %}
  {% if edit.hasStripeRefunds() %}
  <tr>
    <td class="edittext" height="15">
      {{ translate({ ident: "STRIPE_REFUNDED_AMOUNT" }) }}&nbsp;&nbsp;
    </td>
    <td class="edittext" align="right">
      <b style="color: #dc2626;">{{ edit.getStripeRefundedAmount() }}</b>
    </td>
    <td class="edittext">&nbsp;<b>
      {% if edit.oxorder__oxcurrency.value %}
        {{ edit.oxorder__oxcurrency.value }}
      {% else %}
        {{ currency.name }}
      {% endif %}
    </b></td>
  </tr>
  {% endif %}
{% endblock %}
```

**3. Translations**

| Key | EN | DE |
|-----|----|----|
| `STRIPE_FACTUAL_CAPTURED_AMOUNT` | Factual Captured Amount | Tatsächlich erfasster Betrag |
| `STRIPE_REFUNDED_AMOUNT` | Refunded Amount | Erstatteter Betrag |

**4. Tests**

| Test | File | What |
|------|------|------|
| Unit: `getStripeCapturedAmount()` returns formatted amount | `tests/Unit/Stripe/Model/OrderCapturedAmountTest.php` | Mock API, verify formatted output |
| Unit: `hasStripeRefunds()` returns true when refunded | Same file | Verify boolean logic |
| Unit: returns empty string for non-Stripe orders | Same file | PaymentType check |

### Files for 87e

| Action | File | Change |
|--------|------|--------|
| MODIFY | `src/Stripe/Model/Order.php` | +`getStripeCapturedAmount()`, +`getStripeRefundedAmount()`, +`hasStripeRefunds()` |
| CREATE | `views/admin_twig/tpl/include/order_info.html.twig` | Override `admin_order_overview_info_sumtotal` block |
| MODIFY | `views/admin_twig/en/stripe_lang.php` | +2 translation keys |
| MODIFY | `views/admin_twig/de/stripe_lang.php` | +2 translation keys |
| CREATE | `tests/Unit/Stripe/Model/OrderCapturedAmountTest.php` | 3 unit tests |

## Files Changed Summary (Complete)

| Action | File | Change | Sprint |
|--------|------|--------|--------|
| MODIFY | `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` | Remove partial refund rejection | 87b |
| MODIFY | `src/Stripe/Service/StripeRefundService.php` | Fix `validateRefundAmount()` to allow partial | 87b |
| MODIFY | `src/Stripe/Controller/Admin/OrderActionDispatcher.php` | Accept `?float $amount` in dispatch methods | 87c |
| MODIFY | `src/Stripe/Controller/Admin/OrderRefund.php` | Read amount from request, pass to dispatcher | 87c |
| MODIFY | `views/twig/admin/stripe_order_refund.html.twig` | Add amount inputs to capture + refund forms | 87d |
| MODIFY | `src/Stripe/Model/Order.php` | +captured/refunded amount methods | 87e |
| CREATE | `views/admin_twig/tpl/include/order_info.html.twig` | Override overview block for captured/refunded | 87e |
| MODIFY | `views/admin_twig/en/stripe_lang.php` | +5 translation keys total | 87d+87e |
| MODIFY | `views/admin_twig/de/stripe_lang.php` | +5 translation keys total | 87d+87e |
| CREATE | `tests/Unit/Stripe/Controller/Admin/OrderRefundPartialTest.php` | Partial amount controller tests | 87a |
| MODIFY | `tests/Unit/Stripe/EventSystem/Handler/StripeRefundRequestHandlerTest.php` | +1 test: partial accepted | 87a |
| MODIFY | `tests/Unit/Stripe/Service/RefundServiceTest.php` | +2 tests: partial validation | 87a |
| MODIFY | `tests/Integration/Stripe/Controller/Admin/OrderRefundControllerTest.php` | +1 test: amount in event | 87a |
| CREATE | `tests/Unit/Stripe/Model/OrderCapturedAmountTest.php` | 3 unit tests | 87e |

## Validation Rules (server-side)

| Rule | Capture | Refund |
|------|---------|--------|
| Amount > 0 | Yes | Yes |
| Amount <= max | <= `PaymentIntent.amount / 100` | <= `(Charge.amount_captured - Charge.amount_refunded) / 100` |
| Empty = full | null → Stripe captures full | null → Stripe refunds all remaining |
| Source of truth | `OrderRefundViewDataProvider::getCaptureableAmount()` | `OrderRefundViewDataProvider::getRemainingRefundableAmount()` |

## Backward Compatibility

- If no amount is provided in the form (empty string), it's treated as `null` → full capture/refund (same as current behavior)
- Existing tests that pass `amount: null` continue to work
- No changes to Stripe adapter or payment-component layers
- Event structure unchanged — `amount` field already exists in context

## Out of Scope

- Multiple captures (Stripe only allows one capture per PaymentIntent)
- Refund-to-different-payment-method
- Amount input formatting (currency symbols, thousand separators) — use plain number input
- Client-side JS validation beyond HTML5 `max`/`min`/`step` attributes
