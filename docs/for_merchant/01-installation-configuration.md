# Stripe Payment Module - Merchant Guide

## Installation, Configuration & Usage

**Module:** `oxid-esales/stripe-wallet`
**Version:** 1.0.0
**Requires:** OXID eShop 7.4+, PHP 8.2+

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Stripe Dashboard Setup](#stripe-dashboard-setup)
5. [Payment Methods](#payment-methods)
6. [Order Management](#order-management)
7. [Refunds](#refunds)
8. [Troubleshooting](#troubleshooting)

---

## Requirements

### System Requirements

| Component | Version |
|-----------|---------|
| OXID eShop CE/EE | 7.4+ |
| PHP | 8.2+ |
| MySQL/MariaDB | 8.0+ / 10.4+ |

### Dependencies

The Stripe module requires the **payment-component** package which provides the event-driven architecture:

```bash
# Automatically installed as dependency
oxid-esales/payment-component: *
stripe/stripe-php: ^18.0
```

### Stripe Account

- Active Stripe account (https://dashboard.stripe.com)
- API keys (test and live)
- Webhook endpoint configured

---

## Installation

### Step 1: Install via Composer

```bash
cd /path/to/your/oxid-shop

# Install the Stripe module
composer require oxid-esales/stripe-wallet

# Clear cache
rm -rf var/cache/*
```

### Step 2: Run Database Migrations

The payment-component creates required database tables:

```bash
# Run migrations
vendor/bin/doctrine-migrations migrate \
  --configuration=extensions/payment-component/migration/migrations.yml \
  --db-configuration=extensions/payment-component/migration/migrations-db.php \
  --no-interaction
```

### Step 3: Activate the Module

```bash
# Via console
bin/oe-console oe:module:activate oe_payments_stripe_wallet

# Or via Admin Panel:
# Extensions → Modules → Stripe Payment Gateway → Activate
```

### Step 4: Clear Cache

```bash
rm -rf var/cache/*
bin/oe-console oe:cache:clear
```

---

## Configuration

### Admin Panel Configuration

Navigate to: **Extensions → Modules → Stripe Payment Gateway → Settings**

#### General Settings

| Setting | Description | Values |
|---------|-------------|--------|
| **Mode** | API environment | `test` / `live` |
| **Test Secret Key** | Stripe test API secret key | `sk_test_...` |
| **Test Publishable Key** | Stripe test public key | `pk_test_...` |
| **Live Secret Key** | Stripe live API secret key | `sk_live_...` |
| **Live Publishable Key** | Stripe live public key | `pk_live_...` |
| **Capture Mode** | When to capture funds | `automatic` / `manual` |
| **Log Transactions** | Enable transaction logging | `Yes` / `No` |
| **Filter by Country** | Show payment based on billing country | `Yes` / `No` |
| **Filter by Currency** | Show payment based on basket currency | `Yes` / `No` |

#### Capture Mode Explained

- **Automatic**: Funds are captured immediately when payment is authorized
- **Manual**: Funds are only authorized; you must capture them manually from the admin panel (useful for businesses that ship physical goods)

#### Webhook Settings

| Setting | Description |
|---------|-------------|
| **Webhook Endpoint** | Your shop's webhook URL (auto-generated) |
| **Webhook Secret** | Secret for verifying webhook signatures (`whsec_...`) |

---

## Stripe Dashboard Setup

### Step 1: Get API Keys

1. Log in to https://dashboard.stripe.com
2. Navigate to **Developers → API keys**
3. Copy your keys:
   - **Publishable key**: `pk_test_...` or `pk_live_...`
   - **Secret key**: `sk_test_...` or `sk_live_...`

### Step 2: Configure Webhook

1. Go to **Developers → Webhooks**
2. Click **Add endpoint**
3. Enter your webhook URL:
   ```
   https://your-shop.com/index.php?cl=StripeWebhookController
   ```
4. Select events to listen for:
   - `checkout.session.completed`
   - `checkout.session.expired`
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `charge.captured`
   - `charge.refunded`
   - `charge.refund.updated`

5. Click **Add endpoint**
6. Copy the **Signing secret** (`whsec_...`) to your module settings

### Step 3: Enable Payment Methods

1. Go to **Settings → Payment methods**
2. Enable desired payment methods:
   - Cards (Visa, Mastercard, Amex)
   - Apple Pay
   - Google Pay
   - Other regional methods

---

## Payment Methods

### Supported Payment Methods

| Method | Description | Availability |
|--------|-------------|--------------|
| **Digital Wallet** | Apple Pay, Google Pay | Global |
| **Card Payments** | Visa, Mastercard, Amex, etc. | Global |

### Currency Support

The module supports 24 currencies:
`AED, AUD, BGN, BRL, CAD, CHF, CZK, DKK, EUR, GBP, HKD, HRK, HUF, INR, JPY, MXN, MYR, NOK, NZD, PLN, RON, SEK, SGD, USD`

### Minimum/Maximum Amounts

- **Minimum**: €0.50 (or equivalent)
- **Maximum**: €999,999 (or equivalent)

---

## Order Management

### Order States

| State | Description |
|-------|-------------|
| **PENDING** | Payment initiated, awaiting completion |
| **OK** | Payment successful, order confirmed |
| **ERROR** | Payment failed or cancelled |

### Viewing Orders

1. Navigate to **Administer Orders → Orders**
2. Click on an order to view details
3. The **Stripe** tab shows payment information:
   - Payment Intent ID
   - Capture status
   - Refund history

### Manual Capture (if using Manual Capture Mode)

1. Go to **Administer Orders → Orders**
2. Select an order with authorized payment
3. Click the **Stripe** tab
4. Click **Capture Payment**
5. Enter amount (full or partial)
6. Confirm

---

## Refunds

### Issuing a Refund

1. Navigate to **Administer Orders → Orders**
2. Select the paid order
3. Click the **Stripe** tab (or **Refund** action)
4. Enter refund amount:
   - **Full refund**: Leave amount as total
   - **Partial refund**: Enter specific amount
5. Click **Refund**

### Refund Behavior

- Refunds are processed immediately via Stripe API
- Stock is automatically restored for refunded items
- Order status updates based on refund amount:
  - Partial refund: Order remains **OK**
  - Full refund: Order status changes to **CANCELLED**

### Refund Limitations

- Can only refund captured payments
- Cannot exceed captured amount
- Multiple partial refunds allowed up to total captured

---

## Troubleshooting

### Common Issues

#### Payment button not working

**Symptom:** Clicking "Submit Order" does nothing

**Solutions:**
1. Check browser console for JavaScript errors
2. Verify Stripe.js is loaded (check Network tab)
3. Confirm publishable key is configured
4. Clear browser cache and shop cache

#### Webhook not receiving events

**Symptom:** Orders stuck in PENDING state

**Solutions:**
1. Verify webhook URL is correct in Stripe Dashboard
2. Check webhook signing secret matches module setting
3. Test webhook from Stripe Dashboard → Webhooks → Send test event
4. Check server logs for 500 errors

#### "Stripe configuration error" message

**Symptom:** Error when trying to checkout

**Solutions:**
1. Verify API keys are correct (test/live mode matching)
2. Check that both secret and publishable keys are set
3. Ensure keys are for the correct Stripe account

#### Payment fails immediately

**Symptom:** Payment rejected without card input

**Solutions:**
1. Check Stripe Dashboard → Payments for decline reason
2. Verify account is fully activated
3. Check for blocked countries/currencies

### Logging

Enable transaction logging in module settings to debug issues:

**Log locations:**
- `var/log/osc/stripe_events.log` - Webhook events
- `var/log/osc/stripe_requests.log` - API requests
- `var/log/osc/stripe_reconciliation.log` - Payment reconciliation

### Test Mode

Always test in **test mode** before going live:

1. Use test API keys (`sk_test_...`, `pk_test_...`)
2. Use Stripe test cards:
   - Success: `4242 4242 4242 4242`
   - Decline: `4000 0000 0000 0002`
   - 3D Secure: `4000 0000 0000 3220`

---

## Going Live Checklist

Before switching to live mode:

- [ ] Module activated and configured
- [ ] Live API keys entered
- [ ] Webhook configured with live endpoint
- [ ] Webhook secret updated
- [ ] Test transaction completed in test mode
- [ ] SSL certificate valid (required for live payments)
- [ ] Stripe account fully verified
- [ ] Payment methods enabled in Stripe Dashboard

---

## Support

### Documentation
- OXID Documentation: https://docs.oxid-esales.com
- Stripe Documentation: https://stripe.com/docs

### Issues
- GitHub Issues: https://github.com/OXID-eSales/stripe-wallet/issues

### Contact
- OXID Support: support@oxid-esales.com
- Stripe Support: https://support.stripe.com

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2026-01 | Initial release with Digital Wallet support |

---

**License:** proprietary
**Copyright:** OXID eSales AG
