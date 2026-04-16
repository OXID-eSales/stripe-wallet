# Sprint 91: Security Hardening — Stripe.js SRI + Missing Translations

**Date:** 2026-04-16
**Branch:** `b-7.4.x`
**Ticket:** STRP-126

## Core Requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Write tests verifying translation keys exist before adding them |
| **DevOps-first** | Pre-commit validates all changes |
| **SOLID / SRP** | Translations fix = lang files only. SRI = template only. No logic mixing. |
| **DRY** | Same key structure for EN and DE — identical set of keys, different values |
| **Clean Code** | Consistent key naming: `OSC_STRIPE_` prefix, alphabetical grouping |
| **No overengineering** | SRI: document why we can't use it, add CSP as alternative. Translations: just add the missing keys. |

## Problem 1: Missing Frontend Translations (13 keys)

The Twig template `base_stripe_element_config.html.twig` references 13 `OSC_STRIPE_*` keys that don't exist in either EN or DE translation files. These render as raw key names in the payment form.

### Missing Keys (both EN and DE)

**From `base_stripe_element_config.html.twig` (card element labels + errors):**

| Key | Used For |
|-----|----------|
| `OSC_STRIPE_CARD_NUMBER` | Card number field placeholder |
| `OSC_STRIPE_CARD_EXDATE` | Expiry date field placeholder |
| `OSC_STRIPE_CARD_CVC` | CVC field placeholder |
| `OSC_STRIPE_CARD_NAME` | Cardholder name field placeholder |
| `OSC_STRIPE_ERROR_MISSING_NAME` | Validation: name required |
| `OSC_STRIPE_ERROR_MISSING_NUMBER` | Validation: card number required |
| `OSC_STRIPE_ERROR_MISSING_CVC` | Validation: CVC required |
| `OSC_STRIPE_ERROR_MISSING_EXDATE` | Validation: expiry required |
| `OSC_STRIPE_ERROR_INBOX` | Generic input error |
| `OSC_STRIPE_UNKNOWN_ERROR` | Unknown error fallback |
| `OSC_STRIPE_AUTHORIZATION_DENIED_ERROR` | Authorization denied message |

**From `payment.html.twig` (checkout flow):**

| Key | Used For |
|-----|----------|
| `OSC_STRIPE_VAULTING_VAULTED_PAYMENTS` | Saved payment methods header |
| `OSC_STRIPE_CONTINUE_TO_NEXT_STEP` | Checkout continue button |

### Translation Files

| File | Location |
|------|----------|
| EN | `translations/en/stripe_lang.php` |
| DE | `translations/de/stripe_lang.php` |

## Problem 2: Stripe.js SRI (Subresource Integrity)

**Current state:**
```html
<script src="https://js.stripe.com/v3/"></script>
```

**Finding:** Missing `integrity` and `crossorigin` attributes.

**However:** Stripe **officially does not support SRI** for `js.stripe.com/v3/`. They deploy changes to the script at the same URL without version pinning. Adding an SRI hash would break the integration when Stripe updates the script (which they do regularly).

