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

## 6. Checked afterwards: the two providers do not collide — earlier note withdrawn

While fixing this I noted that the order page's frame list showed Mollie's
Components (`mollie-components-controller-iframe`, `cardNumber-input`, …) next to
Stripe's sheet, and flagged "two PSP widgets on one order page" as worth a look.
**That was wrong.** Measured on the order page, both ways, with both providers
active:

| Selected payment | Mollie controller hosts | Mollie card iframes | Stripe embedded sheets |
|---|---|---|---|
| Stripe Wallet | 0 | 0 | **1** |
| Mollie | **1** | **1** | 0 |

Each provider's widget renders only for its own order. The Mollie frames in that
earlier dump were transient — the OPC footer manager loads a widget per payment
method and swaps its content, so frames from a previously selected method can
still be listed for a moment.

Mollie's own guard for the standard checkout also passes unchanged:
`mollie-standard-inline-card.spec.ts` — each of the four Components fields
(`cardNumber`, `cardHolder`, `expiryDate`, `verificationCode`) mounts exactly
once on `cl=order`, no doubling.

## 7. Commits

| Commit | Subject |
|---|---|
| `a88bfd4` | fix(checkout): one embedded sheet per page, and no Stripe wording for shoppers |
| `68f70b1` | chore(e2e): bump submodule — embedded single-mount spec |

Submodule `e2e-tests-playwright` (`projects/Stripe`): the spec itself.

---

## 8. Follow-up 2026-08-27: the first fix broke paying — reversed

Sharing the *creation* of the sheet was wrong, and it made the order page worse
than the bug it fixed. A single instance handed between hosts is mounted by
whichever calls `mount()` last, and that was the OPC footer widget — whose
container on the order page is **0 pixels tall**. Measured on the order page:

| Container | Owner | iframes | height |
|---|---|---|---|
| `#stripe-embedded-checkout` | order page (visible) | **0** | 300 |
| `.stripe-embedded-checkout` | OPC footer | **1** | **0** |

With the Place-Order button hidden in eager mode, that is a checkout with **no way
to pay** — reported as "the iframe is not loaded". No server error; the shop log
was empty.

**Reversed the ownership instead of sharing creation.** The `order-submit` host
creates and mounts its own sheet; the footer widget stands down when the order
page hosts one (an order-submit host in iframe mode with the mount container
present). Only one host ever initialises, so the collision the registry was
guarding against cannot arise. What is still shared is only the *record* of the
live instance, on the same window key the footer's OPC-132 serialisation already
used, so it can retire an instance the other host created.

After: the sheet mounts in `#stripe-embedded-checkout` at 825px, the footer's
container stays empty. On OPC pages, where the footer is the only host, nothing
changes.

### Why the spec let it through

It asserted "at most one sheet". A page with the sheet in the wrong container
satisfies that — as does a page with no sheet at all. It now asserts a **usable**
sheet: mounted, in the order page's own container, tall enough to be seen, and
recorded page-wide. Verified against the broken arrangement, where it fails with
*"the order page hosts the sheet, not the footer widget"*.

The lesson is the useful part: an assertion phrased as an upper bound (`≤ 1`,
`not.toContain`) passes on an empty page. Pair it with the positive one.

### Commits

| Commit | Subject |
|---|---|
| `169bc95` | fix(checkout): the order page owns its embedded sheet — regression fix |
