# API Key Mismatch Root Cause Analysis

**Date:** 2026-02-05
**Issue:** Publishable Key and Secret Key from different Stripe accounts

---

## The Problem

Keys from **different Stripe accounts**:
- Publishable Key: `pk_test_51NXKT4E...` → Account: **51NXKT4E**
- Secret Key: `sk_test_51OyDwdA...` → Account: **51OyDwdA**

This causes checkout to fail because:
1. Frontend creates Payment Intent with Publishable Key (Account A)
2. Backend tries to retrieve it with Secret Key (Account B)
3. Stripe returns "No such payment_intent" error

---

## Root Cause

### The OAuth Flow

```
┌─────────────────┐    1. Click "Connect with Stripe"
│   Admin Page    │ ─────────────────────────────────────┐
│   (OXID Shop)   │                                      │
└────────┬────────┘                                      ▼
         │                                    ┌──────────────────────┐
         │                                    │ osm.oxid-esales.com  │
         │                                    │ (OAuth Intermediary) │
         │                                    └──────────┬───────────┘
         │                                               │
         │                                               ▼
         │                                    ┌──────────────────────┐
         │                                    │   Stripe Dashboard   │
         │                                    │   (User logs in)     │
         │                                    └──────────┬───────────┘
         │                                               │
         │    4. Redirect back with access_token         │
         │       + publishable_key                       │
         ◄───────────────────────────────────────────────┘
         │
         ▼
┌────────────────────────────────────────────────────────┐
│  StripeConnect::stripeFinishOnBoarding()               │
│                                                        │
│  $sAccessToken = getParam('access_token');             │
│  $sPublishableKey = getParam('publishable_key');       │
│                                                        │
│  // NO VALIDATION! Just saves both                     │
│  save('sStripeTestToken', $sAccessToken);              │
│  save('sStripeTestPk', $sPublishableKey);              │
└────────────────────────────────────────────────────────┘
```

### How Mismatch Happens

**Scenario 1: Multiple OAuth Flows**
1. Admin clicks "Connect with Stripe" for Test Token → Account A secret key saved
2. Later, admin clicks "Connect with Stripe" again → Account B keys returned
3. If intermediary bug: only secret key updated, publishable key stays from Account A

**Scenario 2: Intermediary Bug**
- The OAuth intermediary (`osm.oxid-esales.com`) might:
  - Not return publishable_key at all (saved as empty string)
  - Return cached publishable_key from different session/account
  - Mix up keys between different OAuth flows

**Scenario 3: Manual Edit**
- Admin manually edited the publishable key field (it's editable for this reason)
- But didn't update it to match the secret key account

---

## Current Code Analysis

### StripeConnect.php (lines 39-65)

```php
public function stripeFinishOnBoarding()
{
    $sAccessToken = Registry::getRequest()->getRequestEscapedParameter('access_token');
    $sPublishableKey = Registry::getRequest()->getRequestEscapedParameter('publishable_key');
    $sMode = Registry::getRequest()->getRequestEscapedParameter('shop_param');

    // ⚠️ NO VALIDATION that keys are from same account!
    if ($sMode == 'live') {
        $this->moduleSettingService->save('sStripeLiveToken', $sAccessToken, Module::MODULE_ID);
        $this->moduleSettingService->save('sStripeLivePk', $sPublishableKey, Module::MODULE_ID);
    } else {
        $this->moduleSettingService->save('sStripeTestToken', $sAccessToken, Module::MODULE_ID);
        $this->moduleSettingService->save('sStripeTestPk', $sPublishableKey, Module::MODULE_ID);
    }
}
```

**Problems:**
1. No validation that `access_token` and `publishable_key` are from same account
2. Empty `publishable_key` is silently saved
3. No warning shown to admin

### Validation EXISTS but not used in Admin UI

`ModuleConfigurationService.php` has these methods:

```php
public function validateKeyPair(): bool
{
    $pkAccountId = $this->extractAccountId($this->getPublishableKey());
    $skAccountId = $this->extractAccountId($this->getToken());
    return $pkAccountId === $skAccountId;
}

public function getKeyValidationError(): ?string
{
    // Returns detailed error message if keys mismatch
}
```

**These are only used in `StripeOrderController::createCheckoutSession()`** (at checkout time), not in:
- Admin module configuration page
- StripeConnect onboarding callback

---

## Why the Template Has Editable Publishable Key

```twig
{# Publishable key fields - editable to allow manual correction of mismatched keys #}
```

The developers **knew this issue exists** and made publishable key editable so admins can manually fix mismatches. But this is a workaround, not a solution.

---

## Recommended Fixes

### Fix 1: Validate in StripeConnect (Immediate)

```php
public function stripeFinishOnBoarding()
{
    $sAccessToken = Registry::getRequest()->getRequestEscapedParameter('access_token');
    $sPublishableKey = Registry::getRequest()->getRequestEscapedParameter('publishable_key');

    // Validate keys are from same account
    $accessAccountId = $this->extractAccountId($sAccessToken);
    $publishableAccountId = $this->extractAccountId($sPublishableKey);

    if ($accessAccountId !== $publishableAccountId) {
        // Log warning, show error to admin
        $this->addWarning('API keys appear to be from different accounts');
    }

    // ... save keys
}
```

### Fix 2: Show Warning in Admin Config Page

Add to `ModuleConfiguration.php`:

```php
public function stripeGetKeyValidationError(): ?string
{
    return $this->getModuleConfig()->getKeyValidationError();
}
```

Add to `module_config.html.twig`:

```twig
{% set keyError = oView.stripeGetKeyValidationError() %}
{% if keyError %}
    <div class="alert alert-danger">
        ⚠️ {{ keyError }}
    </div>
{% endif %}
```

### Fix 3: Fetch Publishable Key from Stripe API

Instead of relying on OAuth callback, fetch publishable key using the secret key:

```php
// After getting access_token, verify and get publishable key
$stripe = new \Stripe\StripeClient($sAccessToken);
$account = $stripe->accounts->retrieve('acct_xxxxx');
// Use account info to derive/validate publishable key
```

---

## Immediate Action

**For current mismatch, the admin should:**

1. Go to Stripe Dashboard → https://dashboard.stripe.com/test/apikeys
2. Copy the correct Publishable Key for the same account as the Secret Key
3. Paste it in OXID Admin → Extensions → Modules → Stripe → Settings → Test API Publishable Key
4. Save

**Account ID in Secret Key:** `51OyDwdA...`
**Expected Publishable Key prefix:** `pk_test_51OyDwdA...`

---

## Test Results

The `ModuleConfigurationService::validateKeyPair()` method correctly detects this mismatch:

```php
$config->validateKeyPair(); // Returns false
$config->getKeyValidationError(); // Returns detailed error message
```

But this validation is only called at checkout time, not in admin UI.
