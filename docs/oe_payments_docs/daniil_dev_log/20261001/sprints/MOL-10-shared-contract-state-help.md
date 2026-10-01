# MOL-10 (shared Help) — Stripe's column of payment-base's contract-state Help — 2026-10-01

**Branch:** `b-7.4.x-MOL-10-contract-state-help` (also in payment-base and mollie-payment). Requires payment-base's
branch of the same name (merged into `b-7.4.x` first — Stripe's CI installs payment-base from `b-7.4.x`).

- `src/Stripe/Admin/StripeContractStateHelp.php`: state → PaymentIntent status (`not_finished` → requires_payment_method /
  requires_confirmation / requires_action, `pending` → processing, `authorized` → requires_capture, `ready_to_commit` →
  succeeded, `committed`/`fulfilled` → none, `cancelled` → canceled (requested_by_customer, abandoned, duplicate, fraudulent),
  `expired` → canceled (automatic) / checkout.session.expired, `failed` → payment_intent.payment_failed), header + intro idents.
- `Core\ViewConfig::getStripeContractStateHelp()`; `module_config.html.twig` gains the `admin_module_config_group` override
  (Help group after the last group, Stripe module only, shared table with Stripe's column); `stripe_panel.html.twig` gains an
  "OXID Contract Status" row (builder exposes `contractState` via `OrderContractResolver::getContractForOrder()`) with
  payment-base's "?" hint; translations `STRIPE_CONTRACT_STATE`, `STRIPE_HELP_CONTRACT_STATES_INTRO`, `STRIPE_HELP_COL_STRIPE_STATUS` (EN/DE).
- Tests: `StripeContractStateHelpTest`, `StripeAdminHelpTemplatesGuardTest`, builder `contractState` ×2, ViewConfig source
  test — red → green; standalone Unit suite 1587 green. e2e (mollie-payment admin suite, shop with all three modules):
  Stripe Settings Help group with 3 columns; Stripe order panel "?" layer opens/closes.
- Gates: phpcs, PHPStan green; PHPMD reports a pre-existing `TooManyMethods` on `StripeOrderController` (untouched).
