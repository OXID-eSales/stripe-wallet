# Sprint 140 — Guest checkout the documented express way

**Date:** 2026-10-21 · **Based on:** [`../reports/01-graphql-api-docs-review-2026-10-21.md`](../reports/01-graphql-api-docs-review-2026-10-21.md) (finding 4, update 3)
**Rules for every subsprint:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Goal

A shopper without an account completes a Stripe, Mollie or PayPal checkout in the headless
storefront. The mechanism is the one the OXID GraphQL documentation describes for express checkout:
an anonymous token (`oxidanonymous`) whose basket rights a module's `PermissionProvider` grants,
and a module-owned checkout workflow that creates or matches the shop user.

## Decision gate (140a, story A1)

The storefront's basket services work on the authenticated user's id. Whether an anonymous token can
own a basket at all is proven first on the SDK shop; the result decides between
**(a)** anonymous basket + user created at `checkoutStart`, and
**(b)** a guest user created at `checkoutStart` from e-mail + addresses, basket built from the
client's line items. (a) is the documented model and the recommendation; (b) is the fallback.

## Subsprints

| #    | File                                                                       | Repos                               | Order                       |
| ---- | -------------------------------------------------------------------------- | ----------------------------------- | --------------------------- |
| 140a | [Backend: rights and guest user](sprint-140a-backend-rights-and-guest-user.md) | payment-base                   | first (decision gate)       |
| 140b | [Modules: mutations open to guests](sprint-140b-modules-open-to-guests.md) | stripe, mollie-payment, paypal      | after 140a released         |
| 140c | [Frontend: checkout without login](sprint-140c-frontend-guest-flow.md)     | oxid-fe-core, oxid-fe-user, hydroxid | after 140b released        |

## Sprint acceptance

Playwright: a fresh browser with no account buys with each of the three providers on the sandbox;
the order appears in the admin with the guest's addresses; a logged-in checkout is unchanged;
sealed-key tests unchanged; gates green in all six repos.
