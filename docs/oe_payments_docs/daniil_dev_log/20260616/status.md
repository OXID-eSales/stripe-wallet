# Dev log — 2026-06-16

## Sprints

| # | Title | Issue | Status |
|---|---|---|---|
| 126 | [opalreturns: make RefundIntentHandler wiring tolerant of an absent/old payment-base](done/sprint-126-opalreturns-refund-intent-handler-conditional-service.md) | `oe:module:activate opalreturns` fataled at compile — `services.yaml` declared a hard payment-base FQCN as a service `class:` | done |

## What happened

`opalreturns@b-7.4.x-agnosticism` (STRP-135 — refund decision logic moved into
payment-base) could not be activated standalone:

    Invalid service "opalreturns.refund_intent_handler":
    class "OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler" does not exist.

Diagnosis + fix plan: `reports/01-opalreturns-refund-intent-handler-hard-dependency.md`.
Two prongs had to converge — both now satisfied:

- Prong A (payment-base): `RefundIntentHandler` + `RefundIntentEventInterface` are on
  canonical `origin/b-7.4.x` @ `7bc2ef9` (verified). No composer constraint change —
  payment-base stays `suggest`.
- Prong B (opalreturns): Sprint 126 — replaced the hard `class:` reference with a factory
  service (`RefundIntentListenerFactory`) that returns the real handler when
  `class_exists(RefundIntentHandler::class)`, else a no-op `NullRefundIntentListener`
  (manual-refund fallback). Compiler-pass + conditional-YAML options were rejected: OXID
  7.4 modules have no reliable compiler-pass hook and auto-import only root `services.yaml`.

## Key decisions / lessons

- Symfony validates a service's `class:` at compile time regardless of `@?` argument
  optionality — `@?` only nullifies arguments, never the `class:` key.
- OXID 7.4 forced two factory-service accommodations: `class:` set to the factory's own
  class (for `CheckDefinitionValidityPass`) and `method: '__invoke'` on the listener tag
  (for `RegisterListenersPass`). The payment-base FQCN appears nowhere in opalreturns YAML.
- `ReturnRefundRequestedEvent`'s hard `implements RefundIntentEventInterface` is path-safe
  (C-skip) — only constructed when the `@?` contract repo is non-null; never autoloads
  without payment-base. Pinned by a guard test.
- The factory treats absent and too-old payment-base identically -> graceful degradation
  with no version pin needed.

## Verified

- Commits on opalreturns `b-7.4.x-agnosticism`: `7adeb87 -> 8478dc0 -> 9ba9f50 -> 91947b8`.
- Unit 297->308 (+11, green) | PHPStan max 0 | PHPCS 0 | no new suppressions.
- End-to-end: `oe:module:activate opalreturns` exit 0 (no fatal), shop HTTP 200, service
  resolves to the real `RefundIntentHandler`, listener registered for
  `ReturnRefundRequestedEvent` in the live compiled container alongside all 5 existing
  opalreturns listeners. Full evidence in report section 7.

## Still open (not blocking the merge)

- `EventBrokerInterface` has no concrete binding on either side yet — handler early-returns
  (safe) until the active PSP (Stripe/PayPal) binds it. Separate work.

Verdict: `b-7.4.x-agnosticism` is mergeable.