**Reference:** [stripe/stripe-js#167](https://github.com/stripe/stripe-js/issues/167)

**PCI DSS v4.0.1 tension:** Section 6.4.3 requires integrity verification for payment page scripts. Stripe's CDN approach conflicts with this. This is a known industry-wide issue.

### Alternative: Content-Security-Policy

Instead of SRI (which Stripe blocks), we can add a CSP `script-src` directive that restricts which domains can serve JavaScript on payment pages. This is the recommended approach by Stripe.

**Current:** No CSP header or meta tag for payment pages.

**Fix:** Add CSP meta tag to the payment page template:
```html
<meta http-equiv="Content-Security-Policy"
      content="script-src 'self' 'unsafe-inline' https://js.stripe.com https://m.stripe.com;">
```

This ensures only `js.stripe.com` and `m.stripe.com` (Stripe's fraud detection) can execute scripts — no other third-party CDN can inject code.

## Implementation Plan

### What Changes

| Action | File | Change |
|--------|------|--------|
| MODIFY | `translations/en/stripe_lang.php` | Add 13 missing translation keys (EN) |
| MODIFY | `translations/de/stripe_lang.php` | Add 13 missing translation keys (DE) |
| MODIFY | `views/twig/frontend/base_js.html.twig` | Add comment documenting SRI limitation |
| MODIFY | `views/twig/frontend/base_js.html.twig` | Add CSP meta tag for payment script restriction |

### What Does NOT Change

- Stripe adapter / PHP code — no logic changes
- Admin templates — only frontend affected
- services.yaml — no DI changes
- Payment flow — cosmetic + security hardening only

## TDD Plan

### Phase 1: RED — Failing Tests

**Unit: `TranslationCompletenessTest`**
```
Test: testAllFrontendTranslationKeysExistInEnglish()
  Arrange: Load EN translation file, list of required keys
  Act: Check each key exists in array
  Assert: All 13 keys present

Test: testAllFrontendTranslationKeysExistInGerman()
  Same for DE file

Test: testNoEmptyTranslationValues()
  Assert: No key has empty string as value
```

### Phase 2: GREEN — Implementation

1. Add 13 EN translations
2. Add 13 DE translations
3. Add CSP meta tag to payment template
4. Add SRI documentation comment to script tag

### Phase 3: REFACTOR

- Pre-commit check
- Manual verification: payment page renders with correct labels

## Translation Values

### English (`translations/en/stripe_lang.php`)

```php
'OSC_STRIPE_CARD_NUMBER'                => 'Card Number',
'OSC_STRIPE_CARD_EXDATE'                => 'Expiry Date',
'OSC_STRIPE_CARD_CVC'                   => 'CVC',
'OSC_STRIPE_CARD_NAME'                  => 'Cardholder Name',
'OSC_STRIPE_ERROR_MISSING_NAME'         => 'Please enter the cardholder name.',
'OSC_STRIPE_ERROR_MISSING_NUMBER'       => 'Please enter a valid card number.',
'OSC_STRIPE_ERROR_MISSING_CVC'          => 'Please enter the CVC code.',
'OSC_STRIPE_ERROR_MISSING_EXDATE'       => 'Please enter the expiry date.',
'OSC_STRIPE_ERROR_INBOX'                => 'Please check your input.',
'OSC_STRIPE_UNKNOWN_ERROR'              => 'An unknown error occurred. Please try again.',
'OSC_STRIPE_AUTHORIZATION_DENIED_ERROR' => 'Authorization denied. Please use a different payment method.',
'OSC_STRIPE_VAULTING_VAULTED_PAYMENTS'  => 'Saved Payment Methods',
'OSC_STRIPE_CONTINUE_TO_NEXT_STEP'      => 'Continue',
```

### German (`translations/de/stripe_lang.php`)

```php
'OSC_STRIPE_CARD_NUMBER'                => 'Kartennummer',
'OSC_STRIPE_CARD_EXDATE'                => 'Ablaufdatum',
'OSC_STRIPE_CARD_CVC'                   => 'CVC',
'OSC_STRIPE_CARD_NAME'                  => 'Name des Karteninhabers',
'OSC_STRIPE_ERROR_MISSING_NAME'         => 'Bitte geben Sie den Namen des Karteninhabers ein.',
'OSC_STRIPE_ERROR_MISSING_NUMBER'       => 'Bitte geben Sie eine gültige Kartennummer ein.',
'OSC_STRIPE_ERROR_MISSING_CVC'          => 'Bitte geben Sie den CVC-Code ein.',
'OSC_STRIPE_ERROR_MISSING_EXDATE'       => 'Bitte geben Sie das Ablaufdatum ein.',
'OSC_STRIPE_ERROR_INBOX'                => 'Bitte überprüfen Sie Ihre Eingabe.',
'OSC_STRIPE_UNKNOWN_ERROR'              => 'Ein unbekannter Fehler ist aufgetreten. Bitte versuchen Sie es erneut.',
'OSC_STRIPE_AUTHORIZATION_DENIED_ERROR' => 'Autorisierung abgelehnt. Bitte verwenden Sie eine andere Zahlungsmethode.',
'OSC_STRIPE_VAULTING_VAULTED_PAYMENTS'  => 'Gespeicherte Zahlungsmethoden',
'OSC_STRIPE_CONTINUE_TO_NEXT_STEP'      => 'Weiter',
```

## Sub-Sprints

| Sprint | Description | Status |
|--------|-------------|--------|
| 91a | RED — Translation completeness test | todo |
| 91b | GREEN — Add 13 EN + 13 DE translations + CSP meta tag | todo |
| 91c | REFACTOR — Pre-commit | todo |

## Out of Scope

- SRI hash for `js.stripe.com/v3/` — Stripe doesn't support it (documented above)
- Server-side security headers (Apache config) — infrastructure team responsibility
- Rate limiting on login — OXID core issue
- Cookie SameSite attribute — OXID core / PHP config
- Admin panel IP whitelisting — infrastructure

## SRI Decision Record

**Decision:** Do NOT add SRI to `js.stripe.com/v3/`.

**Reason:** Stripe deploys changes to the same URL without version pinning. Adding SRI would break the integration on every Stripe deployment. This is Stripe's official position ([stripe-js#167](https://github.com/stripe/stripe-js/issues/167)).

**Alternative:** CSP `script-src` meta tag restricts executable script sources to `self` + `js.stripe.com` + `m.stripe.com`. This prevents supply chain attacks from unauthorized CDNs while allowing Stripe's dynamic updates.

**Compliance note:** PCI DSS v4.0.1 §6.4.3 tension is an industry-wide issue with hosted payment libraries. Stripe's approach is accepted by QSAs as PCI-compliant because Stripe itself is PCI Level 1 certified and takes responsibility for the script's integrity.
