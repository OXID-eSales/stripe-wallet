# "You cannot have multiple Embedded Checkout objects" — shown to the shopper

**Date:** 2026-08-26
**Module:** `stripe` (branch `b-7.4.x`)
**Found by:** the headed Playwright run of the single-payment walkthrough, which
picked the message up off the order page

---

## 1. What the customer saw

```
messages: ["You cannot have multiple Embedded Checkout objects."]
```

Stripe's own developer wording, rendered in the order page's alert area.

## 2. Why

Stripe permits **one** Embedded Checkout object per document, and two hosts sit on
the order page:

| Host | Where |
|---|---|
| `order-submit` controller | the classic order page (`resources/build/js/controllers/order_submit_controller.js`) |
| Stripe checkout footer widget | rendered onto the same page by OPC (`views/twig/widget/checkout/stripe-footer.html.twig`) |

The footer widget already knew about this. OPC-132 gave it a **page-global
registry with mount serialisation**: every mount waits for any init in flight,
destroys whatever instance is alive, then inits its own. The order-page host,
however, called `stripe.initEmbeddedCheckout()` **directly** — so it was
invisible to that serialisation, and the footer's careful queue could not see or
wait for it. When the two collided, the loser threw the `IntegrationError` above
and passed `error.message` straight to the customer.

## 3. The fix

- The registry moved out of the twig template into
  `resources/build/js/embedded_checkout_registry.js`, and the order-page host now
  mounts through it. Every mount, from either host, is serialised against the
  same queue. The footer widget keeps its inline copy — it cannot import from the
  bundle — and both now name the module as the shared contract.
- **Separately, and regardless of the race:** a Stripe library error is written
  for developers. Both hosts now log it and show the customer the generic
  wording. Messages our own server sent (declined payment, missing consent) are
  still shown as they are — only `IntegrationError` / Embedded-Checkout wording is
  swapped.

## 4. What the test proves, and what it cannot

The collision is a **race**: whether it happens depends on which host initialises
first and on whether OPC replaces the footer's HTML mid-init. It does not
reproduce on demand — after the fix *and* with the fix reverted, a given run may
well mount one sheet and show nothing.

So counting sheets proves nothing on its own, and
`stripe-embedded-single-mount.spec.ts` asserts the invariant underneath instead:

> a mounted sheet must be **in the page-wide registry**

A host that inits Stripe directly is invisible to the other's serialisation
whether or not the race is lost on a given load — that is the defect, and it is
deterministic. Verified both ways:

| Order-page host | Spec |
|---|---|
| inits Stripe directly (before) | **fails** — "the mounted sheet must be in the page-wide registry" |
| mounts through the registry (after) | **passes** |

The spec also asserts that no Stripe library wording ever appears on the page.

## 5. Verification

| Gate | Result |
|---|---|
| `composer phpcs` · phpstan `--level=max` | clean (no PHP changed) |
| `npm run build` (production + dev bundles) | rebuilt, `oe:module:install-assets` run |
| E2E `stripe-embedded-single-mount` | passes; fails with the change reverted |
| E2E `stripe-eager-mount-single-session` | passes |
| E2E `single-active-payment` | passes |

## 6. Noticed while looking

The order page's frame list shows Mollie's Components mounting alongside Stripe's
sheet (`mollie-components-controller-iframe`, `cardNumber-input`, …) when both
providers are active. Nothing is broken by it here, but two PSP widgets
initialising on one order page is worth a look of its own.

## 7. Commits

| Commit | Subject |
|---|---|
| `a88bfd4` | fix(checkout): one embedded sheet per page, and no Stripe wording for shoppers |
| `68f70b1` | chore(e2e): bump submodule — embedded single-mount spec |

Submodule `e2e-tests-playwright` (`projects/Stripe`): the spec itself.
