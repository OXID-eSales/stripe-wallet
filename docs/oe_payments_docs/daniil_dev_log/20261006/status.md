# Status — dev_log 20261006 · GRAPH-QL Stripe provider story (P-Stripe)

**Branch:** `b-7.4.x-GRAPH-QL` (stripe), on payment-base `b-7.4.x-GRAPH-QL` (Sprint 15 done).
**Sprint:** [sprints/GRAPH-QL-stripe-provider-story.md](sprints/GRAPH-QL-stripe-provider-story.md)
**Ritual per story:** this file updated · report in `done/` · sound played.

| Story | State | Notes |
|---|---|---|
| PS1 Headless-ready handler | **DONE** 2026-10-06 | [done/GRAPH-QL-PS1-headless-ready-handler.md](done/GRAPH-QL-PS1-headless-ready-handler.md) — Unit 1593, Integration 100, gates green |
| PS2 Wiring + catalog | IN PROGRESS | resolver tag + open-attempt finder wired; catalog test red |
| PS3 Webhooks commit + cleanup | TODO | |
| PS4 GraphQL mutations | TODO | |
| PS5 ACP service | TODO | |
| PS6 Proof | TODO | |

## How to run (dev shop)

- Unit (standalone): `docker compose exec -T php php extensions/stripe/vendor/bin/phpunit -c extensions/stripe/tests/phpunit-unit.xml`
- Integration (shop PHPUnit): `docker compose exec -T php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration`
- Gates: `cd extensions/stripe && composer phpcs && composer phpstan && composer phpmd` (inside the PHP container)
