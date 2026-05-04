# Sprint 90: Special Characters in Customer Data Break Payment

**Date:** 2026-04-16
**Branch:** `b-7.4.x`
**Ticket:** STRP-125

## Core Requirements

| Principle | Application |
|-----------|-------------|
| **TDD-first** | Sprint 90a: tests with umlauts, accents, emoji, Cyrillic, control chars BEFORE any sanitization code |
| **DevOps-first** | Sprint 90c: full pre-commit + Playwright test with special characters |
| **SOLID / SRP** | One sanitization service (`CustomerDataSanitizer`) — all fields go through it. Stripe adapter untouched. |
| **DRY** | Single sanitize method reused for name, address, city, company — no per-field duplication |
| **Liskov** | Sanitizer implements interface — swappable for different providers with different rules |
| **Clean Code** | Pure function: string in → string out, no side effects, no state |
| **No overengineering** | Strip control chars + validate UTF-8 + trim length. No transliteration, no locale-specific rules, no regex libraries. |

## Problem

When a customer enters special characters in account detail fields (name, address, city, company), the Stripe payment fails. The module passes raw OXID field values to the Stripe API without sanitization.

### Reproduction

1. Open storefront, add product to cart
2. Go to account/billing details
3. Enter special characters in name: `Müller-O'Brien <script>` or `José 😀 Straße`
4. Proceed to checkout → payment fails

### Why It Fails

Customer data flows from OXID → Stripe API with zero processing:

```
OXID DB (oxuser.oxfname) → getFieldData() → trim() → Stripe API
                                                 ↑
                                          NO sanitization
```

The Stripe API rejects or mishandles:
- **Control characters** (U+0000–U+001F) — invalid in JSON
- **Invalid UTF-8 sequences** — PHP's `json_encode` fails silently
- **Excessively long strings** — Stripe has field length limits (e.g., name: 255 chars)
- **HTML/script tags** — not a Stripe issue, but a hygiene issue (stored in metadata)

**What Stripe DOES accept:**
- Unicode letters (umlauts, accents, Cyrillic, CJK, Arabic)
- Apostrophes, hyphens, spaces
- Most punctuation

## Current Data Flow

| Source | Field | Processing | Sent To |
|--------|-------|-----------|---------|
| `oxuser.oxfname` + `oxlname` | Customer name | `trim()` only | `Stripe\Customer::create(['name' => ...])` |
| `oxuser.oxusername` | Email | None | `Stripe\Customer::create(['email' => ...])` |
| `oxuser.ox*` address fields | Billing address | None | Not sent to checkout session (address collected by Stripe Checkout) |
| Order metadata | Various | None | `PaymentIntent.metadata` (512 char limit per value) |

## Fix: CustomerDataSanitizer

A lightweight, stateless sanitization service that cleans customer data before it reaches the Stripe API.

### Sanitization Rules

| Rule | What | Why |
|------|------|-----|
| **Strip control chars** | Remove U+0000–U+001F except tab/newline | Invalid in JSON, causes API errors |
| **Validate UTF-8** | Replace invalid sequences with `?` | Prevents `json_encode` failures |
| **Trim whitespace** | Remove leading/trailing whitespace + collapse multiple spaces | Clean display |
| **Enforce max length** | Truncate to field-specific limit (default 255) | Stripe field limits |

### What We Do NOT Do

- No HTML stripping — Stripe API accepts plain text, HTML is just characters
- No transliteration (Müller → Mueller) — Stripe handles Unicode fine
- No locale-specific rules — same sanitizer for all languages
- No emoji removal — Stripe accepts emoji in name/address fields
- No validation of field content (e.g., "name must contain letters") — that's OXID's job

## Implementation Plan

### What Changes

| File | Change |
|------|--------|
| CREATE `src/Stripe/Service/CustomerDataSanitizer.php` | Stateless service: `sanitize(string, int $maxLength = 255): string` |
| MODIFY `StripeCheckoutSessionHandler.php` | Sanitize name before passing to customer service |
| MODIFY `StripeCustomerService.php` | Sanitize name + email before Stripe API call |
| MODIFY `services.yaml` | Register `CustomerDataSanitizer` |
| CREATE `tests/Unit/Stripe/Service/CustomerDataSanitizerTest.php` | Full character set tests |

### What Does NOT Change

- `StripeAdapter` — receives already-sanitized data
- `CheckoutSessionService` — doesn't handle customer data
- `OxidShopAdapter` — data retrieval, not processing
- `ControllerRequestHelper` — no customer data
- payment-component — no changes

### CustomerDataSanitizer API

```php
final class CustomerDataSanitizer
{
    public function sanitize(string $value, int $maxLength = 255): string
    {
        // 1. Ensure valid UTF-8
        // 2. Strip control characters (keep \t, \n)
        // 3. Collapse whitespace + trim
        // 4. Truncate to maxLength
    }
}
```

Usage:
```php
// In StripeCustomerService or StripeCheckoutSessionHandler:
$name = $this->sanitizer->sanitize($firstName . ' ' . $lastName);
$email = $this->sanitizer->sanitize($email, 320); // RFC 5321 max
```

## TDD Plan

### Phase 1: RED — Failing Tests

**Unit: `CustomerDataSanitizerTest`**

| Test | Input | Expected |
|------|-------|----------|
| `testPreservesNormalAscii` | `'John Doe'` | `'John Doe'` |
| `testPreservesGermanUmlauts` | `'Müller-Straße'` | `'Müller-Straße'` |
| `testPreservesAccentedCharacters` | `'José García'` | `'José García'` |
| `testPreservesCyrillic` | `'Иванов Пётр'` | `'Иванов Пётр'` |
| `testPreservesApostrophesAndHyphens` | `"O'Brien-Smith"` | `"O'Brien-Smith"` |
| `testStripsControlCharacters` | `"John\x00\x01Doe"` | `'JohnDoe'` |
| `testPreservesTabAndNewline` | `"Line1\nLine2"` | `"Line1\nLine2"` |
| `testReplacesInvalidUtf8` | `"Bad\xC0\xAFdata"` | Clean UTF-8 string |
| `testCollapsesWhitespace` | `'  John   Doe  '` | `'John Doe'` |
| `testTruncatesToMaxLength` | 300-char string, max=255 | 255-char string |
| `testTruncatesAtCharBoundary` | Multibyte string at limit | Clean truncation (no broken chars) |
| `testPreservesEmoji` | `'José 😀'` | `'José 😀'` |
| `testEmptyStringReturnsEmpty` | `''` | `''` |

### Phase 2: GREEN — Implementation

1. Create `CustomerDataSanitizer` with `sanitize()` method
2. Register in `services.yaml`
3. Inject into `StripeCustomerService` — sanitize name + email
4. Inject into `StripeCheckoutSessionHandler` — sanitize name before building customer params

### Phase 3: REFACTOR

- Pre-commit check
- Playwright test: create order with `Müller-O'Brien` as customer name

## Sub-Sprints

| Sprint | Description | Status |
|--------|-------------|--------|
| 90a | RED — Failing tests for CustomerDataSanitizer | todo |
| 90b | GREEN — Implement sanitizer + wire into customer flow | todo |
| 90c | REFACTOR — Pre-commit + E2E verification | todo |

## Out of Scope

- OXID-side input validation (OXID should validate before storing in DB)
- Address field sanitization (address is collected by Stripe Checkout, not passed from OXID)
- PaymentIntent metadata sanitization (separate concern, 512 char limit per value)
- Email format validation (OXID validates email format on registration)
- Transliteration for non-Latin scripts
