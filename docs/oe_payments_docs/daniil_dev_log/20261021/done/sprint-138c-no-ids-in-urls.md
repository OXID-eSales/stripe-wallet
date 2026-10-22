# Subsprint 138c — No identifiers or credentials in URLs

**Repos:** mollie-payment, paypal, oxid-fe-user · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md) · **After:** 138b released and pinned

## Findings addressed (audit §2)

- Mollie headless return URL carries `contract_id` (`MollieCheckoutSessionHandler::redirectUrlFor()`); PayPal's
  carries `contract_id` and `cancelled=1` (`CheckoutSessionService`); Stripe's carries only `session_id`.
- Frontend keeps query strings after use: `/checkout/return/<provider>?…`, `/reset-password?uid=<key>`; they
  stay in browser history and in default nginx access logs (138d handles the log side).

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                                  |
| -- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| C1 | mollie: `testRedirectUrlFor_HeadlessRequest_AppendsNothing` — the headless return URL is the client's URL unchanged; the Twig URL keeps its own parameters. The return resolver reads the contract from the client's `contractId` argument only. |
| C2 | paypal: same for `CheckoutSessionService` (no `contract_id`, no `cancelled=1`; cancel = the client's cancelUrl or the returnUrl unchanged); the headless return path reads nothing from the URL. |
| C3 | oxid-fe-user: `ReturnView`/`CancelView` read their params, call the mutation, then `history.replaceState` to the bare route; `ResetPasswordView` does the same with `uid` after storing it in component state. Playwright: after each flow `location.search === ''`; a reload of the return page shows the result from the stored attempt, not from the URL. |
| C4 | Proof: Playwright traces of Stripe, Mollie and PayPal returns with the final URLs; module CI green.                                                                                                                                                      |

## Acceptance

No module appends anything but what the provider requires (`session_id` for Stripe; PayPal's own
`token`/`PayerID` are theirs) to a headless return URL; the browser never keeps a query string after
the page has used it.

## Out of scope

PayPal's and Stripe's own parameters (not ours to remove); the Twig flows' URLs (own ticket: `force_sid`
and `contract_token` in Stripe's and Mollie's Twig success URLs).
