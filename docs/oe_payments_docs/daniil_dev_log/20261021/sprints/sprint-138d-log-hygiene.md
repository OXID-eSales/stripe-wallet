# Subsprint 138d — Logs without secrets

**Repos:** payment-base, stripe, paypal, oxid-fe-user, hydroxid-project · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Findings addressed (audit §3, §2)

- graphql-base logs every GraphQL error message; ours therefore put the **full rejected return URL**
  (`ReturnUrlRejectedException`) and raw provider text (`Failed to create Stripe checkout session: …`,
  `PayPal payment processing failed: …`) into `oxideshop.log`.
- nginx of both frontends uses the default `combined` format: query strings (`session_id`, `token`,
  `PayerID`, `contract_id`, `uid`) are written to the access log.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                                 |
| -- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1 | payment-base: `testMessage_RejectedUrl_ContainsOriginOnly` — `ReturnUrlRejectedException` message = `Return URL rejected (<reason>): <scheme://host[:port]>`; the full URL moves to an exception getter used only by debug logging. |
| D2 | stripe, paypal: provider failures surface as `provider_failed` + `providerCode` with a fixed message; the provider's text goes to the logger at `debug` with the contract id, never into the exception message. Tests on `StripeCheckoutError` / `PayPalCheckoutError` message shape. |
| D3 | Frontends' nginx: an explicit `log_format headless '$remote_addr - [$time_local] "$request_method $uri $server_protocol" $status $body_bytes_sent'` (no `$request`, no query string) in `oxid-fe-user/nginx/default.conf.template` and hydroxid `scripts/render-nginx.mjs`; a unit test renders the config and asserts `$uri` is used and `$request` is not. |
| D4 | Proof: a full sandbox checkout, then `grep` of the shop log and the nginx access log for `session_id`, `PayerID`, `contract_id`, `uid`, `cs_`, `https://` in error lines — zero hits; attached to the report.                      |

## Acceptance

A misconfigured return origin, a provider failure and a normal checkout leave no URL, token or provider text
in `error`-level shop logs and no query string in the frontends' access logs.

## Out of scope

Changing graphql-base's error logging (vendor); pay1's host-level nginx (outside the repos — note it in the
report for the host owner).
