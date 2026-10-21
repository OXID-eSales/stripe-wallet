# Subsprint 138h — Docs and closing report

**Repos:** stripe, oxid-fe-user · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md) · **After:** all other subsprints

## Stories

| #  | Story                                                                                                                                                                                                                        |
| -- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| H1 | `oxid-fe-user/docs/payments-keys.md`: a "Traces" section stating what is and is not left where (keys sealed; PII plaintext inside TLS; token hashed at rest; no ids in URLs; logs without secrets; browser storage policy). |
| H2 | stripe `docs/for_developer/05-headless-graphql-checkout.md` §7 (security model): hashed contract token, log policy, error codes as the UI contract; `docs/for_merchant/02-headless-storefronts.md`: nothing to configure, but the webhook id for PayPal. |
| H3 | Developer note (both frontends' `docs/quality-gates.md`): Playwright `retain-on-failure` traces keep bearer tokens and passwords on disk — delete `test-results/` after failed runs on shared machines; never upload them from oxid-fe-user CI. |
| H4 | Closing report in `reports/`: per subsprint the proof links (gitleaks output, test counts, gate output, sandbox runs), the rotation log (dates, no values), the history-rewrite list, and what remains open.                 |

## Acceptance

Every subsprint's proof is linked from one report; the docs describe the system as it now is.
