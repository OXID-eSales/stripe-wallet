# 2026-10-21

- Read the OXID GraphQL API documentation (13.0-en, local clone `oxapi-documentation`) in full; memory updated.
- Review of stripe / mollie / payment-base / oxid-fe-core / oxid-fe-user / hydroxid against it:
  `reports/01-graphql-api-docs-review-2026-10-21.md`. Three updates needed (schema cache-clear in docs and
  ops, `?lang=`/`?shp=` instead of relying on `Accept-Language`, guest checkout per the documented
  express pattern), two recommendations (refresh tokens, `@ExtendType` on `Order`).
- stripe `b-7.4.x` = `f8113eb` (v3.5.0-rc1) carries the headless/GraphQL docs merged earlier today.
- Audit "do keys and customer data leave traces in the headless channel" across stripe, mollie, paypal, payment-base,
  OPC, oxid-fe-core/-user, hydroxid: `reports/02-sensitive-data-traces-audit-2026-10-21.md`. Keys are sealed and
  never secret; PII is plaintext inside TLS (the API's model); traces found: unhashed `headless_token`, JWT with the
  e-mail claim in localStorage without server-side revoke, query strings in nginx access logs, full rejected URL and
  provider text in the shop log, raw error messages on screen, committed real-looking secrets in three repos' dev
  logs, public keys in pushed history, PayPal webhooks unverified on `main`. Plan: `sprints/sprint-138-no-traces-sensitive-data.md`.
