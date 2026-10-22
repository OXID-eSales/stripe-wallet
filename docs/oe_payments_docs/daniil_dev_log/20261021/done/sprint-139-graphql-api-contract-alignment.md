# Sprint 139 — Align the headless stack with the OXID GraphQL API contract

**Date:** 2026-10-21 · **Based on:** [`../reports/01-graphql-api-docs-review-2026-10-21.md`](../reports/01-graphql-api-docs-review-2026-10-21.md) (findings 1, 2, 5, 12–14)
**Rules for every subsprint:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Goal

What the OXID GraphQL API documentation (13.0) defines differently from what we built is corrected:
the schema cache is part of every install/update procedure, language and shop are selected the
documented way, the payment contract is visible on the storefront `Order` type, and our developer
guide speaks the documentation's vocabulary.

## Subsprints

| #    | File                                                                              | Repos                                            | Order                        |
| ---- | --------------------------------------------------------------------------------- | ------------------------------------------------ | ---------------------------- |
| 139a | [Schema cache clear in docs and ops](sprint-139a-schema-cache-clear.md)           | stripe, payment-base, mollie-payment, hydroxid   | first; docs only             |
| 139b | [Language and shop via `?lang=` / `?shp=`](sprint-139b-lang-shp-parameters.md) | oxid-fe-core, oxid-fe-user, hydroxid             | independent                  |
| 139c | [`paymentContract` on the storefront `Order`](sprint-139c-extend-order-type.md) | payment-base, oxid-fe-user (dashboard)           | independent                  |
| 139d | [Developer guide in the documentation's terms](sprint-139d-docs-vocabulary.md)    | stripe                                           | after 139a                   |

**Finding 5 (refresh tokens)** is implemented by subsprint 138e, story E2; it is not repeated here.
**Finding 4 (guest checkout)** is [Sprint 140](sprint-140-guest-checkout.md).

## Not verified before this sprint (do first, in 139a)

pay1's rewrite for `/graphql/` (`QSA`, `skipSession=1`) and whether yesterday's missing
`paymentClientConfig` on pay1 was a stale schema cache.

## Sprint acceptance

All subsprints accepted; a fresh pay1 update following the written procedure exposes the full schema
without manual intervention; the frontend addresses language and shop by id; the gates are green.
