# Sprint 6: Admin Backend Capture UI

**Status:** PENDING
**Priority:** MEDIUM
**Estimated Effort:** 3 hours
**Depends On:** Sprint 4 (CaptureRequestedEvent)

---

## Objective

Add a "Capture Payment" button to the admin order detail page for orders with payments in AUTHORIZED state.

---

## Admin UI Requirements

### Order Detail Page

When viewing an order with an AUTHORIZED payment:

```
┌─────────────────────────────────────────────────────────────────┐
│ Order: 2025-12345                                               │
├─────────────────────────────────────────────────────────────────┤
│ Customer: John Doe                                              │
│ Date: 2025-12-16 14:30:00                                       │
│ Total: €99.99                                                   │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │ Payment Status                                              │ │
│ ├─────────────────────────────────────────────────────────────┤ │
│ │ Method: Stripe (Card)                                       │ │
│ │ Status: AUTHORIZED (awaiting capture)                       │ │
│ │ PaymentIntent: pi_3ABC123...                                │ │
│ │ Authorized Amount: €99.99                                   │ │
│ │ Authorization Expires: 2025-12-23 14:30:00                  │ │
│ │                                                             │ │
│ │ [Capture Full Amount €99.99] [Capture Partial] [Void]      │ │
│ └─────────────────────────────────────────────────────────────┘ │
│                                                                 │
│ Items:                                                          │
│ - Product A x 1 - €49.99                                        │
│ - Product B x 1 - €50.00                                        │
└─────────────────────────────────────────────────────────────────┘
```

---

## Components to Create

### 1. Admin Controller Extension

**File:** `src/Stripe/Controller/Admin/OrderCapture.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller\Admin;

use OxidEsales\Eshop\Application\Controller\Admin\AdminDetailsController;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\CaptureRequestedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidEsales\Eshop\Core\Registry;

class OrderCapture extends AdminDetailsController
{
    protected $_sThisTemplate = '@osc_stripe_wallet/admin/stripe_order_capture.html.twig';

    private EventDispatcher $eventDispatcher;
    private ContractRepositoryInterface $contractRepository;

    public function render(): string
    {
        parent::render();

        $orderId = $this->getEditObjectId();
        $contract = $this->getContractByOrderId($orderId);

        $this->addTplParam('contract', $contract);
        $this->addTplParam('canCapture', $contract?->getState()->isAuthorized() ?? false);
        $this->addTplParam('authorizedAmount', $this->getAuthorizedAmount($contract));
        $this->addTplParam('paymentIntentId', $contract?->getMetadataValue('provider_payment_id'));

        return $this->_sThisTemplate;
    }

    public function captureFullAmount(): void
    {
        $orderId = $this->getEditObjectId();
        $contract = $this->getContractByOrderId($orderId);

        if ($contract === null || !$contract->getState()->isAuthorized()) {
            $this->addErrorMessage('OSC_STRIPE_CAPTURE_ERROR_INVALID_STATE');
            return;
        }

        try {
            $this->getEventDispatcher()->dispatch(new CaptureRequestedEvent(
                contractId: $contract->getId(),
                amount: null, // Full amount
                triggeredBy: 'admin',
                idempotencyKey: $this->generateIdempotencyKey($contract->getId()),
                reason: 'Admin manual capture'
            ));

            $this->addSuccessMessage('OSC_STRIPE_CAPTURE_SUCCESS');
        } catch (\Exception $e) {
            $this->addErrorMessage('OSC_STRIPE_CAPTURE_ERROR: ' . $e->getMessage());
        }
    }

    public function capturePartialAmount(): void
    {
        $orderId = $this->getEditObjectId();
        $contract = $this->getContractByOrderId($orderId);
        $amount = (float) Registry::getRequest()->getRequestParameter('capture_amount');

        if ($contract === null || !$contract->getState()->isAuthorized()) {
            $this->addErrorMessage('OSC_STRIPE_CAPTURE_ERROR_INVALID_STATE');
            return;
        }

        if ($amount <= 0) {
            $this->addErrorMessage('OSC_STRIPE_CAPTURE_ERROR_INVALID_AMOUNT');
            return;
        }

        try {
            $this->getEventDispatcher()->dispatch(new CaptureRequestedEvent(
                contractId: $contract->getId(),
                amount: $amount,
                triggeredBy: 'admin',
                idempotencyKey: $this->generateIdempotencyKey($contract->getId(), $amount),
                reason: 'Admin partial capture'
            ));

            $this->addSuccessMessage('OSC_STRIPE_CAPTURE_PARTIAL_SUCCESS');
        } catch (\Exception $e) {
            $this->addErrorMessage('OSC_STRIPE_CAPTURE_ERROR: ' . $e->getMessage());
        }
    }

    private function generateIdempotencyKey(string $contractId, ?float $amount = null): string
    {
        return sprintf(
            'capture-%s-%s-%d',
            $contractId,
            $amount !== null ? (string) $amount : 'full',
            time()
        );
    }

    private function getContractByOrderId(string $orderId): ?PaymentContract
    {
        return $this->getContractRepository()->findByOrderId($orderId);
    }

    // ... helper methods ...
}
```

