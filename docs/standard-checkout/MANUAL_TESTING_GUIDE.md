# Manual Testing Guide - Stripe Standard Checkout

Complete guide for manually testing the Stripe standard checkout implementation in OXID eShop.

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Initial Setup](#initial-setup)
3. [Test Scenarios](#test-scenarios)
4. [Webhook Testing](#webhook-testing)
5. [Verification Steps](#verification-steps)
6. [Troubleshooting](#troubleshooting)

---

## Prerequisites

### Required Accounts and Access

- ✅ OXID eShop 7.0+ installation (running and accessible)
- ✅ Stripe account (can be test mode)
- ✅ Access to OXID Admin Panel
- ✅ Access to Stripe Dashboard
- ✅ Database access (phpMyAdmin, MySQL CLI, etc.)
- ✅ Access to OXID logs (`/var/log/oxideshop.log`)

### Stripe Test Mode Setup

1. **Create Stripe Account** (if not already done)
   - Go to https://dashboard.stripe.com/register
   - Complete registration
   - Skip onboarding, go to "Developers" section

2. **Get API Keys**
   - Navigate to: Developers → API keys
   - Copy "Publishable key" (starts with `pk_test_`)
   - Click "Reveal test key" for "Secret key" (starts with `sk_test_`)
   - **IMPORTANT**: Never use live keys for testing!

3. **Get Webhook Secret** (for later)
   - Navigate to: Developers → Webhooks
   - Click "Add endpoint"
   - Enter URL: `https://your-shop.com/index.php?cl=stripe_webhook`
   - Select events or choose "Select all events"
   - Copy "Signing secret" (starts with `whsec_`)

---

## Initial Setup

### Step 1: Module Activation

```bash
# SSH into your OXID installation
cd /path/to/oxid/shop

# Activate the module
./vendor/bin/oe-console oe:module:activate osc_stripe_wallet

# Clear cache
./vendor/bin/oe-console oe:cache:clear
```

**Expected Result:**
- Module appears in Admin → Extensions → Modules
- Status shows "Active"
- No error messages in logs

**Verify Database Tables Created:**
```sql
SHOW TABLES LIKE 'osc_payment_%';
```

Should show:
- `osc_payment_transaction`
- `osc_payment_order_state`
- `osc_payment_customer`
- `osc_payment_webhook_log`

### Step 2: Configure API Keys

1. **Go to OXID Admin Panel**
   - Navigate to: Extensions → Modules → Stripe Payment Gateway

2. **Enter Test Mode Configuration**
   - Enable "Test Mode": ✅ YES
   - Test Publishable Key: `pk_test_xxxxx`
   - Test Secret Key: `sk_test_xxxxx`
   - Webhook Secret: `whsec_xxxxx` (if webhook endpoint created)

3. **Configure Payment Settings**
   - Capture Mode: `automatic` (for testing)
   - Minimum Order Amount: `0.50` EUR
   - Allowed Currencies: `EUR, USD, GBP`
   - Logging Enabled: ✅ YES
   - Log Level: `debug`

4. **Save Configuration**
   - Click "Save"
   - Clear cache again

### Step 3: Enable Payment Method

1. **Navigate to Shop Settings → Payment Methods**
2. **Find "Credit/Debit Card (Stripe)"** (`osc_stripe_card`)
3. **Check the following:**
   - Active: ✅ YES
   - Assigned to Countries: Select your test countries
   - Assigned to User Groups: Select "Customer"
   - Assigned to Shipping Methods: Select "Standard"
   - From/To Amount: 0 to 1000000

4. **Save Payment Method**

### Step 4: Create Test User (if needed)

1. **Create new customer account**
   - Email: `test@example.com`
   - Password: `test123`
   - First Name: `Test`
   - Last Name: `Customer`
   - Address: Complete valid address

2. **Verify user can login to frontend**

---

## Test Scenarios

### Scenario 1: Successful Payment (Basic Flow)

**Objective:** Test successful payment with no 3D Secure

**Steps:**

1. **Add product to basket**
   - Login as test user
   - Add any product to basket
   - Go to checkout

2. **Complete shipping address**
   - Fill in shipping address
   - Click "Continue"

3. **Select Stripe payment method**
   - Choose "Credit/Debit Card (Stripe)"
   - Click "Continue"

4. **Enter test card details**
   ```
   Card Number: 4242 4242 4242 4242
   Expiry: 12/34 (any future date)
   CVC: 123 (any 3 digits)
   Name: Test Customer
   ```

5. **Complete order**
   - Review order details
   - Click "Order Now" / "Buy Now"

**Expected Results:**
- ✅ Payment processes without errors
- ✅ Redirected to "Thank You" page
- ✅ Order number displayed
- ✅ Order appears in Admin → Orders
- ✅ Order status: "Not shipped"
- ✅ Payment status: "Paid" or similar

**Verification:**

```sql
-- Check transaction was stored
SELECT * FROM osc_payment_transaction
ORDER BY OXCREATED DESC LIMIT 1;

-- Check order payment state
SELECT * FROM osc_payment_order_state
ORDER BY OXCREATED DESC LIMIT 1;

-- Should show:
-- OXPAYMENTSTATE = 'paid'
-- OXCAPTURED = 1
```

**Check Logs:**
```bash
tail -n 50 /var/log/oxideshop.log | grep -i stripe
```

Look for:
- "Stripe PaymentIntent created"
- "Order created successfully"
- "Payment successful"

**Check Stripe Dashboard:**
- Go to: Payments → All payments
- Find payment with matching amount
- Status should be "Succeeded"
- Check metadata contains OXID user ID

---

### Scenario 2: Payment with 3D Secure Authentication

**Objective:** Test Strong Customer Authentication (SCA) flow

**Steps:**

1. Follow steps 1-3 from Scenario 1

2. **Enter 3DS test card**
   ```
   Card Number: 4000 0027 6000 3184
   Expiry: 12/34
   CVC: 123
   Name: Test 3DS
   ```

3. **Complete order**
   - Click "Order Now"

4. **3D Secure Modal Appears**
   - Modal window opens with authentication challenge
   - Click "Complete" or "Authenticate" button
   - (In test mode, this always succeeds)

5. **Return to shop**
   - Should automatically return after authentication

**Expected Results:**
- ✅ 3DS modal appears
- ✅ After authentication, payment completes
- ✅ Order created successfully
- ✅ Thank you page displayed

**Verification:**

```sql
-- Check 3DS was used
SELECT OX3DSECURE FROM osc_payment_transaction
ORDER BY OXCREATED DESC LIMIT 1;
-- Should be 1
```

---

### Scenario 3: Declined Card

**Objective:** Test error handling for declined payments

**Steps:**

1. Follow steps 1-3 from Scenario 1

2. **Enter declined card**
   ```
   Card Number: 4000 0000 0000 0002
   Expiry: 12/34
   CVC: 123
   Name: Test Decline
   ```

3. **Complete order**
   - Click "Order Now"

**Expected Results:**
- ❌ Payment fails with error message
- ✅ User stays on payment/order page
- ✅ Error message displayed: "Your card was declined"
- ✅ NO order created in database
- ✅ User can try again with different card

**Verification:**

```sql
-- No new order should be created
SELECT COUNT(*) FROM oxorder
WHERE OXUSERID = 'test_user_oxid'
AND OXORDERNR IS NOT NULL;
-- Count should not increase
```

**Check Logs:**
- Should see: "Payment failed" or "Card declined"

---

### Scenario 4: Insufficient Funds

**Objective:** Test insufficient funds error

**Steps:**

1. Follow steps 1-3 from Scenario 1

2. **Enter insufficient funds card**
   ```
   Card Number: 4000 0000 0000 9995
   Expiry: 12/34
   CVC: 123
   Name: Test Insufficient
   ```

3. **Complete order**

**Expected Results:**
- ❌ Payment fails
- ✅ Error: "Your card has insufficient funds"
- ✅ No order created
- ✅ User can retry

---

### Scenario 5: Expired Card

**Objective:** Test expired card handling

**Steps:**

1. Follow steps 1-3 from Scenario 1

2. **Enter expired card**
   ```
   Card Number: 4000 0000 0000 0069
   Expiry: 12/34
   CVC: 123
   Name: Test Expired
   ```

3. **Complete order**

**Expected Results:**
- ❌ Payment fails
- ✅ Error: "Your card has expired"
- ✅ No order created

---

### Scenario 6: Processing Payment (Edge Case)

**Objective:** Test payment that takes time to process

**Steps:**

1. Follow steps 1-3 from Scenario 1

2. **Enter processing card** (simulates bank processing delay)
   ```
   Card Number: 4000 0000 0000 9979
   Expiry: 12/34
   CVC: 123
   Name: Test Processing
   ```

3. **Complete order**

**Expected Results:**
- ⏳ Shows "Processing" message
- ✅ User instructed to wait or refresh
- ✅ Eventually completes (in test mode)

**Note:** In production, this would be resolved via webhook

---

### Scenario 7: Minimum Order Amount Validation

**Objective:** Test minimum amount enforcement

**Steps:**

1. **Add very cheap product** (e.g., 0.10 EUR)
2. Go to payment selection
3. Try to select Stripe payment

**Expected Results:**
- ❌ Cannot proceed or error shown
- ✅ Message: "Minimum order amount is 0.50 EUR"

**Check Logic in:**
`/src/Controller/PaymentController.php:79-89`

---

### Scenario 8: Refund Processing

**Objective:** Test refund functionality

**Prerequisites:** Complete Scenario 1 first (need existing paid order)

**Steps:**

1. **Get Payment Intent ID**
   ```sql
   SELECT OXPROVIDERORDERID FROM osc_payment_transaction
   WHERE OXORDERID = 'your_order_oxid';
   ```

2. **Create refund via service** (or admin interface if implemented)
   ```php
   // In OXID console or admin
   $paymentService = \OxidEsales\Eshop\Core\Registry::get(
       \OxidSolutionCatalysts\Stripe\Service\StripePaymentService::class
   );

   $refund = $paymentService->createRefund(
       'pi_xxxxx',  // PaymentIntent ID
       null,        // null = full refund
       'requested_by_customer'
   );
   ```

3. **Or refund via Stripe Dashboard:**
   - Go to Payment in Stripe Dashboard
   - Click "Refund payment"
   - Enter amount or full refund
   - Submit

**Expected Results:**
- ✅ Refund created successfully
- ✅ Webhook received (if configured)
- ✅ Order state updated to "refunded"

**Verification:**

```sql
-- Check refund state
SELECT OXREFUNDED, OXREFUNDEDAMOUNT FROM osc_payment_order_state
WHERE OXORDERID = 'your_order_oxid';
-- OXREFUNDED should be 1
```

---

## Webhook Testing

### Setup Local Webhook Testing

**Option 1: Using Stripe CLI (Recommended for local development)**

1. **Install Stripe CLI**
   ```bash
   # Linux/Mac
   brew install stripe/stripe-cli/stripe

   # Or download from: https://stripe.com/docs/stripe-cli
   ```

2. **Login to Stripe**
   ```bash
   stripe login
   ```

3. **Forward webhooks to local**
   ```bash
   stripe listen --forward-to http://localhost/index.php?cl=stripe_webhook
   ```

4. **Copy webhook signing secret**
   - CLI will display: `whsec_xxxxx`
   - Add this to module configuration

5. **Trigger test events**
   ```bash
   # Test payment success
   stripe trigger payment_intent.succeeded

   # Test refund
   stripe trigger charge.refunded
   ```

**Option 2: Using ngrok (for remote testing)**

1. **Install ngrok**
   ```bash
   # Download from https://ngrok.com/download
   ```

2. **Expose local server**
   ```bash
   ngrok http 80
   ```

3. **Copy ngrok URL** (e.g., `https://abc123.ngrok.io`)

4. **Add webhook endpoint in Stripe Dashboard**
   - URL: `https://abc123.ngrok.io/index.php?cl=stripe_webhook`
   - Events: Select all or specific events
   - Copy signing secret

5. **Test by making real payment**

### Webhook Test Scenarios

**Test 1: Payment Intent Succeeded**

```bash
stripe trigger payment_intent.succeeded
```

**Expected:**
- ✅ Webhook logged in `osc_payment_webhook_log`
- ✅ Event type: `payment_intent.succeeded`
- ✅ Status: `processed`

**Test 2: Charge Refunded**

```bash
stripe trigger charge.refunded
```

**Expected:**
- ✅ Webhook received and logged
- ✅ Order refund state updated

**Test 3: Invalid Signature**

```bash
# Send webhook with wrong signature
curl -X POST http://localhost/index.php?cl=stripe_webhook \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: invalid_signature" \
  -d '{"type": "test"}'
```

**Expected:**
- ❌ HTTP 400 Bad Request
- ✅ Error logged: "Invalid signature"
- ✅ Webhook NOT processed

**Verification Queries:**

```sql
-- Check webhook logs
SELECT * FROM osc_payment_webhook_log
ORDER BY OXCREATED DESC;

-- Should show:
-- OXEVENTID, OXEVENTTYPE, OXSTATUS, OXCREATED
```

---

## Verification Steps

### After Each Test Scenario

#### 1. Check OXID Order Table

```sql
SELECT
    OXORDERNR,
    OXUSERID,
    OXTOTALORDERSUM,
    OXPAYMENTTYPE,
    OXPAID,
    OXORDERDATE
FROM oxorder
WHERE OXPAYMENTTYPE = 'osc_stripe_card'
ORDER BY OXORDERDATE DESC
LIMIT 5;
```

#### 2. Check Transaction Records

```sql
SELECT
    t.OXORDERID,
    t.OXPROVIDERORDERID,
    t.OXAMOUNT,
    t.OXSTATUS,
    t.OXCARDLAST4,
    t.OX3DSECURE,
    t.OXCREATED
FROM osc_payment_transaction t
ORDER BY t.OXCREATED DESC
LIMIT 5;
```

#### 3. Check Payment State

```sql
SELECT
    s.OXORDERID,
    s.OXPAYMENTSTATE,
    s.OXCAPTURED,
    s.OXCAPTUREDAMOUNT,
    s.OXREFUNDED,
    s.OXREFUNDEDAMOUNT
FROM osc_payment_order_state s
ORDER BY s.OXCREATED DESC
LIMIT 5;
```

#### 4. Check Stripe Customer Mapping

```sql
SELECT
    OXUSERID,
    OXSTRIPECUSTOMERID,
    OXCREATED
FROM osc_payment_customer
ORDER BY OXCREATED DESC;
```

#### 5. Check Application Logs

```bash
# View recent Stripe-related logs
tail -n 100 /var/log/oxideshop.log | grep -i stripe

# Look for:
# - "PaymentIntent created"
# - "Order created successfully"
# - "Webhook received"
# - Any ERROR or WARNING messages
```

#### 6. Check Stripe Dashboard

1. **Go to Stripe Dashboard → Payments**
2. **Verify payment appears with:**
   - Correct amount
   - Status: Succeeded (for successful tests)
   - Metadata contains OXID user ID
   - Customer email matches

3. **Go to Customers**
   - Verify customer created
   - Name and email correct
   - Linked to payment

4. **Go to Logs (Developers → Logs)**
   - Check API calls were successful
   - No 400/500 errors

---

## Troubleshooting

### Issue: Payment Method Not Appearing

**Symptoms:** Stripe option not shown on payment page

**Checks:**
1. Module activated? `oe:module:activate osc_stripe_wallet`
2. Payment method active in Admin?
3. Country restrictions correct?
4. User group assigned?
5. Cache cleared? `oe:cache:clear`

**Fix:**
```bash
# Reactivate module
./vendor/bin/oe-console oe:module:deactivate osc_stripe_wallet
./vendor/bin/oe-console oe:module:activate osc_stripe_wallet
./vendor/bin/oe-console oe:cache:clear
```

### Issue: "API Key Invalid" Error

**Symptoms:** Error message about invalid API key

**Checks:**
1. Test mode enabled?
2. Using test keys (start with `pk_test_` and `sk_test_`)?
3. Keys copied correctly (no spaces)?
4. Keys belong to same Stripe account?

**Fix:**
- Re-copy keys from Stripe Dashboard
- Verify test mode is ON
- Clear configuration cache

### Issue: Webhook Not Receiving Events

**Symptoms:** Webhooks not logged in database

**Checks:**
1. Webhook endpoint URL correct?
2. HTTPS enabled (required for production)?
3. Webhook signing secret configured?
4. Firewall blocking requests?

**Debug:**
```bash
# Test webhook endpoint manually
curl -v https://your-shop.com/index.php?cl=stripe_webhook

# Should return 400 (missing signature) but endpoint is reachable
```

**Check Webhook Logs in Stripe:**
- Dashboard → Developers → Webhooks → Click endpoint
- View "Recent deliveries"
- Check response codes

### Issue: Order Created But No Transaction Record

**Symptoms:** Order exists, but `osc_payment_transaction` is empty

**Possible Causes:**
1. Database error during transaction insert
2. Exception thrown in `storeTransaction()` method
3. Transaction rollback

**Debug:**
```sql
-- Enable MySQL query log
SET GLOBAL general_log = 'ON';

-- Make test payment

-- Check log
SHOW VARIABLES LIKE 'general_log_file';
-- View the file path and check for failed INSERTs
```

**Check Code:** `/src/Service/StripePaymentService.php:201-236`

### Issue: 3D Secure Modal Not Appearing

**Symptoms:** Card requiring 3DS doesn't show authentication modal

**Checks:**
1. Using 3DS test card? (e.g., `4000 0027 6000 3184`)
2. JavaScript errors in browser console?
3. Stripe.js loaded correctly?
4. Content Security Policy blocking?

**Debug:**
```javascript
// Open browser console (F12)
// Look for errors like:
// "Stripe is not defined"
// "Failed to load Stripe.js"
```

### Issue: Payment Succeeds but Order Not Created

**Symptoms:** Payment in Stripe, but no order in OXID

**Most Likely Cause:** `finalizeOrder()` returned error state

**Debug:**
```bash
# Check logs for error state
grep "Order creation failed" /var/log/oxideshop.log

# Common states:
# - Order::ORDER_STATE_PAYMENTERROR
# - Order::ORDER_STATE_ORDEREXISTS
```

**Check:** `/src/Service/StripePaymentService.php:174-177`

### Issue: Refund Not Updating Order State

**Symptoms:** Refund successful in Stripe, order state unchanged

**Checks:**
1. Webhook received? Check `osc_payment_webhook_log`
2. Webhook processed? Status should be 'processed'
3. Order found? Check `OXPROVIDERORDERID` mapping

**Debug:**
```sql
-- Check if webhook was received
SELECT * FROM osc_payment_webhook_log
WHERE OXEVENTTYPE = 'charge.refunded'
ORDER BY OXCREATED DESC LIMIT 1;

-- Check processing errors
SELECT OXERRORMESSAGE FROM osc_payment_webhook_log
WHERE OXSTATUS = 'failed';
```

---

## Test Card Reference

### Successful Payments

| Card Number | Description | 3D Secure |
|-------------|-------------|-----------|
| 4242 4242 4242 4242 | Visa - Success | No |
| 4000 0027 6000 3184 | Visa - Requires 3DS | Yes |
| 5555 5555 5555 4444 | Mastercard - Success | No |
| 3782 822463 10005 | American Express - Success | No |

### Declined Payments

| Card Number | Error |
|-------------|-------|
| 4000 0000 0000 0002 | Generic decline |
| 4000 0000 0000 9995 | Insufficient funds |
| 4000 0000 0000 9987 | Lost card |
| 4000 0000 0000 9979 | Stolen card |
| 4000 0000 0000 0069 | Expired card |
| 4000 0000 0000 0127 | Incorrect CVC |
| 4000 0000 0000 0119 | Processing error |

### Special Scenarios

| Card Number | Scenario |
|-------------|----------|
| 4000 0000 0000 9979 | Payment processing (takes time) |
| 4000 0025 0000 3155 | 3DS required, authentication fails |

**More test cards:** https://stripe.com/docs/testing

---

## Testing Checklist

Use this checklist to ensure comprehensive testing:

### Basic Functionality
- [ ] Module activates without errors
- [ ] Configuration saves correctly
- [ ] Payment method appears in frontend
- [ ] Successful payment creates order
- [ ] Order has correct amount and details
- [ ] Transaction record stored in database
- [ ] Customer mapping created

### Payment Flows
- [ ] Basic card payment (no 3DS)
- [ ] Payment with 3D Secure authentication
- [ ] Declined card shows error
- [ ] Insufficient funds handled
- [ ] Expired card rejected
- [ ] Order uses `finalizeOrder()` method

### Error Handling
- [ ] Invalid card number rejected
- [ ] Network errors handled gracefully
- [ ] Duplicate order prevention works
- [ ] User can retry failed payment
- [ ] Error messages are user-friendly

### Webhooks
- [ ] Webhook endpoint accessible
- [ ] Signature verification works
- [ ] Invalid signatures rejected
- [ ] Payment success webhook processed
- [ ] Refund webhook processed
- [ ] Webhook events logged

### Admin/Backend
- [ ] Orders visible in admin
- [ ] Payment details shown correctly
- [ ] Refund can be initiated
- [ ] Transaction logs viewable
- [ ] Configuration validated

### Edge Cases
- [ ] Minimum order amount enforced
- [ ] Multiple currencies supported
- [ ] Guest checkout works (if enabled)
- [ ] Basket changes during payment handled
- [ ] Session timeout handled

---

## Next Steps After Testing

1. **Review Test Results**
   - Document any failures or unexpected behavior
   - Check logs for all WARNING/ERROR messages

2. **Configure Production Keys**
   - Switch to live mode only after thorough testing
   - Use live API keys: `pk_live_` and `sk_live_`
   - Set up production webhook endpoint

3. **Security Checklist**
   - [ ] HTTPS enabled (required for live mode)
   - [ ] Webhook signature verification active
   - [ ] API keys stored securely (not in version control)
   - [ ] Error messages don't expose sensitive data
   - [ ] Logging doesn't include full card numbers

4. **Monitoring Setup**
   - Set up alerts for failed payments
   - Monitor webhook failures
   - Track transaction success rate
   - Review logs regularly

5. **Documentation**
   - Document custom configurations
   - Create runbook for common issues
   - Train support team on Stripe payments

---

## Support Resources

- **OXID Documentation:** https://docs.oxid-esales.com
- **Stripe API Docs:** https://stripe.com/docs/api
- **Stripe Test Cards:** https://stripe.com/docs/testing
- **Stripe CLI:** https://stripe.com/docs/stripe-cli
- **Module Issues:** Check implementation logs and database

For issues specific to this implementation, check:
- `/var/log/oxideshop.log` - Application logs
- MySQL error logs - Database issues
- Browser console - Frontend JavaScript errors
- Stripe Dashboard Logs - API call issues
