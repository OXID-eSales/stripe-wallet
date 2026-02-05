# API Key Duplication Analysis

**Date:** 2026-02-05
**Sprint:** 37
**Status:** Complete

---

## Executive Summary

The module admin configuration contains **6 API key fields**, but only **4 are actually used**. Two fields (`sStripeTestKey`, `sStripeLiveKey`) are **DEAD CODE** that should be removed.

---

## Current API Key Fields in `metadata.php`

| Field Name | Position | Description | Used? |
|------------|----------|-------------|-------|
| `sStripeTestToken` | 20 | Test API Access Token (Secret Key) | **YES** |
| `sStripeTestPk` | 21 | Test API Publishable Key | **YES** |
| `sStripeLiveToken` | 30 | Live API Access Token (Secret Key) | **YES** |
| `sStripeLivePk` | 31 | Live API Publishable Key | **YES** |
| `sStripeTestKey` | 32 | Test API Private Key | **NO - DEAD CODE** |
| `sStripeLiveKey` | 33 | Live API Private Key | **NO - DEAD CODE** |

---

## Stripe Key Types Explained

Stripe uses **two types of API keys**:

### 1. Secret Key (`sk_test_...` / `sk_live_...`)
- Used for server-side API calls
- Must be kept confidential
- Required for: charges, refunds, webhooks, all backend operations

### 2. Publishable Key (`pk_test_...` / `pk_live_...`)
- Used for client-side JavaScript (Stripe.js, Elements)
- Safe to expose in frontend code
- Required for: tokenizing cards, creating payment intents on frontend

There is **NO THIRD KEY TYPE** called "Private Key" - this appears to be a naming confusion.

---

## How Keys Are Used in Code

### `ModuleConfigurationService.php`

```php
// Used keys:
public function getPublishableKey(): string {
    return $this->isTestMode()
        ? $this->get('sStripeTestPk')      // ✓ USED
        : $this->get('sStripeLivePk');     // ✓ USED
}

public function getSecretKey(): string {
    return $this->isTestMode()
        ? $this->get('sStripeTestToken')   // ✓ USED
        : $this->get('sStripeLiveToken');  // ✓ USED
}

public function getToken(): string {
    // Same as getSecretKey()
    return $this->isTestMode()
        ? $this->get('sStripeTestToken')   // ✓ USED
        : $this->get('sStripeLiveToken');  // ✓ USED
}

// sStripeTestKey - NEVER REFERENCED
// sStripeLiveKey - NEVER REFERENCED
```

### Grep Verification

```bash
$ grep -r "sStripeTestKey\|sStripeLiveKey" src/
# No matches found!
```

---

## Stripe Connect Flow

### How It Works

1. Admin clicks "Connect with Stripe" button in module config
2. Browser redirects to OXID Service Marketplace (osm.oxid-esales.com)
3. User completes Stripe OAuth onboarding
4. Stripe redirects back to OXID shop with tokens
5. `StripeConnect::stripeFinishOnBoarding()` receives and saves tokens

### Code in `StripeConnect.php`

```php
public function stripeFinishOnBoarding()
{
    $sAccessToken = Registry::getRequest()->getRequestEscapedParameter('access_token');
    $sPublishableKey = Registry::getRequest()->getRequestEscapedParameter('publishable_key');
    $sMode = Registry::getRequest()->getRequestEscapedParameter('shop_param');

    if ($sMode == 'live') {
        $this->moduleSettingService->save('sStripeLiveToken', $sAccessToken, Module::MODULE_ID);
        $this->moduleSettingService->save('sStripeLivePk', $sPublishableKey, Module::MODULE_ID);
    } else {
        $this->moduleSettingService->save('sStripeTestToken', $sAccessToken, Module::MODULE_ID);
        $this->moduleSettingService->save('sStripeTestPk', $sPublishableKey, Module::MODULE_ID);
    }
}
```

**Note:** Only `Token` and `Pk` fields are populated by Stripe Connect. The `Key` fields are never touched.

---

## UI Template Analysis (`module_config.html.twig`)

### Token Fields (lines 7-24)
- Displayed as **read-only** text inputs
- Has "Connect with Stripe" button
- Shows "Connection successful" status

### Publishable Key Fields (lines 25-36)
- Displayed as **editable** text inputs
- "editable to allow manual correction of mismatched keys"

### Key Fields (lines 77-89) - **DEAD CODE**
- Displayed as **editable** text inputs
- Help text: "used to set up the webhook endpoint"
- **But webhooks use `sStripeWebhookEndpointSecret`, NOT these fields!**

---

## Naming Confusion

| Current Name | Actual Purpose | Better Name |
|--------------|----------------|-------------|
| `sStripeTestToken` | Test Secret Key | `sStripeTestSecretKey` |
| `sStripeLiveToken` | Live Secret Key | `sStripeLiveSecretKey` |
| `sStripeTestPk` | Test Publishable Key | `sStripeTestPublishableKey` |
| `sStripeLivePk` | Live Publishable Key | `sStripeLivePublishableKey` |
| `sStripeTestKey` | **UNUSED** | **DELETE** |
| `sStripeLiveKey` | **UNUSED** | **DELETE** |

---

## Recommendation: Remove Dead Code

### Files to Modify

1. **`metadata.php`** - Remove lines 81-82:
   ```php
   // DELETE:
   ['group' => 'STRIPE_GENERAL', 'name' => 'sStripeTestKey', 'type' => 'str', 'value' => '', 'position' => 32],
   ['group' => 'STRIPE_GENERAL', 'name' => 'sStripeLiveKey', 'type' => 'str', 'value' => '', 'position' => 33],
   ```

2. **`module_config.html.twig`** - Remove lines 77-89:
   ```twig
   // DELETE:
   {% elseif module_var == 'sStripeTestKey' or module_var == 'sStripeLiveKey' %}
       ...
   ```

3. **`views/admin_twig/en/stripe_lang.php`** - Remove translations:
   ```php
   // DELETE:
   'SHOP_MODULE_sStripeTestKey' => ...
   'SHOP_MODULE_sStripeLiveKey' => ...
   'HELP_SHOP_MODULE_sStripeTestKey' => ...
   'HELP_SHOP_MODULE_sStripeLiveKey' => ...
   ```

4. **`views/admin_twig/de/stripe_lang.php`** - Same deletions

5. **`recipe/.../oe_payments_stripe_wallet.yaml`** - Remove settings

6. **`tests/Integration/Module/MetadataTest.php`** - Remove assertions

---

## Impact Assessment

| Category | Impact |
|----------|--------|
| Functionality | None - fields are not used |
| Breaking Change | None - no code depends on these fields |
| Admin UI | Cleaner configuration page |
| User Confusion | Reduced - no more redundant "Private Key" fields |

---

## Questions for Clarification

1. Was there ever functionality planned for `sStripeTestKey`/`sStripeLiveKey`?
2. The help text mentions "webhook endpoint" - was this meant for Stripe Connect restricted keys?
3. Should we rename `Token` → `SecretKey` for clarity? (This would be a breaking change for existing configs)

---

## Next Steps

1. **Confirm removal** with team lead
2. **Create migration** to clean up existing shop configs (optional)
3. **Update documentation** to reflect correct key naming
4. Consider renaming remaining fields for clarity (future sprint)
