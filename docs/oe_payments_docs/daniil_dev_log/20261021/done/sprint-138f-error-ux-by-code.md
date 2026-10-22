# Subsprint 138f — Errors shown by code, never by raw message

**Repo:** oxid-fe-user · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md) · **After:** 138d (fixed error shapes from the modules)

## Findings addressed (audit §4)

`LoginView`, `ResetPasswordView`, the dashboard views (`PasswordView`, `ReviewsView`, `DeleteAccountView`,
`EmailForm`, `NewsletterView`, `AddressesView`), `CheckoutView` (`messageOf()`) and the inline payment components
render `error.message` verbatim: provider text, internal URLs, graphql-base wording reach the shopper.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                       |
| -- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| F1 | core: `errorCodeOf(error)` (+ `providerCodeOf`) helpers on `GraphqlError`; unit tests for the known codes (`basket_not_found`, `payment_not_supported`, `terms_not_confirmed`, `return_url_rejected`, `provider_failed`, `contract_not_found`, `invalid_token`, `invalid_login`, `network_error`, `http_error`). |
| F2 | user: one `errors.<code>` i18n group (en/de) with a generic fallback; a `useErrorMessage(error)` composable returns the translated text and never the raw message. Unit test: an unknown code maps to the generic text; the raw message is not in the output. |
| F3 | Replace every `error.message` render in the views listed above with the composable; `CheckoutView`'s `checkout.errors.start/basket` take the mapped text. A unit test per view (mount with a failing client) asserts no raw message in the DOM. |
| F4 | Developer aid: the raw message and code go to a collapsible "details" block only when the instance config sets `debug.errors: true` (default off); e2e proves it is absent by default.                                                    |

## Acceptance

With a misconfigured return origin, a Stripe decline, an expired token and a network failure, the shopper
sees translated, specific messages and never a URL, provider text or English wording from the shop.

## Out of scope

Rewording graphql-storefront's own validation messages (shown through the same mapping with a generic text).
