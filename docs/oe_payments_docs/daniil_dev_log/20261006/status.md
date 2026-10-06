# Status — dev_log 20261006 · GRAPH-QL Stripe provider story (P-Stripe)

**Branch:** `b-7.4.x-GRAPH-QL` (stripe), on payment-base `b-7.4.x-GRAPH-QL` (Sprint 15 done).

**P-Stripe is DONE (PS1–PS6).** CI: Stripe's workflows install payment-base from `b-7.4.x-GRAPH-QL` (TEMPORARY, `f164517`); payment-base's Actions had to be green first (S6 follow-up `9ebf3a3`).
**Sprint:** [sprints/GRAPH-QL-stripe-provider-story.md](sprints/GRAPH-QL-stripe-provider-story.md)
**Ritual per story:** this file updated · report in `done/` · sound played.

| Story | State | Notes |
|---|---|---|
| PS1 Headless-ready handler | **DONE** 2026-10-06 | [done/GRAPH-QL-PS1-headless-ready-handler.md](done/GRAPH-QL-PS1-headless-ready-handler.md) — Unit 1593, Integration 100, gates green |
| PS2 Wiring + catalog | **DONE** 2026-10-06 | [done/GRAPH-QL-PS2-wiring.md](done/GRAPH-QL-PS2-wiring.md) — resolver tag, open-attempt finder, `checkout.session.completed` |
| PS3 Webhooks commit + cleanup | **DONE** 2026-10-06 | [done/GRAPH-QL-PS3-webhooks-commit-and-cleanup.md](done/GRAPH-QL-PS3-webhooks-commit-and-cleanup.md) — Unit 1607, Integration 100, gates green; cleanup asks Stripe first |
| PS4 GraphQL mutations | **DONE** 2026-10-06 | [done/GRAPH-QL-PS4-graphql-mutations.md](done/GRAPH-QL-PS4-graphql-mutations.md) — Unit 1615, Integration 102 (GraphQLite schema proof), gates green |
| PS5 ACP service | **DONE** 2026-10-06 | [done/GRAPH-QL-PS5-acp-checkout-service.md](done/GRAPH-QL-PS5-acp-checkout-service.md) — Unit 1622, Integration 102, gates green; MCP transport is a separate feature |
| PS6 Proof | **DONE** 2026-10-06 | [done/GRAPH-QL-PS6-proof.md](done/GRAPH-QL-PS6-proof.md) — Playwright 4/4 through the GraphQL mutations + Stripe's hosted page; found and fixed: cancel state, bot UA, Card accordion, **module activation without graphql-base** (payment-base CI red since S6) |

## How to run (dev shop)

- Unit (standalone): `docker compose exec -T php php extensions/stripe/vendor/bin/phpunit -c extensions/stripe/tests/phpunit-unit.xml`
- Integration (shop PHPUnit): `docker compose exec -T php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration`
- Gates: `cd extensions/stripe && composer phpcs && composer phpstan && composer phpmd` (inside the PHP container)
