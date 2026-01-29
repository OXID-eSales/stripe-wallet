# Quick Start Guide

**Get Stripe Payments Running in 30 Minutes**
**Version:** 1.0.0
**Date:** 2025-11-13

---

## Overview

This quick start guide will get you from zero to accepting Stripe payments in 30 minutes. Perfect for developers who want to see it working quickly before diving into the detailed documentation.

---

## Prerequisites Checklist

- [ ] OXID eShop 7.0+ installed and running
- [ ] Composer installed
- [ ] Stripe account created ([stripe.com/register](https://stripe.com/register))
- [ ] Test API keys from Stripe Dashboard
- [ ] HTTPS enabled (required for production, optional for testing)

---

## Step 1: Get Stripe API Keys (2 minutes)

1. Login to [Stripe Dashboard](https://dashboard.stripe.com)
2. Click **Developers** → **API Keys**
3. Copy your keys:
   - **Publishable key** (starts with `pk_test_`)
   - **Secret key** (starts with `sk_test_`)

**Keep these keys safe - you'll need them in Step 4!**

---

## Step 2: Install Module (5 minutes)

### Option A: Via Composer (Recommended)

```bash
cd /path/to/oxid/source/modules
composer require osc/stripe
```

### Option B: Manual Installation

1. Download module files
2. Extract to `source/modules/osc/stripe/`
3. Install Stripe SDK:
   ```bash
   cd source/modules/osc/stripe
   composer require stripe/stripe-php
   ```

---

## Step 3: Create Database Tables (3 minutes)

Run the migration SQL:

```bash
mysql -u [username] -p [database_name] < source/modules/osc/stripe/migrations/001_create_payment_tables.sql
```

Or copy and paste the SQL from `migrations/001_create_payment_tables.sql` into your MySQL client.

**Verify tables created:**
```sql
SHOW TABLES LIKE 'osc_payment%';
```

You should see 4 tables:
- oe_payments_transaction
- oe_payments_order_state
- oe_payments_customer
- oe_payments_webhook_log

---

## Step 4: Configure Module (5 minutes)

### In OXID Admin

1. Go to **Extensions** → **Modules**
2. Find **Stripe Payment Integration**
3. Click **Activate**
4. Go to **Settings** tab
5. Configure:

```
Mode: Test
Test Public Key: pk_test_xxxxxxxxxxxxxxxx
Test Secret Key: sk_test_xxxxxxxxxxxxxxxx
Webhook Secret: (leave empty for now)
Capture Mode: Automatic
3D Secure: Enabled
```

6. Click **Save**

### Verify Configuration

Check that payment method was created:
1. Go to **Shop Settings** → **Payment Methods**
2. Look for **Credit Card (Stripe)**
3. Should be active and available

---

## Step 5: Test Payment (10 minutes)

### Frontend Test

1. **Add product to basket:**
   - Go to shop frontend
   - Add any product to cart
   - Click "Checkout"

2. **Login/Register:**
   - Login or create test account

3. **Select Stripe Payment:**
   - On payment page, select "Credit Card (Stripe)"
   - You should see Stripe card input form

4. **Enter test card:**
   ```
   Card: 4242 4242 4242 4242
   Expiry: 12/34 (any future date)
   CVC: 123 (any 3 digits)
   ```

5. **Complete order:**
   - Click "Continue to Review"
   - Click "Place Order"
   - Should redirect to thank you page

6. **Verify order created:**
   - Check OXID admin → Orders
   - Should see new order with status "OK"

### Verify in Stripe Dashboard

1. Go to [Stripe Dashboard](https://dashboard.stripe.com/test/payments)
2. Should see payment for your test order
3. Status: "Succeeded"

**🎉 Congratulations! You just processed your first payment!**

---

## Step 6: Test 3D Secure (5 minutes)

Test Strong Customer Authentication (required in EU):

1. Start new checkout
2. Use 3DS test card:
   ```
   Card: 4000 0027 6000 3184
   Expiry: 12/34
   CVC: 123
   ```
3. You'll be redirected to 3D Secure page
4. Click "Complete authentication"
5. Should return to shop and complete order

**Result:** Order created with 3DS authentication ✅

---

## Step 7: Test Failed Payment (2 minutes)

Test error handling:

1. Start new checkout
2. Use declined card:
   ```
   Card: 4000 0000 0000 0002
   Expiry: 12/34
   CVC: 123
   ```
3. Should see error: "Your card was declined"
4. Order NOT created ✅

---

## Common Issues & Quick Fixes

### Issue: "Payment method not available"

**Solution:** Check module configuration
```bash
# Verify API keys are set
php -r "require 'bootstrap.php'; var_dump(Registry::getConfig()->getConfigParam('stripeTestPublicKey'));"
```

### Issue: "Stripe is not defined" JavaScript error

**Solution:** Check Stripe.js loaded
- Open browser console (F12)
- Check for `https://js.stripe.com/v3/` in Network tab
- Verify no ad-blocker blocking Stripe

### Issue: Card element not displayed

**Solution:** Check template integration
- Verify `payment_stripe.tpl` exists
- Check browser console for JavaScript errors
- Clear OXID template cache:
  ```bash
  rm -rf source/tmp/*
  ```

### Issue: Order not created after payment

**Solution:** Check PHP error log
```bash
tail -f /var/log/php/error.log
```

Common causes:
- Missing database tables
- API key mismatch
- Session expired

---

## Test Cards Reference

### Success Cards

```
# Standard success (no 3DS)
4242 4242 4242 4242

# Success with 3DS required
4000 0027 6000 3184

# Success (Visa Debit)
4000 0566 5566 5556
```

### Declined Cards

```
# Generic decline
4000 0000 0000 0002

# Insufficient funds
4000 0000 0000 9995

# Lost card
4000 0000 0000 9987

# Stolen card
4000 0000 0000 9979
```

### More test cards: [stripe.com/docs/testing](https://stripe.com/docs/testing)

---

## Quick Configuration Checklist

- [ ] Stripe SDK installed
- [ ] Database tables created
- [ ] Module activated in OXID admin
- [ ] API keys configured
- [ ] Payment method "Credit Card (Stripe)" active
- [ ] Test payment successful
- [ ] 3D Secure tested
- [ ] Failed payment tested

---

## Next Steps

### For Development

1. **Setup Webhooks:**
   - Read [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md)
   - Install Stripe CLI for local testing
   - Configure webhook endpoint

2. **Customize Templates:**
   - Read [TEMPLATE_GUIDE.md](TEMPLATE_GUIDE.md)
   - Customize payment form styling
   - Add your branding

3. **Implement Error Handling:**
   - Read [ERROR_HANDLING.md](ERROR_HANDLING.md)
   - Handle edge cases
   - Improve user feedback

### For Production

1. **Get Live API Keys:**
   - Complete Stripe account verification
   - Activate your account
   - Get live keys (pk_live_, sk_live_)

2. **Switch to Live Mode:**
   ```
   Mode: Live
   Live Public Key: pk_live_xxxxxxxxxxxxxxxx
   Live Secret Key: sk_live_xxxxxxxxxxxxxxxx
   ```

3. **Configure Webhooks:**
   - Add production webhook endpoint
   - Copy webhook secret
   - Update module configuration

4. **Security Checklist:**
   - [ ] HTTPS enabled
   - [ ] SSL certificate valid
   - [ ] Webhook secret configured
   - [ ] API keys not in version control
   - [ ] Error logging enabled

5. **Test Production:**
   - Process real $1.00 transaction
   - Verify order created
   - Verify webhook received
   - Refund the test transaction

---

## Getting Help

### Documentation

- **Full Implementation:** [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)
- **Controllers:** [CONTROLLER_INTEGRATION.md](CONTROLLER_INTEGRATION.md)
- **Services:** [SERVICE_LAYER.md](SERVICE_LAYER.md)
- **Templates:** [TEMPLATE_GUIDE.md](TEMPLATE_GUIDE.md)
- **Webhooks:** [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md)

### Support Resources

- **Stripe Docs:** [stripe.com/docs](https://stripe.com/docs)
- **OXID Docs:** [docs.oxid-esales.com](https://docs.oxid-esales.com)
- **Stripe Support:** [support.stripe.com](https://support.stripe.com)

### Community

- **OXID Forum:** [forum.oxid-esales.com](https://forum.oxid-esales.com)
- **GitHub Issues:** Report bugs and feature requests

---

## Troubleshooting

### Enable Debug Logging

Add to `config.inc.php`:

```php
$this->iDebug = 6; // Enable all logging
```

View logs:
```bash
tail -f source/log/oxideshop.log
```

### Clear All Caches

```bash
# Clear OXID caches
rm -rf source/tmp/*

# Clear browser cache
# Press Ctrl+Shift+Delete in browser
```

### Reset Module

If something goes wrong:

1. **Deactivate module:**
   - OXID Admin → Extensions → Modules
   - Click "Deactivate"

2. **Clear cache:**
   ```bash
   rm -rf source/tmp/*
   ```

3. **Reactivate module:**
   - Click "Activate"

4. **Reconfigure settings**

---

## Success Checklist

Your Stripe integration is ready when:

- [✅] Test payment successful
- [✅] Order created in OXID
- [✅] Payment visible in Stripe Dashboard
- [✅] 3D Secure authentication works
- [✅] Failed payments handled gracefully
- [✅] Webhooks configured (production)
- [✅] HTTPS enabled (production)
- [✅] Live mode tested with real transaction

---

**🚀 You're ready to accept payments!**

For production deployment, read the [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md) for complete details on all features, security, and best practices.

