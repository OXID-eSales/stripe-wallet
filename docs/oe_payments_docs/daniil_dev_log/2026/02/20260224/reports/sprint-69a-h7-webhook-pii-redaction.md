# Sprint 69a — H7: Webhook Payload PII Redaction

**Date:** 2026-02-24
**Status:** DONE
**Finding:** H7 — PII in Webhook Logs (CVSS 4.5, HIGH)
**Package:** payment-component

## Problem

`AbstractWebhookProcessor::logWebhookReceived()` stores the **full** Stripe webhook payload in `oe_payments_webhooklogs` via `$log->setPayload($event->data)`. Stripe payloads contain PII:

- `customer_details.email` / `customer_details.name`
- `shipping.address` (full shipping address)
- `billing_details` (billing name + address)
- `payment_method_details.card.last4`, `exp_month`, `exp_year`
- `receipt_email`
- `metadata` (arbitrary merchant data, may contain PII)

This violates **GDPR Article 5(1)(c)** — data minimization. The webhook log table is long-lived (audit/reconciliation), storing more personal data than needed.

## Fix

### New Class: `WebhookPayloadSanitizer`

Dedicated sanitizer (SRP) that recursively strips PII paths while preserving operational data needed for debugging:

**Preserved:** `id`, `type`, `data.object.id`, `amount_*`, `currency`, `status`, `payment_intent`, etc.

**Stripped (replaced with `[REDACTED]`):**

Top-level PII keys:
- `customer_details`, `customer_email`, `customer_name`, `shipping`, `billing_details`, `receipt_email`, `metadata`

Nested PII keys (at any depth):
- `email`, `name`, `phone`, `address`, `last4`, `exp_month`, `exp_year`

### Integration

Injected into `AbstractWebhookProcessor` via constructor with a default instance (`= new WebhookPayloadSanitizer()`). The original payload stays in memory for processing; only the sanitized version is persisted.

```php
// BEFORE:
$log->setPayload($event->data);

// AFTER:
$log->setPayload($this->sanitizer->sanitize($event->data));
```

## Files Created (2)

### Production (1)
- `payment-component/src/Webhook/WebhookPayloadSanitizer.php`
  - `PII_KEYS` constant — top-level keys replaced entirely
  - `PII_NESTED_KEYS` constant — nested keys stripped at any depth
  - Recursive `stripRecursive()` for deeply nested payloads

### Tests (1)
- `payment-component/tests/Unit/Webhook/WebhookPayloadSanitizerTest.php`

## Files Modified (1)

- `payment-component/src/Webhook/AbstractWebhookProcessor.php`
  - Added `WebhookPayloadSanitizer $sanitizer` constructor parameter with default
  - Changed `logWebhookReceived()` to sanitize payload before storage

## Test Results

```
Tests: 12, Assertions: 18, Failures: 0
```

| # | Test | What It Proves |
|---|------|----------------|
| 1 | `sanitizerPreservesEventId` | `id` field kept |
| 2 | `sanitizerPreservesEventType` | `type` field kept |
| 3 | `sanitizerPreservesObjectId` | `data.object.id` kept (nested safe field) |
| 4 | `sanitizerPreservesAmounts` | `amount_total`, `amount_subtotal` kept |
| 5 | `sanitizerPreservesCurrency` | `currency` kept |
| 6 | `sanitizerStripsCustomerEmail` | `customer_details` → `[REDACTED]` |
| 7 | `sanitizerStripsCustomerName` | `customer_name` → `[REDACTED]` |
| 8 | `sanitizerStripsShippingAddress` | `shipping` → `[REDACTED]` |
| 9 | `sanitizerStripsNestedCardDetails` | `last4`, `exp_month`, `exp_year` stripped; `brand` kept |
| 10 | `sanitizerHandlesNestedObjects` | PII stripped at arbitrary nesting depth |
| 11 | `sanitizerHandlesEmptyPayload` | `[]` → `[]` (no crash) |
| 12 | `sanitizerIsDeterministic` | Same input → same output (idempotent) |

## GDPR Compliance

- **Art. 5(1)(c) Data Minimization:** Only operational data stored in webhook logs
- **Art. 5(1)(e) Storage Limitation:** PII no longer persists in audit tables
- **Art. 17 Right to Erasure:** Reduced scope of data requiring deletion on customer request

## SOLID Compliance

- **S**: Sanitizer has one job — strip PII from arrays
- **O**: `AbstractWebhookProcessor` extended via constructor injection
- **L**: Sanitizer is a plain object, no substitution concerns
- **I**: Single method interface (`sanitize(array): array`)
- **D**: Processor depends on injected sanitizer, not hardcoded stripping
