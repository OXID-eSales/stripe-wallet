# Sprint 138 — No traces: secrets and personal data in the headless channel

**Date:** 2026-10-21 · **Based on:** [`../reports/02-sensitive-data-traces-audit-2026-10-21.md`](../reports/02-sensitive-data-traces-audit-2026-10-21.md)
**Rules for every subsprint:** [`_engineering_requirements.md`](../../_engineering_requirements.md) — TDD-first, SOLID, DRY, Clean Code, no overengineering, pre-commit guard green, proof in `reports/`, git flow (feature branch per subsprint, rebase only, no-ff merges, no PRs, no worktrees).

## Goal

After this sprint, no API key, credential or personal datum of the headless flow is left where it does
not have to be: not in git, not in logs, not in URLs, not in the database in a reusable form, not on
screen; the browser keeps only what the next request needs.

## Decision frame (settled before 138c and 138f start)

- Customer data stays plaintext **inside TLS**, the OXID GraphQL API's own model; no application-layer
  encryption of PII in this sprint.
- Provider public ids (`pk_…`, PayPal client id, `pfl_…`) are public in the providers' model; the sealed
  exchange keeps them out of repos, builds and logs, nothing more.
- Git history of the public ids is **not** rewritten by this sprint (your decision; 138a lists the commits).

## Subsprints

| #    | File                                                                                   | Repos                                   | Order / dependency                     |
| ---- | -------------------------------------------------------------------------------------- | --------------------------------------- | -------------------------------------- |
| 138a | [Purge, rotate, gitleaks everywhere](../done/sprint-138a-purge-rotate-gitleaks.md)             | mollie, stripe, paypal, oxid-fe-user, hydroxid | first; independent                 |
| 138b | [Contract token hashed at rest + guard allow-list](../done/sprint-138b-token-at-rest-and-guard.md) | payment-base                         | independent                            |
| 138c | [No identifiers in URLs](sprint-138c-no-ids-in-urls.md)                                | mollie, paypal, oxid-fe-user            | after 138b is released (pins)          |
| 138d | [Logs without secrets](../done/sprint-138d-log-hygiene.md)                                     | payment-base, stripe, paypal, oxid-fe-user, hydroxid | independent              |
| 138e | [Browser keeps only what it needs](../done/sprint-138e-browser-storage-and-logout.md)          | oxid-fe-core, oxid-fe-user              | independent                            |
| 138f | [Error UX by code](../done/sprint-138f-error-ux-by-code.md)                                    | oxid-fe-user                            | after 138d (error shapes)              |
| 138g | [PayPal webhook signature verification](../done/sprint-138g-paypal-webhook-signature.md)       | paypal                                  | independent                            |
| 138h | [Docs and closing report](sprint-138h-docs-and-report.md)                              | stripe, oxid-fe-user                    | last                                   |

## Sprint acceptance

Every subsprint's acceptance met; gates green in all seven repos; e2e green; the sealed-key tests
unchanged; the closing report (138h) links each subsprint's proof.
