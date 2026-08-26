# 2026-08-26 — day summary (Stripe)

**Branch:** `b-7.4.x` · **Shop:** `daniil.oxiddev.de` · **CI:** green on OXID CE 7.4 and 7.5

Everything on this page started from a payment-base sprint that had nothing to do
with Stripe: *when a shop offers one payment method, assign it and stop asking*.
Installing its one new setting recompiled the DI container, and the container was
holding two long-latent defects together. That is the theme of the day — none of
the three bugs below were introduced today; they were **exposed** today.

---

## 1. Three defects, in the order the customer met them

### "Payment processing failed. Please try again." — checkout dead

`RetryCleanupService` was fetched by id from `StripeOrderController` but declared
**private**, so Symfony inlined it into its only compile-time consumer and
deleted the id. The definition looked deliberate: `services.yaml` re-declares the
service after the `src/Stripe/Service/*` sweep to inject `$logger` — and a
re-declaration re-inherits `_defaults: public: false`.

Audited all 14 ids fetched through `ServiceContainer::getServiceFromContainer()`.
One more had the same defect, silently: **`StripeOrderApiService`** is referenced
nowhere at compile time (so it was removed outright) and its caller catches
`Throwable` — the admin order tab's Stripe history simply came up empty. The
other 12 were already public.

→ [report 01](01-checkout-return-and-container-fixes.md) §2 · commit `9189b6f`

### "Payment verification failed" — after the customer was charged

`checkoutSuccess()` compared the returned contract id against session
`stripe_contract_id`. That pointer is written in one place only and names the
**last** contract the order page created, while a checkout ends up with several.
Paying in any other sheet was refused *after* Stripe took the money.

Two halves were fixed. The return now **says why** it refuses — six named reasons
in the log, one deliberately vague sentence for the customer — and that
instrumentation is what identified the bug on the next attempt
(`{"reason":"contract_mismatch"}`). And the check itself became an **ownership**
check: the id is already authenticated by its HMAC token, so what remains to ask
is whose contract it is.

→ [report 01](01-checkout-return-and-container-fixes.md) §3 · commits `bfac6ca`, `aa6882d`

### Five Stripe sessions for one checkout

The OPC checkout API calls `StripePaymentHandler::processPayment()` on every
accordion step and it prepared a fresh contract, early order and Stripe session
each time; `RetryCleanupService` then cancelled whatever was in flight whenever a
checkout page rendered. Each behaviour was sensible alone; together they produced
five of everything and made the mismatch above reachable.

Both now consult one `CheckoutInFlightGuard`. **5 → 2 sessions** per walkthrough.

→ [report 02](02-duplicate-checkout-sessions.md) · commit `745a193`

## 2. One more, in Stripe's own template

`views/.../page/checkout/order.html.twig` replaces core's whole
`shippingAndPayment` block for Stripe payments and renders its own copy, so
payment-base's override of the nested `checkout_order_payment_method` never
reached it. **Any block nested inside `shippingAndPayment` is unreachable on a
Stripe order** — worth knowing before overriding one. The copy now honours the
same getter, guarded with `is defined`.

→ commit `cbc174c`

## 3. Commits

| Commit | Subject |
|---|---|
| `9189b6f` | fix(di): make runtime-fetched services public, keep return VOs out of the sweep |
| `bfac6ca` | fix(checkout-return): stop refusing a paid return, and say why when we do |
| `cbc174c` | fix(order-page): honour the single-payment decision in Stripe's own block |
| `800dc9f` | chore(e2e): bump submodule — single-active-payment and eager-mount specs |
| `171f9f5` | docs: dev log — checkout return and container fixes |
| `aa6882d` | fix(checkout-return): don't let the ownership check break on an absent user |
| `745a193` | fix(checkout): one checkout, one Stripe session |
| `9235f8c` | chore(e2e): bump submodule — specs annotate instead of failing off-scenario |
| `1ff4001` | docs: dev log — the duplicate checkout sessions |

Submodule `e2e-tests-playwright` (`projects/Stripe`): `fbf595e`, `90bf09a`.

## 4. Verification

| Gate | Result |
|---|---|
| `composer phpcs` · phpstan `--level=max` · phpmd | clean throughout |
| unit, per file (new + touched) | 88 tests across the guard, rule, return path, handler and cleanup |
| **CI** (isolated shop, full unit suite + integration) | **green, 7.4 and 7.5** |
| E2E `single-active-payment` | passes — full payment through the hosted page → `cl=thankyou`, contract `committed`, order **247**, `OXPAID` set |
| E2E `stripe-eager-mount-single-session` | passes |

CI is the only place the full unit suite runs: locally it cannot even be built,
because OXID's `ModuleChainsGenerator` recurses through the five modules
extending `PaymentController` under PHPUnit's autoloader. Per-file runs work and
are what to use here.

## 5. Open

- **Two sessions, not one.** What remains is one session per checkout *UI* — OPC's
  accordion and the classic order page's eager mount. Collapsing them is a
  decision about which UI owns the session.
- **Pending vs failed on return.** Mollie distinguishes a still-open payment from
  a failed one and lands it on thankyou with a notice; Stripe reports
  `no_order_created` for both. Worth copying — see report 01 §4 for the full
  comparison of why Mollie never hit any of today's defects.
- **33 dependabot advisories** on the repo's default branch (2 critical, 11 high),
  surfaced by today's pushes. Unrelated to this work, but someone should look.
