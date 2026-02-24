# Stripe Footer Widget Implementation

This document describes the Stripe Footer Widget implementation for the one-page checkout module.

## Overview

The Stripe Footer Widget provides a custom checkout footer with:
- ✅ Stripe-branded submit button with signature purple gradient
- ✅ Enhanced security disclaimers (PCI compliance messaging)
- ✅ Terms checkbox including Stripe Consumer Terms
- ✅ Loading states with full-screen overlay
- ✅ Error handling with user-friendly messages
- ✅ EventBus integration for state synchronization
- ✅ Debug mode for development

**IMPORTANT:** This widget is used in **one-page checkout UI contexts** (Buy Now modal, checkout flows), NOT in the global shop footer/layout. The widget is **not URL-dependent** - it renders dynamically when the user selects Stripe as the payment method in:
- Buy Now modal footer section
- Full-page checkout footer section

## Files Created

### 1. PHP Widget Controller
**Location:** `src/Stripe/Component/Widget/StripeCheckoutFooter.php`

Widget controller that:
- Extends OXID's `WidgetController`
- Collects checkout data from view parameters
- Adds Stripe-specific configuration (mode, keys, URLs)
- Provides data to the template

### 2. Twig Template
**Location:** `views/twig/widget/checkout/stripe-footer.html.twig`

Template that renders:
- Security disclaimers with Stripe branding
- Terms checkbox with Stripe Consumer Terms link
- Custom submit button with Stripe styling
- Loading overlay for payment processing
- Error message container
- Debug panel (development mode only)

### 3. Stimulus Controller
**Location:** `resources/build/js/controllers/stripe_checkout_footer_controller.js`

JavaScript controller that:
- Uses `withEventBus` mixin for event management
- Validates terms checkbox
- Handles submit button clicks
- Listens to basket and payment events
- Manages loading and error states
- Updates total price display

### 4. Module Configuration

**metadata.php:**
```php
'controllers' => [
    // ...
    'stripecheckoutfooter' => \OxidEsales\Payments\Stripe\Component\Widget\StripeCheckoutFooter::class,
],
```

**Events.php:**
- `registerFooterWidget()` - Registers widget in `FooterWidgetRegistry`
- Called during `onActivate()` event

