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

## 2026-10-22 — implementation on `feature/graphql-comply` (all seven repos)

- Branches cut and pushed: stripe (from `docs/graphql-docs-review`, so merge that first), mollie, payment-base, paypal (clone at `source/extensions/paypal-comply`, your `feature/iframe-fields` checkout untouched), oxid-fe-core, oxid-fe-user, hydroxid.
- **Done:** 138a (secrets purged, gitleaks in five repos; rotation checklist and history list in `reports/05`), 138b (token hashed at rest + guard allow-list; `reports/03`), 140a (anonymous rights + guest user, decision variant (a); `reports/04`).
- Done in part: oxid-fe-core 0.10.0 carries 139b B1, 138f F1, 138e E1/E2, 140c C2 (226 unit tests, CI green); payment-base carries 138d D1 and 139c C1–C3/C5.
- **Done:** 138g (PayPal webhook signature verification; `reports/06`; the live sandbox delivery proof waits for a shop serving the branch). paypal also carries its parts of 138b/138c/138d/139a/140b, CI green at `7ca28a4`.
- **Done:** 139d (developer guide in the documentation's terms; `reports/07`). stripe also carries its parts of 138b/138d/139a/140b, CI green at `ba66286`; live anonymous guest start proven (order 1314, cancelled). Findings: `zend.exception_ignore_args=0` leaks query text into the shop error log via graphql-base traces (host fix); guest orders ship to the invoice address (140a gap, confirmed).
- In progress: mollie and oxid-fe-user; then the shared guest input in payment-base (DRY, 140b), hydroxid, 139a A1 reproduction, 138h.
