# 2026-05-05 — Stripe module dev log

_Continues from `../2026/04/20260423/`._

| # | Task | Scope | Status | Landed |
|---|---|---|---|---|
| 1 | Diagnose why CI on `b-7.4.x-return-lang-STRP-120` is red — last green was `14c14fd` ("unification" Apr 23 morning), latest run `5279bbd8` fails at `Install demodata` | `stripe`, `payment-component` | ✅ Diagnosed — root cause in payment-component | 2026-05-05 |
| 2 | Diagnose why payment-component CI is also red on `db5d350` — last green was `9fcd4937` | `payment-component` | ✅ Same root cause as #1 + a separate `styles` job regression | 2026-05-05 |
| 3 | Sprint 93 — TDD-first SOLID sprint to land Sprint I §80–95 (CI install-flow steps that were specified but never landed) | `stripe`, `payment-component` | 🟡 v6 staged: drop the stale `allow-plugins.oxid-esales/payment-component: true` (3 lines across the 2 workflows + stripe composer.json). Composer was being told to allow PC as a `composer-plugin`, but PC is now `oxideshop-module` — misconfiguration that may explain the silent unified-namespace-generator plugin failure. Earlier: scope reset (v4-v5) + `--with-all-dependencies` (v5+1) didn't unblock the bootstrap. Trail: `reports/01…`, `reports/02…` | 2026-05-05 |

## Legend
- ⬜ Not started
- 🟡 In progress
- ✅ Done
- 🚫 Blocked

## Summary

Both repos went red on **2026-04-23** within hours of each other. The break
is a single change in payment-component commit `db5d350` ("unification"):
the package's composer `type` flipped from `composer-plugin` to
`oxideshop-module`. Since both stripe-wallet's and payment-component's CI
flows pin `oxid-esales/payment-component:dev-b-7.4.x`, both started
pulling the new variant the moment `b-7.4.x` was advanced past the last
green commit (`9fcd4937`).

The shop bootstrap dies at `source/bootstrap.php:184` with `Class
"OxidEsales\Eshop\Core\ConfigFile" not found` — i.e. the OXID
unified-namespace virtual classes are not on the autoload path by the
time `bin/oe-console` is first invoked. Full reasoning, evidence and the
two recommended fixes are in `reports/01-ci-broken-after-unification.md`.

A second, unrelated red is the payment-component `styles` job — three
PHPStan errors about `OxidEsales\Eshop\Application\Model\Order` typing
in `Admin/Contract/AdminActionDispatcherInterface.php` plus an unused
`HandlesCheckoutReturn` trait. Same report, §5.

## Pending
- Land the CI patch in §4 of the report — one new step in each repo's
  `.github/workflows/development.yml`:
  `docker compose exec -T php composer dump-autoload --optimize --no-interaction`,
  inserted before the first `oe-console` invocation.
- Once bootstrap is green, add an
  `oe-console oe:module:activate oe_payment_component` step ahead of the
  existing `oe_payments_stripe_wallet` activation — payment-component
  now ships a real `metadata.php` and the panel-tag iterator needs it
  active to collect anything.
- Address the payment-component `styles` regression separately
  (interface widening of the `dispatch*` signatures vs. baseline entry).
- Reverting payment-component to `composer-plugin` is **off the table** —
  the new `type: oxideshop-module` is load-bearing for the unified
  Payment admin tab.
