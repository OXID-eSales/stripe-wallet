# 2026-05-08 — Stripe module dev log

_Continues from `../20260507/`._

| # | Task | Scope | Status | Landed |
|---|---|---|---|---|
| 1 | Sprint 102 — Rename `PaymentComponent` → `PaymentBase` end-to-end across the suite. Driven by the rename split into five sub-sprints (102.1–102.5). | `payment-component` (→ `payment-base`), `one-page-checkout`, `stripe`, `paypal`, shop root | ⬜ Plan filed; awaiting go-ahead from user. | — |

## Legend
- ⬜ Not started
- 🟡 In progress
- ✅ Done
- 🚫 Blocked

## Summary

`OxidEsales\PaymentComponent\` is the provider-agnostic foundation
that the PSP modules (Stripe, PayPal) and the checkout module
(`one-page-checkout`) depend on. The chosen identifier reads as a
synonym of "module" instead of "the base layer for payment
providers". Sprint 102 standardises the name on `PaymentBase`:

- composer package `oxid-esales/payment-component` → `oxid-esales/payment-base`
- PHP namespace `OxidEsales\PaymentComponent\` → `OxidEsales\PaymentBase\`
- module ID `oe_payment_component` → `oe_payment_base`
- target-directory `oe_payment_component` → `oe_payment_base`
- twig alias `@oe_payment_component` → `@oe_payment_base`
- directory `extensions/payment-component/` → `extensions/payment-base/`

DB table names stay (`oe_payments_*` is unaffected — that prefix
already reads correctly).

## Scope by module (counts gathered 2026-05-08)

| Module                | PHP files w/ namespace ref | yaml/json/neon refs |
|-----------------------|---------------------------:|--------------------:|
| `payment-component`   | 353                        | 7                   |
| `one-page-checkout`   | —                          | 14 (mixed)          |
| `stripe`              | —                          | 147 (mixed)         |
| `paypal`              | —                          | 100 (mixed)         |
| shop root composer    | —                          | 1                   |

(Mixed = `.php`, `.yaml`, `.json`, `.neon`, `.xml` — all in one
`grep -rl` pass, excluding `docs/`, `vendor/`, `node_modules/`.)

## Sprint 102 plan

The rename is split into five sub-sprints. Each is a single git commit,
TDD-style: run the affected module's test suite at the gate.

1. **[102.1](sprints/sprint-102.1-rename-payment-component-internals.md)** —
   Rename payment-component's own code (composer name, autoload,
   namespace, module ID, target-directory, services.yaml,
   metadata.php, migration data ns, src/, tests/) AND patch the
   `require` line in every consumer composer.json so that
   `composer install` still resolves. Directory stays
   `extensions/payment-component/` (renamed in 102.5). Gate:
   payment-base unit + integration tests green; consumers are
   expected red here.
2. **[102.2](sprints/sprint-102.2-update-one-page-checkout-consumer.md)** —
   one-page-checkout `use` statements and `services.yaml` FQCN
   service IDs. Gate: one-page-checkout phpunit green.
3. **[102.3](sprints/sprint-102.3-update-stripe-consumer.md)** —
   stripe `use` statements, `services.yaml` FQCN service IDs, test
   files, non-dev-log docs. Gate: `./bin/pre-commit-check.sh --full`
   green (1707 tests baseline from sprint 101).
4. **[102.4](sprints/sprint-102.4-update-paypal-consumer.md)** —
   paypal `use` statements, `services.yaml` FQCN service IDs, test
   files. Gate: paypal phpunit green.
5. **[102.5](sprints/sprint-102.5-directory-rename-and-final-sweep.md)** —
   `git mv extensions/payment-component extensions/payment-base`;
   update path-repo URL in shop-root composer.json + every consumer
   composer.json (`../payment-component` → `../payment-base` and
   `./extensions/payment-component` → `./extensions/payment-base`);
   `composer update` in shop root; `oe:module:deactivate
   oe_payment_component` followed by install/activate of
   `oe_payment_base`; final cross-module test sweep; module symlink
   under `source/source/out/modules/oe_payment_base` regenerated.
   Gate: every module's test suite green; module installs/activates;
   adminer page for the order-detail Stripe tab loads in browser.

Top-level overview: [`sprints/sprint-102-rename-payment-component-to-payment-base.md`](sprints/sprint-102-rename-payment-component-to-payment-base.md).

## Pending

- Decision: keep the legacy module symlink
  `source/source/out/modules/oe_payment_component` as a one-release
  back-compat alias, or hard-cut at activation? The plan currently
  deactivates the old ID and activates the new one — no alias.
- Do existing **shop database rows** under `oxconfig`,
  `oxmodule_settings`, or any `module_id` column that references the
  literal string `'oe_payment_component'` need a one-shot UPDATE?
  Ascertain in sub-sprint 102.5 §pre-flight.

## Artifacts

- Sprint plan: [`sprints/sprint-102-rename-payment-component-to-payment-base.md`](sprints/sprint-102-rename-payment-component-to-payment-base.md)
- Sub-sprints: see the index above.
- Scope survey: numbers in this file (also in main sprint §3).