### 2. Twig Template

**File:** `views/twig/admin/stripe_order_capture.html.twig`

```twig
{% extends "admin/admin_details.html.twig" %}

{% block admin_details_main %}
<form name="transfer" id="transfer" action="{{ oViewConf.getSelfLink() }}" method="post">
    {{ oViewConf.getHiddenSid()|raw }}
    <input type="hidden" name="cl" value="stripe_order_capture">
    <input type="hidden" name="oxid" value="{{ oxid }}">

    <div class="stripe-capture-panel">
        <h3>{{ translate({ ident: "OSC_STRIPE_CAPTURE_TITLE" }) }}</h3>

        {% if canCapture %}
            <div class="capture-info">
                <table>
                    <tr>
                        <td>{{ translate({ ident: "OSC_STRIPE_PAYMENT_STATUS" }) }}:</td>
                        <td><span class="status-authorized">{{ translate({ ident: "OSC_STRIPE_STATUS_AUTHORIZED" }) }}</span></td>
                    </tr>
                    <tr>
                        <td>{{ translate({ ident: "OSC_STRIPE_AUTHORIZED_AMOUNT" }) }}:</td>
                        <td>{{ authorizedAmount|number_format(2, ',', '.') }} {{ currency }}</td>
                    </tr>
                    <tr>
                        <td>{{ translate({ ident: "OSC_STRIPE_PAYMENT_INTENT" }) }}:</td>
                        <td><code>{{ paymentIntentId }}</code></td>
                    </tr>
                </table>
            </div>

            <div class="capture-actions">
                <h4>{{ translate({ ident: "OSC_STRIPE_CAPTURE_OPTIONS" }) }}</h4>

                <!-- Full Capture Button -->
                <div class="capture-option">
                    <button type="submit" name="fnc" value="captureFullAmount" class="btn btn-primary">
                        {{ translate({ ident: "OSC_STRIPE_CAPTURE_FULL" }) }}
                        ({{ authorizedAmount|number_format(2, ',', '.') }} {{ currency }})
                    </button>
                </div>

                <!-- Partial Capture -->
                <div class="capture-option">
                    <label>{{ translate({ ident: "OSC_STRIPE_CAPTURE_PARTIAL_LABEL" }) }}:</label>
                    <input type="number" name="capture_amount" step="0.01" min="0.01" max="{{ authorizedAmount }}" placeholder="0.00">
                    <button type="submit" name="fnc" value="capturePartialAmount" class="btn btn-secondary">
                        {{ translate({ ident: "OSC_STRIPE_CAPTURE_PARTIAL" }) }}
                    </button>
                </div>
            </div>

            <div class="capture-warning">
                <p>{{ translate({ ident: "OSC_STRIPE_CAPTURE_WARNING" }) }}</p>
            </div>
        {% else %}
            <div class="capture-unavailable">
                <p>{{ translate({ ident: "OSC_STRIPE_CAPTURE_NOT_AVAILABLE" }) }}</p>
                <p>{{ translate({ ident: "OSC_STRIPE_CURRENT_STATUS" }) }}: {{ contract.state.value }}</p>
            </div>
        {% endif %}
    </div>
</form>
{% endblock %}
```

### 3. Translations

**File:** `translations/en/osc_stripe_wallet_lang.php`

