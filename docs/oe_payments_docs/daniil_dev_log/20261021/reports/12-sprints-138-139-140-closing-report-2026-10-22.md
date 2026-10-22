# Closing report — Sprints 138 (no traces), 139 (API-contract alignment), 140 (guest checkout)

**Date:** 2026-10-22 · **Branches:** `feature/graphql-comply` in stripe-wallet, mollie-payment, paypal-payment, payment-base, oxid-fe-core, oxid-fe-user, hydroxid-project (all pushed, CI green at their heads) · **Rules:** [`../../_engineering_requirements.md`](../../_engineering_requirements.md) · **Spec 138h:** [`../done/sprint-138h-docs-and-report.md`](../done/sprint-138h-docs-and-report.md)

## Subsprints and their proof

| Subsprint | Title                                              | Proof                                                                                              |
| --------- | -------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| 138a      | Purge, rotate, gitleaks everywhere                 | [05](05-sprint-138a-proof-2026-10-22.md) — rotation checklist and history list for your decision   |
| 138b      | Contract token hashed at rest + guard allow-list   | [03](03-sprint-138b-proof-2026-10-22.md)                                                           |
| 138c      | No identifiers in URLs                             | [10](10-sprints-138c-139a-proof-2026-10-22.md)                                                     |
| 138d      | Logs without secrets                               | [09](09-sprints-138d-139b-proof-2026-10-22.md)                                                     |
| 138e      | Browser keeps only what it needs                   | [08](08-sprints-138e-138f-139c-proof-2026-10-22.md)                                                |
| 138f      | Errors by code                                     | [08](08-sprints-138e-138f-139c-proof-2026-10-22.md)                                                |
| 138g      | PayPal webhook signature verification              | [06](06-sprint-138g-proof-2026-10-22.md)                                                           |
| 138h      | Docs and closing report                            | this report; H1 user `6afada2`, H2 stripe `18d7cbb`, H3 user `6afada2` + hydroxid `d86e5cd`         |
| 139a      | Schema cache-clear in docs and ops                 | [10](10-sprints-138c-139a-proof-2026-10-22.md) — stale cache reproduced on the SDK shop            |
| 139b      | Language and shop via `?lang=` / `?shp=`           | [09](09-sprints-138d-139b-proof-2026-10-22.md)                                                     |
| 139c      | `paymentContract` on the storefront `Order`        | [08](08-sprints-138e-138f-139c-proof-2026-10-22.md)                                                |
| 139d      | Developer guide in the documentation's terms       | [07](07-sprint-139d-and-stripe-parts-proof-2026-10-22.md)                                          |
| 140a      | Backend: anonymous rights and the guest user       | [04](04-sprint-140a-proof-2026-10-22.md) — decision variant (a)                                    |
| 140b      | Modules open to guests, one shared input           | [11](11-sprints-140b-140c-proof-2026-10-22.md)                                                     |
| 140c      | Frontend: checkout without login                   | [11](11-sprints-140b-140c-proof-2026-10-22.md)                                                     |

## Test counts (before → after, this branch)

| Repo          | Unit         | Integration / e2e                                   |
| ------------- | ------------ | --------------------------------------------------- |
| payment-base  | 1603 → 1690  | 148 → 163                                           |
| stripe        | 1687 → 1695  | 103 → 118                                           |
| mollie        | — → 817      | 46 → 47 (shop bootstrap, as CI)                     |
| paypal        | 578 → 605    | CI: 20 (6 GraphQL tests skipped, graphql-base off)  |
| oxid-fe-core  | 147 → 226    | —                                                   |
| oxid-fe-user  | 165 → 286    | e2e +25 (151 passed in the full run; guest 9/9)     |
| hydroxid      | 57 → 62      | e2e 29 passed (vibrant), guest form on 5 projects   |

## What the headless channel leaves behind now (sprint 138 goal)

Keys sealed and never secret (unchanged); contract token hashed at rest; no identifiers of ours in URLs and the frontend drops query strings; shop log carries origins and fixed messages, provider text at debug; frontends' nginx logs no query string; browser keeps JWT + refresh token and the attempt only until its outcome or logout, logout revokes server-side; screen shows messages by code; tree and history secret scans green in all seven repos. Customer data stays plaintext inside TLS, by decision.

## Your steps (in order)

1. **Rotate** per report 05 §A3 (Mollie sandbox key, the admin login of the local shop, the pay1 test user and the two other test customers, the stripe `TEST_USER_PASSWORD` GitHub secret); decide the history rewrite per report 05 §A4.
2. **Merge** `feature/graphql-comply` no-ff, in dependency order: payment-base (then tag **1.5.0**: the modules' `composer.json` constraints need `>=1.5` and the CI pins `PAYMENT_BASE_BRANCH`/`PAYMENT_BASE_VERSION_ALIAS` go back to `b-7.4.x`), then stripe (its branch was cut from `docs/graphql-docs-review`, merge that first), mollie, paypal; oxid-fe-core (then oxid-fe-user: remove the TEMPORARY `ref:` in `.github/workflows/ci.yml`), then hydroxid (`package.json` pins back to `#main`, re-lock).
3. **pay1 update** per hydroxid `docs/ci.md`: composer update → re-activate modules → payment-base `bin/run-migrations.sh` (hashes stored tokens; without it open attempts fail with `invalid_token`) → `oe:graphql:cache-clear` → smoke introspection; set `zend.exception_ignore_args=On`; the host nginx logs without the query string; verify the `/graphql/` rewrite; PayPal: the webhook id is now mandatory (verification).
4. **PayPal proofs on a shop that serves the branch:** a genuine sandbox webhook delivery (138g G3) and a guest checkout (140b B4 / 140c C1); the SDK shop here serves `source/extensions/paypal` (`feature/iframe-fields`), which also still holds the two login fallbacks the scanner fingerprinted.

## Open items carried out of the sprints

- **Delivery address on guest and headless orders** (payment-base): `UserBasketProvider` ignores the basket's `OEGQL_DELADDRESSID`; orders ship to the invoice address. Own story.
- Guest accounts: no `oxregister` (`customer { orders }` empty for them), no cleanup, no rate limit on anonymous starts.
- stripe: warning-level log lines in `CheckoutReturnService::validateReturn` carry `session_id`/`contract_id`; `StripeOrderController` PHPMD TooManyMethods and the gate's PHPUnit autoload step are pre-existing red; `composer phpstan/phpmd` fail on deleted files in the diff.
- paypal: `tests/phpcs.xml` excludes `*/var/*` (checks nothing inside the container); GraphQL behaviour not exercised in CI (graphql-base not activated there); provider-text hygiene on return/cancel paths not audited.
- core: the rejected-token handler drops the session without trying a refresh first; no `visibilitychange` listener; `sJsonWebTokenLifetime` not shortened (shop config).
- user: zod validation messages in the logged-in checkout still English; header overflow by 4 px at 360 px with a cart item (pre-existing).
- A cross-provider schema test with all three modules registered together is missing (the live SDK schema covers stripe + mollie).
- Local SDK database holds guest users, cancelled early orders and `proof140b*` baskets from the live proofs; local gitignored `.env` files keep the old credentials until rotation.
- Environment: a Vite dev server now runs inside the `strpwt7-nov26-oxid-fe-user-1` container on 5173 (it was idle); two of your other sessions were working in `hydroxid-project` on this branch during the sprint.
