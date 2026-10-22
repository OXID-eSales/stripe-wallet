# Subsprint 140c — oxid-fe-user: checkout without login

**Repos:** oxid-fe-core, oxid-fe-user, hydroxid-project · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md) · **After:** 140b released

## Finding (report #4)

`plugins/checkout/index.ts` marks the three checkout routes `requiresAuth: true`; the shopper is
sent to the login page; there is no guest form.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                                  |
| -- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| C1 | **Red e2e:** `tests/e2e/guest-checkout.spec.ts` — a fresh context adds a product, opens `/checkout`, sees a guest form instead of the login page, fills e-mail + addresses, pays with Stripe (sandbox), lands on the success page; the order number is shown. Fails today. |
| C2 | core: `createProviderCheckoutApi` passes the optional `guest` argument; `AuthService` exposes `isAnonymous()` (claim group); unit tests.                                                                                                       |
| C3 | user: checkout routes drop `requiresAuth`; a `GuestDetailsForm.vue` (e-mail, invoice address, "deliver elsewhere" toggle; validation messages via the 138f error mapping; `account_exists` → "log in or use another e-mail" with a login link that returns to the checkout); `checkout` store keeps the guest details in memory only (not in storage — 138e policy). |
| C4 | Instance option `checkout.guest: boolean` (default true) in `schema.ts` + `public-config.mjs`; when false the routes behave as today. Unit tests on both branches; e2e with `withInstanceConfig`.                                             |
| C5 | hydroxid: inherits; e2e per instance (mock shop) proves the form renders in all three themes; screenshots to `docs/reports/guest-checkout/`; docs `docs/instances.md`.                                                                           |
| C6 | Docs `oxid-fe-user/docs/checkout.md` (guest flow, what is stored where); CHANGELOGs; release core → user → hydroxid.                                                                                                                                 |

## Acceptance

C1 green for Stripe, Mollie and PayPal; a logged-in shopper never sees the guest form; with
`checkout.guest: false` the login redirect is unchanged; responsive proof (tap targets, no horizontal
scroll) on the four device profiles; gates green in all three repos.

## Out of scope

Account creation after checkout; saving guest addresses for a later visit; address autocomplete.
