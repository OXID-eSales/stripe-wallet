# Sprint 138 — No traces: secrets and personal data in the headless channel

**Date:** 2026-10-21 · **Repos:** payment-base, stripe, mollie-payment, paypal, oxid-fe-core, oxid-fe-user,
hydroxid-project · **Based on:** [`../reports/02-sensitive-data-traces-audit-2026-10-21.md`](../reports/02-sensitive-data-traces-audit-2026-10-21.md)
**Binding rules:** the engineering requirements of the frontends (`oxid-fe-user/docs/dev_log/20261019/sprints/_engeneering_requirements.md`) and
payment-base `docs/engineering/shared-requirements.md`: TDD, SOLID, DRY, clean code, no over-engineering, gates green.

## Goal

After this sprint, no API key, credential or personal datum of the headless flow is left where it does
not have to be: not in git, not in logs, not in URLs, not in the database in a reusable form, not on
screen, and the browser keeps only what the next request needs.

## Decision frame (confirm before S3/S5)

- PII stays plaintext **inside TLS** — the OXID GraphQL API's model; this sprint does not add
  application-layer encryption of customer data (that would be a new protocol, not covered by the API
  documentation, and the browser must read the data anyway).
- Provider public ids (`pk_…`, PayPal client id, `pfl_…`) are public in the providers' model; the sealed
  exchange hides them from repos, builds and logs, nothing more.

## Stories (test first)

| #   | Story                                                                                                                                                                                                                                                                                                                                                                                                                           | Repo |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---- |
| S1  | **Purge and rotate.** Remove the committed secrets named in audit §5 (mollie report with API key + admin credentials; stripe run log with a client secret + `force_sid`; paypal SDK sample token); **rotate** the Mollie sandbox key, the admin password and the pay1 test user; replace `.env.dist` credentials and the three fallback passwords with placeholders that make the test skip. Decide on history rewrite for the public ids (not done by this sprint). | all |
| S2  | **gitleaks everywhere, with our shapes.** Add the gitleaks workflow to paypal, oxid-fe-user, hydroxid; extend `.gitleaks.toml` rules: `cs_(test|live)_…_secret_…`, `force_sid=`, PayPal `A21AA…` tokens, Mollie `(live|test)_[A-Za-z0-9]{30}`, admin-credential pairs. Red first on the current trees, green after S1.                                                                                                       | all |
| S3  | **Hash the headless contract token at rest.** payment-base stores `hash('sha256', token)` under `headless_token`; `authorisedContract()` compares hashes with `hash_equals`; a migration re-hashes existing plaintext tokens once (or invalidates open attempts — decide). The token still travels once in `CheckoutStartResult`.                                                                                                    | payment-base |
| S4  | **No credential or identifier in a URL.** Mollie and PayPal headless return URLs drop `contract_id` (the client already holds the attempt, as Stripe's flow proves); frontend return/cancel routes read their params and then `history.replaceState` them away; `ResetPasswordView` does the same with `uid`. e2e: after return, `location.search` is empty; access-log sample shows no ids.                                   | mollie, paypal, oxid-fe-user |
| S5  | **Logs without secrets.** `ReturnUrlRejectedException` message carries the origin only (`Return URL rejected (origin_not_allowed): https://host:port`), the full URL goes to the exception context, not the message; provider error text is wrapped (`provider_failed` + `providerCode`) and the raw text logged at `debug`, not `error`; nginx templates of both frontends get a `log_format` without `$request`'s query string (or `$uri` instead of `$request`). | payment-base, stripe, paypal, oxid-fe-user, hydroxid |
| S6  | **Browser keeps only what it needs.** `oxfe_checkout_attempt` cleared on failed/pending return and on logout; logout calls graphql-base `tokenDelete(tokenId)` before clearing storage (server-side revoke); the JWT moves to `sessionStorage` **or** stays in `localStorage` with the refresh-token flow and a short access-token lifetime (`sJsonWebTokenLifetime`) — decision in the spec; the e-mail claim is graphql-base's, note it in the docs. | oxid-fe-core, oxid-fe-user |
| S7  | **Error UX by code.** Views map `extensions.errorCode` (+ `providerCode`) to i18n strings and never render `error.message` (login, reset, dashboard, checkout, inline components); the raw message is kept for a developer console toggle at most. Unit tests per view.                                                                                                                                                         | oxid-fe-user |
| S8  | **Guard hardening.** `ClientConfigGuard` becomes an allow-list per provider (`stripe: publishableKey`, `paypal: clientId`, `mollie: profileId, testMode`) in addition to the deny shapes; adds Mollie `live_/test_` and PayPal secret shapes to the deny list. Negative tests.                                                                                                                                           | payment-base |
| S9  | **PayPal webhook signature verification** (`PayPalAdapter::verifyAndParse()`): verify with PayPal's `verify-webhook-signature` (or certificate check), reject unverifiable events, test with a recorded event. Outside "traces" but found by the audit; its own ticket if the sprint is too full.                                                                                                                   | paypal |
| S10 | **Docs + report.** `payments-keys.md` threat model gains the "traces" section (what is and is not left where); stripe `05-headless-graphql-checkout.md` §7 lists the hashed token and the log policy; developer note on Playwright traces (`retain-on-failure` keeps bearer tokens on disk — delete `test-results/` after a failed run on shared machines).                                                                   | stripe, oxid-fe-user |

## Acceptance

- `git grep`/gitleaks on every repo's tree and history range of this sprint: zero real secrets; the
  three rotated credentials no longer work.
- `oe_payments_contract.OXMETADATA` holds no plaintext `headless_token`; return/cancel still work with
  the token the client received.
- A failed checkout, a misconfigured return origin and a provider error produce a shop-log line without
  URL or provider text in `error`, and a screen without raw messages.
- After a Stripe/Mollie/PayPal return, the browser URL carries no `contract_id`; nginx access log of a
  full checkout shows no `session_id`, `token`, `PayerID`, `uid` values.
- Logout revokes the token server-side (a subsequent request with the old JWT is refused) and leaves no
  `oxfe_checkout_attempt`.
- Gates green in all seven repos; e2e green; the sealed-key tests unchanged.

## Out of scope

Encrypting PII at the application layer; changing graphql-base's JWT claims; rewriting git history
(your decision; the sprint prepares the list of commits).
