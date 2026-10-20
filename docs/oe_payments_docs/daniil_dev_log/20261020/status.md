# 2026-10-20

- Report: `reports/01-pay1-log-errors-2026-10-20.md` — sources of 4 pay1 log errors (stale container, captured amount never set, wishlist not-found noise, graphql-base null query).
- Sprint: `sprints/sprint-137-pay1-log-errors.md` — 4 stories (deploy fix + schema spec, webhook captured amount, backfill command, fe-core savedLists); graphql-base #4 out of scope (upstream). Daniil deploys pay1 personally.
- Sprint 137 implemented (uncommitted): `done/sprint-137-implemented.md`. Also fixed in payment-base: `issuedAt` was published as `suedAt`.
- Sprint for the OXID GraphQL team: `sprints/GQL-BASE-missing-query-500.md` — missing/non-string query → HTTP 500; reproduced locally (4 request shapes), fix = `InvalidRequest` guard in `GraphQLQueryHandler`.
