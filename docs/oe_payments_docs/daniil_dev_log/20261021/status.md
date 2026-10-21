# 2026-10-21

- Read the OXID GraphQL API documentation (13.0-en, local clone `oxapi-documentation`) in full; memory updated.
- Review of stripe / mollie / payment-base / oxid-fe-core / oxid-fe-user / hydroxid against it:
  `reports/01-graphql-api-docs-review-2026-10-21.md`. Three updates needed (schema cache-clear in docs and
  ops, `?lang=`/`?shp=` instead of relying on `Accept-Language`, guest checkout per the documented
  express pattern), two recommendations (refresh tokens, `@ExtendType` on `Order`).
- stripe `b-7.4.x` = `f8113eb` (v3.5.0-rc1) carries the headless/GraphQL docs merged earlier today.