```php
// Capture UI translations
'OSC_STRIPE_CAPTURE_TITLE' => 'Payment Capture',
'OSC_STRIPE_PAYMENT_STATUS' => 'Payment Status',
'OSC_STRIPE_STATUS_AUTHORIZED' => 'Authorized (awaiting capture)',
'OSC_STRIPE_AUTHORIZED_AMOUNT' => 'Authorized Amount',
'OSC_STRIPE_PAYMENT_INTENT' => 'Payment Intent ID',
'OSC_STRIPE_CAPTURE_OPTIONS' => 'Capture Options',
'OSC_STRIPE_CAPTURE_FULL' => 'Capture Full Amount',
'OSC_STRIPE_CAPTURE_PARTIAL' => 'Capture',
'OSC_STRIPE_CAPTURE_PARTIAL_LABEL' => 'Partial capture amount',
'OSC_STRIPE_CAPTURE_WARNING' => 'Warning: Capturing the payment will transfer funds from the customer\'s account. This action cannot be undone.',
'OSC_STRIPE_CAPTURE_NOT_AVAILABLE' => 'Payment capture is not available for this order.',
'OSC_STRIPE_CURRENT_STATUS' => 'Current Status',
'OSC_STRIPE_CAPTURE_SUCCESS' => 'Payment captured successfully.',
'OSC_STRIPE_CAPTURE_PARTIAL_SUCCESS' => 'Partial payment captured successfully.',
'OSC_STRIPE_CAPTURE_ERROR_INVALID_STATE' => 'Cannot capture: payment is not in authorized state.',
'OSC_STRIPE_CAPTURE_ERROR_INVALID_AMOUNT' => 'Invalid capture amount.',
```

**File:** `translations/de/osc_stripe_wallet_lang.php`

```php
// Capture UI translations
'OSC_STRIPE_CAPTURE_TITLE' => 'Zahlungserfassung',
'OSC_STRIPE_PAYMENT_STATUS' => 'Zahlungsstatus',
'OSC_STRIPE_STATUS_AUTHORIZED' => 'Autorisiert (warten auf Erfassung)',
'OSC_STRIPE_AUTHORIZED_AMOUNT' => 'Autorisierter Betrag',
'OSC_STRIPE_PAYMENT_INTENT' => 'Payment Intent ID',
'OSC_STRIPE_CAPTURE_OPTIONS' => 'Erfassungsoptionen',
'OSC_STRIPE_CAPTURE_FULL' => 'Vollen Betrag erfassen',
'OSC_STRIPE_CAPTURE_PARTIAL' => 'Erfassen',
'OSC_STRIPE_CAPTURE_PARTIAL_LABEL' => 'Teilbetrag erfassen',
'OSC_STRIPE_CAPTURE_WARNING' => 'Warnung: Die Erfassung der Zahlung überträgt Geld vom Kundenkonto. Diese Aktion kann nicht rückgängig gemacht werden.',
'OSC_STRIPE_CAPTURE_NOT_AVAILABLE' => 'Die Zahlungserfassung ist für diese Bestellung nicht verfügbar.',
'OSC_STRIPE_CURRENT_STATUS' => 'Aktueller Status',
'OSC_STRIPE_CAPTURE_SUCCESS' => 'Zahlung erfolgreich erfasst.',
'OSC_STRIPE_CAPTURE_PARTIAL_SUCCESS' => 'Teilzahlung erfolgreich erfasst.',
'OSC_STRIPE_CAPTURE_ERROR_INVALID_STATE' => 'Erfassung nicht möglich: Zahlung ist nicht im autorisierten Status.',
'OSC_STRIPE_CAPTURE_ERROR_INVALID_AMOUNT' => 'Ungültiger Erfassungsbetrag.',
```

### 4. Register Controller in metadata.php

```php
'controllers' => [
    // ... existing controllers ...
    'stripe_order_capture' => OrderCapture::class,
],
```

### 5. Add Menu Entry (Optional)

**File:** `menu.xml`

```xml
<MAINMENU id="mxorders">
    <SUBMENU id="mxdisplayorders">
        <TAB id="stripe_capture" cl="stripe_order_capture" />
    </SUBMENU>
</MAINMENU>
```

---

## Acceptance Criteria

- [ ] "Capture" tab appears in order details for Stripe payments
- [ ] Capture button only enabled for AUTHORIZED state
- [ ] Full capture executes and shows success message
- [ ] Partial capture allows specifying amount
- [ ] Error messages displayed for invalid operations
- [ ] German and English translations complete
- [ ] PHPStan level 6 passes
- [ ] PSR-12 code style passes

---

## Test Commands

```bash
# Manual testing in admin
# 1. Create order with manual capture mode
# 2. Go to Administer Orders → Order → select order
# 3. Click on "Stripe Capture" tab
# 4. Verify capture button works

# Run any admin controller tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  tests/Unit/Stripe/Controller/Admin/
```

---

## Notes

- The capture UI should be simple and clear
- Future enhancement: Add capture history/log
- Future enhancement: Add void authorization button
- Consider adding AJAX capture without page reload
