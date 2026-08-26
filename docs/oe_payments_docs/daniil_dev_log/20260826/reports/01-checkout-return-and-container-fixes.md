# Checkout broke, then refused a paid return — what was wrong and why Mollie was fine

**Date:** 2026-08-26
**Module:** `stripe` (branch `b-7.4.x`), with the decision half in `payment-base`
**Shop:** `daniil.oxiddev.de` — one usable payment method (Stripe Wallet), OPC active, payment-base iframe mode ON
**Trigger:** a payment-base sprint (single active payment method) recompiled the DI container, and checkout fell over

---

## 1. What the customer saw, in order

1. *"Payment processing failed. Please try again."* — checkout dead at the order page.
2. After that was fixed: *"Payment verification failed"* — **after Stripe had charged them**.

Both are now fixed. Neither was caused by the sprint that surfaced the first one.

## 2. "Payment processing failed" — a private service

`RetryCleanupService` was fetched by id from `StripeOrderController` but declared
private, so Symfony inlined it into its only compile-time consumer and deleted
the id. `createCheckoutSession()` then died in `cleanupPreviousCheckoutAttempt()`.

The definition *looked* deliberate: `services.yaml` re-declares the service after
the `src/Stripe/Service/*` sweep to inject `$logger` — and a re-declaration
re-inherits `_defaults: public: false`. That is the trap.

It had been latent for as long as the compiled container was stale; a
`oe:module:install` / `oe:cache:clear` is what made it bite. **When a
"removed or inlined" error appears right after a module install, suspect a
long-standing visibility bug, not the change that triggered the recompile.**

Audited all 14 ids fetched through `ServiceContainer::getServiceFromContainer()`:

| Service | State | Consequence |
|---|---|---|
| `RetryCleanupService` | private | checkout dead (loud) |
| `StripeOrderApiService` | private | **silent** — nothing references it at compile time so it was removed outright, and its caller catches `Throwable`; the admin order tab's Stripe history just came up empty |
| the other 12 | already `public: true` | — |

## 3. "Payment verification failed" — a session pointer that named the wrong contract

`checkoutSuccess()` compared the returned contract id against the session
variable `stripe_contract_id`, refusing anything else.

That pointer is written in exactly one place —
`StripeOrderController::createCheckoutSession()` — and names only the **last**
contract that path created. A single checkout ends up with several: measured on
one order-page load, **five** stripe contracts (each with its own Stripe session
and its own early order) against **one** `createCheckoutSession` call from the
browser. The rest come from `StripePaymentHandler`, the payment-base/OPC handler
path, which never touches the pointer.

Each embedded sheet carries the contract it was opened with. Pay in any sheet but
the last-created one and the return was refused — money taken, no order, and a
message that says nothing.

**The fix.** The contract id is already authenticated by its HMAC
`contract_token`, so the pointer comparison protected nothing; what it was
reaching for is ownership. `CheckoutReturnInputsResolver::checkOwnership()` now
asks whether the contract belongs to the shopper who is here. Someone else's is
still refused; when either side is unknown the return proceeds, because a charged
payment must not be discarded over a check that cannot be made.

**And the return says why now.** Five distinct checks used to end in that one
sentence with **nothing in the log**, which is why the first report of it was
unanswerable. `CheckoutReturnRejection` (enum) owns both texts — the customer
keeps the vague sentence, the log gets a stable token — and every exit goes
through one `rejectReturn()`:

```
STRP: checkout return rejected {"reason":"missing_session_id"}
STRP: checkout return rejected {"reason":"missing_contract_identifiers"}
STRP: checkout return rejected {"reason":"invalid_contract_token"}
STRP: checkout return rejected {"reason":"contract_mismatch"}
STRP: checkout return rejected {"contractId":"…","reason":"contract_not_found"}
STRP: checkout return rejected {"contractId":"…","checkoutSessionId":"…","reason":"no_order_created"}
```

That instrumentation is what identified the bug: the next failed attempt logged
`contract_mismatch` and there was nothing left to guess.

## 4. Why Mollie works straight forward

Worth writing down, because Mollie is the shape Stripe has now been moved
towards. `MollieOrderController::checkoutReturn()` does four things:

1. read `contract_id` + `contract_token`
2. validate the token
3. load the contract
4. dispatch