**app.js:**
```javascript
import StripeCheckoutFooterController from "./controllers/stripe_checkout_footer_controller"
Stimulus.register("stripe-checkout-footer", StripeCheckoutFooterController)
```

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│ One-Page Checkout Module                               │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ Buy Now Modal / Checkout Page                      │ │
│  │ (NOT related to any specific URL!)                 │ │
│  │                                                     │ │
│  │ ┌─────────────────────────────────────────────────┐│ │
│  │ │ Footer Widget Slot                              ││ │
│  │ │ {% block checkout_footer %}                     ││ │
│  │ │   {% set widget = getFooterWidget(paymentId) %} ││ │
│  │ │   {% if widget == 'stripecheckoutfooter' %}     ││ │
│  │ │     {{ include_widget({                         ││ │
│  │ │         cl: 'stripecheckoutfooter',             ││ │
│  │ │         basketId: ...,                          ││ │
│  │ │         paymentMethodId: 'oxidstripe'           ││ │
│  │ │     }) }}                                       ││ │
│  │ │   {% else %}                                    ││ │
│  │ │     {# Standard footer #}                       ││ │
│  │ │   {% endif %}                                   ││ │
│  │ │ {% endblock %}                                  ││ │
│  │ └─────────────────────────────────────────────────┘│ │
│  │                                                     │ │
│  │ Renders in:                                        │ │
│  │ • Buy Now modal footer                             │ │
│  │ • Checkout page footer                             │ │
│  │                                                     │ │
│  │ Triggered by:                                      │ │
│  │ • User selecting 'oxidstripe' payment method       │ │
│  └────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│ Stripe Module                                           │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ StripeCheckoutFooter Widget Controller            │ │
│  │  - Receives checkout data from slot                │ │
│  │  - Adds Stripe config (mode, keys, URLs)           │ │
│  │  - Renders widget template                         │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ stripe-footer.html.twig                            │ │
│  │  - Custom UI with Stripe branding                  │ │
│  │  - Terms checkbox + Stripe Consumer Terms          │ │
│  │  - Submit button with purple gradient              │ │
│  │  - data-controller="stripe-checkout-footer"        │ │
│  └────────────────────────────────────────────────────┘ │
│                                                          │
│  ┌────────────────────────────────────────────────────┐ │
│  │ stripe_checkout_footer_controller.js               │ │
│  │  - EventBus integration (withEventBus mixin)       │ │
│  │  - State management (loading, errors)              │ │
│  │  - User interactions (terms, submit)               │ │
│  │  - Listens: basket updates, payment events         │ │
│  │  - Emits: terms accepted, submit clicked           │ │
│  └────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────┘
```

## Data Flow

### 1. Registration (Module Activation)
```php
Events::onActivate()
  ↓
registerFooterWidget()
  ↓
FooterWidgetRegistry::registerWidget('oxidstripe', 'stripecheckoutfooter')
```

### 2. Rendering (Checkout Page Load)
```
Checkout Template
  ↓
ViewConfig::getCheckoutFooterWidget('oxidstripe')
  ↓
FooterWidgetRegistry::getWidgetForPaymentMethod('oxidstripe')
  ↓
Returns: 'stripecheckoutfooter'
  ↓
include_widget({ cl: 'stripecheckoutfooter', ... })
  ↓
StripeCheckoutFooter::render()
  ↓
stripe-footer.html.twig rendered
```

### 3. User Interaction
```
User checks terms checkbox
  ↓
stripe_checkout_footer_controller.js::validateTerms()
  ↓
EventBus: broadcast('oe:footer:terms-accepted')
  ↓
Submit button enabled

User clicks submit button
  ↓
stripe_checkout_footer_controller.js::processPayment()
  ↓
EventBus: broadcast('oe:footer:submit-clicked')
  ↓
Payment controller processes payment
```

### 4. State Updates
```
Basket updated
  ↓
EventBus: broadcast('oe:basket:updated')
  ↓
stripe_checkout_footer_controller.js::handleBasketUpdate()
  ↓
Update total price display

Payment processing
  ↓
EventBus: broadcast('oe:payment:processing')
  ↓
stripe_checkout_footer_controller.js::showLoader()
  ↓
Full-screen loading overlay shown
```

## EventBus Integration

### Events Emitted by Footer Widget

| Event | When | Data |
|-------|------|------|
| `oe:footer:terms-accepted` | Terms checkbox checked | `{ paymentMethod, basketId, timestamp }` |
| `oe:footer:submit-clicked` | Submit button clicked | `{ paymentMethod, basketId, totalPrice, currency, confirmed, timestamp }` |

### Events Listened by Footer Widget

| Event | Action | Handler |
|-------|--------|---------|
| `oe:basket:updated` | Update total price display | `handleBasketUpdate()` |
| `oe:payment:processing` | Show loading overlay | `showLoader()` |
| `oe:payment:complete` | Hide loader, show success | `hideLoader()` + `showSuccess()` |
| `oe:payment:error` | Hide loader, show error | `hideLoader()` + `showError()` |
| `oe:payment:method-selected` | Show/hide footer | `handlePaymentMethodChange()` |

## Installation Steps

### 1. Ensure One-Page Checkout Module Has Footer Widget Support

The one-page checkout module needs:
- `src/Service/FooterWidgetRegistry.php`
- `src/Core/ViewConfig::getCheckoutFooterWidget()`
- Footer widget slot in checkout template

See: `docs/FOOTER_WIDGET_ARCHITECTURE.md` in one-page-checkout module

### 2. Build JavaScript Assets

```bash
cd /path/to/stripe-module

# Development build
npm run build:dev

# Production build
npm run build

# Watch mode (auto-rebuild)
npm run watch
```

### 3. Add Translations

Add translations to:
- `translations/en/stripe_lang.php`
- `translations/de/stripe_lang.php`

See: `FOOTER_WIDGET_TRANSLATIONS.md`

### 4. Activate/Reactivate Module

```bash
# In OXID admin or via CLI
cd /path/to/oxid-shop
./vendor/bin/oe-console oe:module:deactivate oe_payments_stripe_wallet
./vendor/bin/oe-console oe:module:activate oe_payments_stripe_wallet
```

Or via Docker:
```bash
docker-compose exec -T php bash -c "cd /var/www && ./bin/oe-console oe:module:deactivate oe_payments_stripe_wallet"
docker-compose exec -T php bash -c "cd /var/www && ./bin/oe-console oe:module:activate oe_payments_stripe_wallet"
```

### 5. Clear Cache

```bash
# From module directory
make clear-cache

# Or manually
rm -rf /path/to/oxid-shop/tmp/*
```

## Development & Debugging

### Enable Debug Mode

Set environment variable:
```bash
export STRIPE_DEV_MODE=1
```

Or enable OXID debug mode in admin panel.

### Debug Footer Widget

1. **Check widget registration:**
```php
$container = ContainerFactory::getInstance()->getContainer();
$registry = $container->get(FooterWidgetRegistry::class);
var_dump($registry->getAllWidgets());
// Should show: ['oxidstripe' => 'stripecheckoutfooter']
```

2. **Check EventBus communication:**
```javascript
// In browser console
window.eventBus.setDebug(true)
window.eventBus.getEventHistory()
window.eventBus.printStats()
```

3. **Check controller registration:**
```javascript
// In browser console
Stimulus.router.modulesByIdentifier
// Should include: "stripe-checkout-footer"
```

### Common Issues

**Widget not rendering:**
- Check `FooterWidgetRegistry` has the widget registered
- Verify payment method ID is 'oxidstripe'
- Check template path in `StripeCheckoutFooter::$_sThisTemplate`

**Stimulus controller not working:**
- Verify controller is imported and registered in `app.js`
- Check browser console for JavaScript errors
- Ensure `data-controller="stripe-checkout-footer"` in template

**EventBus events not firing:**
- Check `withEventBus` mixin is imported and used
- Verify EventBus is available: `window.eventBus`
- Check event names match exactly (case-sensitive)

**Styling issues:**
- Check inline styles in template are loading
- Verify Bootstrap 5 is available
- Check for CSS conflicts with theme

## Testing

### Manual Testing Checklist

- [ ] Widget renders when Stripe payment method selected
- [ ] Standard footer shows when other payment method selected
- [ ] Terms checkbox enables/disables submit button
- [ ] Submit button shows total price correctly
- [ ] Click submit broadcasts `oe:footer:submit-clicked` event
- [ ] Loading overlay appears during payment processing
- [ ] Error message shows on payment failure
- [ ] Success state shows on payment complete
- [ ] Basket updates reflect in total price
- [ ] Debug panel shows in development mode
- [ ] All translations display correctly (EN/DE)

### Browser Testing

Test in:
- [ ] Chrome/Edge (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Mobile browsers (iOS Safari, Chrome Android)

## Customization

### Change Button Text

Edit template:
```twig
<span class="button-text">
    {{ translate({ ident: "YOUR_CUSTOM_TEXT" }) }}
</span>
```

### Add Custom Disclaimers

Edit template section "Stripe Security Disclaimers":
```twig
<div class="stripe-disclaimers mb-3">
    <!-- Add your custom disclaimer here -->
</div>
```

### Modify Button Styling

Edit inline styles in template:
```css
.stripe-submit-button {
    background: linear-gradient(135deg, #YOUR_COLOR_1 0%, #YOUR_COLOR_2 100%);
    /* ... */
}
```

### Add Custom Events

In `stripe_checkout_footer_controller.js`:
```javascript
someCustomAction() {
    this.broadcast('oe:stripe:custom-event', {
        // Your data
    })
}
```

## Performance Considerations

- Widget is **server-rendered** (no AJAX overhead)
- Stimulus controller lazy-loads on user interaction
- EventBus uses efficient event delegation
- Loading overlay prevents duplicate submissions
- Inline styles avoid extra HTTP requests

## Security Considerations

- CSRF token passed securely to widget
- No sensitive data in JavaScript
- Payment processing handled server-side
- PCI compliance messaging reassures users
- Terms must be accepted before submission

## Related Documentation

- **[FOOTER_WIDGET_ARCHITECTURE.md](../../onepage-checkout/docs/FOOTER_WIDGET_ARCHITECTURE.md)** - Widget architecture guide
- **[FOOTER_WIDGET_QUICK_REFERENCE.md](../../onepage-checkout/docs/reference/FOOTER_WIDGET_QUICK_REFERENCE.md)** - Quick reference
- **[EVENT_BUS_GUIDE.md](../../onepage-checkout/docs/EVENT_BUS_GUIDE.md)** - EventBus documentation
- **[FOOTER_WIDGET_TRANSLATIONS.md](FOOTER_WIDGET_TRANSLATIONS.md)** - Translation keys

## Support

### Logs to Check

- OXID logs: `/path/to/shop/log/oxideshop.log`
- Browser console: F12 → Console
- Network tab: F12 → Network (check for failed requests)

### Getting Help

1. Enable debug mode and check logs
2. Verify widget registration
3. Check EventBus communication
4. Review browser console for errors
5. Post issue with debug output

---

**Last Updated:** 2026-02-24
**Version:** 1.0.0
**Status:** ✅ Implemented and Ready for Testing