| | Mollie | Stripe (before) | Stripe (now) |
|---|---|---|---|
| binds the return to the session | no — the HMAC token is the proof | yes, via `stripe_contract_id` equality | ownership: contract's user vs current user |
| several contracts per checkout | harmless — nothing compares them | **fatal** — only the last one could be paid | harmless |
| logs why a return was refused | yes, `['reason' => $code]` from the start | **nothing** | yes, six named reasons |
| pending vs failed on return | distinguishes them; a webhook-finalized payment lands on thankyou with a notice | not distinguished | not distinguished (see §7) |
| embedded/iframe mode | cannot be iframed (`X-Frame-Options: DENY`), so always redirect | eager embedded mount | unchanged |

Two independent reasons Mollie never hit this: it never added the session-pointer
check, and being redirect-only it never mounts an eager sheet, so one checkout
means one session. Stripe's iframe mode supplies the extra contracts, and the
pointer check turned them into a refused payment.

## 5. Also fixed here

- `views/twig/extensions/themes/default/page/checkout/order.html.twig` replaces
  core's whole `shippingAndPayment` block for Stripe payments and renders its own
  copy, so payment-base's override of the nested
  `checkout_order_payment_method` never reached it. The copy now asks the same
  getter itself, guarded with `is defined` so a missing extension shows the block
  instead of raising a Twig error. **Any block nested inside `shippingAndPayment`
  is unreachable on a Stripe order** — worth remembering before overriding one.
- `CheckoutReturnInputs` / `CheckoutReturnRejection` had to be excluded from the
  `src/Stripe/Service/*` autowire sweep. Left in, the sweep tries to build the
  value object and breaks container compilation on every page. A new file under
  `Service/` is a service until you say otherwise.

## 6. Verification

TDD throughout: 17 unit tests written red first (11 resolver, 3 enum, 3 logging
seam), then the implementation. The full stripe unit suite still cannot be built
in this shop — OXID's `ModuleChainsGenerator` recurses through the five-module
`PaymentController` chain under PHPUnit's autoloader — so tests were run per
file, which works.

| Gate | Result |
|---|---|
| `composer phpcs` | clean |
| phpstan `--level=max` | no errors |
| phpmd (changed files, `--strict`) | clean |
| unit, per file | 39 tests / 74 assertions |
| payment-base Unit / Integration | 1182 / 102 |

End-to-end, on the live shop: `blPaymentBaseUseIframe` switched off for one run
(and restored), walkthrough driven through the Stripe hosted page with a test
card →

- landed on `cl=thankyou`, no error on the page
- **no** new `checkout return rejected` line
- contract `committed`, order **247**, `OXTRANSSTATUS=OK`, `OXPAID` set

That is exactly the case that used to fail: a checkout with several contracts,
returning with one of them. The four cancelled sibling contracts around order 247
show the duplication is still there — it just cannot cost a payment any more.

New E2E specs (in the `tests/e2e/playwright` submodule, branch `projects/Stripe`):
`single-active-payment.spec.ts` and `stripe-eager-mount-single-session.spec.ts`.
Note for whoever runs them: this shop's Stripe Wallet renders a **wallet-only**
express-checkout sheet with no card element, so embedded mode cannot be completed
headlessly — the spec annotates and stops there. Drive a card in redirect mode.

## 7. Open

- **The duplicate checkout sessions.** Two producers —
  `StripeOrderController::createCheckoutSession()` and `StripePaymentHandler` —
  both create Stripe sessions, each with a contract and an early order. Symptoms:
  `IntegrationError: You cannot have multiple Embedded Checkout objects` on every
  eager mount, and `oxorder` rows with an empty `OXPAYMENTTYPE`. The browser side
  is guarded (one call per load); the server side is untouched. Worth its own
  sprint, and it should decide which producer owns the session in OPC + iframe
  mode.
- **Pending returns.** Mollie tells a still-open payment apart from a failed one
  and lands it on thankyou; Stripe reports `no_order_created` for both. Worth
  copying.

## 8. Commits (`b-7.4.x`)

| Commit | Subject |
|---|---|
| `9189b6f` | fix(di): make runtime-fetched services public, keep return VOs out of the sweep |
| `bfac6ca` | fix(checkout-return): stop refusing a paid return, and say why when we do |
| `cbc174c` | fix(order-page): honour the single-payment decision in Stripe's own block |
| `800dc9f` | chore(e2e): bump submodule — single-active-payment and eager-mount specs |

The payment-base side (the single-payment decision itself) is in that module's
dev log for today: `docs/dev_log/20260826/`.
