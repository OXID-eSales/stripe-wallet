# Audit: do API keys and customer data leave traces in the headless (GraphQL) channel?

**Date:** 2026-10-21 · **Scope:** stripe `b-7.4.x`, mollie-payment `b-7.4.x`, paypal `main`, payment-base
`b-7.4.x`, one-page-checkout `b-7.4.x`, oxid-fe-core `main` (0.9.2), oxid-fe-user `main` (0.6.1),
hydroxid-project `main`, the shop's graphql-base/-storefront `dev-b-7.4.x`, the SDK and pay1 nginx/Apache
configuration as far as it is in the repos. Read-only; no values of any secret are reproduced here, only
file paths.

## Answer in short

**Keys: yes, encrypted.** The only provider values that ever go to a headless frontend are the public
halves (Stripe publishable key, PayPal client id, Mollie profile id + test mode), and they go **sealed**
(ECDH P-256 + HKDF-SHA256 + AES-256-GCM, bound to the session token, 120 s replay window, memory-only in
the browser). No secret key, OAuth token, webhook secret or client secret has a path to a client; the
guard refuses secret-shaped values.

**Customer data: no, plaintext inside TLS — as the OXID GraphQL API itself does.** Names, addresses,
e-mail, phone, orders travel as ordinary JSON in HTTPS request/response bodies, never in URLs. That is
the storefront's contract; the modules add no PII field of their own. There is no application-layer
encryption of PII, and the documentation does not ask for one.

**Traces: several, and they are the real findings.** A credential (`contractToken`) in plaintext in the
database and in `sessionStorage`; the customer's e-mail inside the JWT in `localStorage` for 8 hours with
no server-side revocation on logout; query strings (`session_id`, PayPal `token`/`PayerID`, password-reset
`uid`) in default nginx access logs; the full rejected return URL and raw provider error text in the shop
log; raw error messages on screen; and **committed, real-looking secrets** in dev-log files of three
repositories plus provider public keys still in pushed git history. One finding is outside "traces" but
serious: **PayPal webhooks are not signature-verified on `main`.**

## 1. What leaves the shop over GraphQL

| Data | Where | Form | Trace risk |
| --- | --- | --- | --- |
| Stripe publishable key, PayPal client id, Mollie profile id/test mode | `paymentClientConfig` (payment-base `ClientConfigSealer`; providers `StripeClientConfigProvider`, `PayPalClientConfigProvider`, `MollieClientConfigProvider`) | **sealed** envelope; unsealed value only in JS memory (`oxid-fe-core/src/payments/sealedKeys.ts`) | none on the wire or in storage. PayPal's client id is afterwards part of the `https://www.paypal.com/sdk/js?client-id=…` script URL (DOM, paypal.com logs) — public by design |
| Secret keys, webhook secrets, OAuth tokens, PayPal client secret | — | **never leave the shop**; `ClientConfigGuard` refuses `sk_`/`rk_`/`whsec_` and `*secret*`/`apikey` names | guard gap: Mollie `live_…`/`test_…` key shapes and a PayPal secret under a harmless name would pass (no provider publishes such a value today) |
| `contractToken` (bearer credential for return/cancel) | `CheckoutStartResult.contractToken` | plaintext (random 128-bit) in the response; kept in `sessionStorage` `oxfe_checkout_attempt` | **stored unhashed** in `oe_payments_contract.OXMETADATA.headless_token`; sessionStorage entry not cleared on failed/pending return or logout |
| Stripe `clientSecret` (Checkout Session) | `CheckoutStartResult.clientSecret` (embedded mode) | plaintext, opaque, short-lived, not persisted by the shop | low; a committed dev-log run log contains one (see §5) |
| Provider redirect URLs (Stripe hosted URL, PayPal approval URL incl. its order token, Mollie checkoutUrl) | `CheckoutStartResult.redirectUrl` | plaintext, opaque | persisted in `OXPROVIDERDATA.redirectUrl` (all three) |
| Customer PII (e-mail, names, addresses, phone, birthdate, vatID), orders | graphql-storefront `customer`, `customerInvoiceAddress`, `orders`… | plaintext JSON over TLS | standard; not cached (`cachePolicy.spec` enforces), not logged, not in URLs |
| JWT (`Authorization: Bearer`) | every request | signed (HS512), **not encrypted**: claims `username` = customer e-mail, `userid`, `tokenid`, `shopid`, `useranonymous`, `fingerprintHash`; lifetime 8 h (`sJsonWebTokenLifetime`) | in `localStorage` `oxfe.token`; `AuthService.logout()` clears storage only — graphql-base offers `tokenDelete(tokenId)` / `customerTokensDelete`, unused |
| Card data | — | never through the shop: Stripe Embedded/Hosted Checkout and Mollie Components tokenise in the browser; `cardToken` is forwarded to Mollie, not stored, not logged | none |

## 2. URLs (access logs, browser history, Referer)

| Flow | What the provider/shop appends to the frontend's return URL | Note |
| --- | --- | --- |
| Stripe headless | `session_id={CHECKOUT_SESSION_ID}` only (`StripePaymentHandler::headlessUrls()`) | no contract ids |
| Mollie headless | `contract_id` (`MollieCheckoutSessionHandler::redirectUrlFor()`) | an opaque id, not a credential; still a trace |
| PayPal headless | `contract_id`, cancel adds `cancelled=1`; PayPal itself adds `token=<order>&PayerID=` | same |
| Twig/OPC (not headless) | Stripe: `session_id`, `contract_id`, `contract_token` (HMAC), **`force_sid` = the OXID session id**; Mollie: `contract_id`, `contract_token` (HMAC) | the Twig flow puts credentials in URLs; out of headless scope but worth a ticket |
| Frontend | `/reset-password?uid=<reset key>` (`ResetPasswordView.vue`), `/login?redirect=…` | reset key in URL |

Frontend transport is clean: every GraphQL call is `POST` with a JSON body (`GraphqlClient.send()`),
no `GET`; `contractId`/`contractToken` never enter a URL or route; `Authorization` only goes to the
same-origin `/graphql/` proxy. **nginx** (`oxid-fe-user/nginx/default.conf.template`,
`hydroxid-project/scripts/render-nginx.mjs`) sets no `log_format`, so the default `combined` format
records the query strings above in the access log. Request bodies and the `Authorization` header are not
logged.

## 3. Logs on the shop side

- graphql-base logs **every** GraphQL error message (`GraphQLQueryHandler.php:100`). Consequences:
  `Return URL rejected (<reason>): <full URL>` (payment-base `ReturnUrlRejectedException`), raw Stripe
  error text (`Failed to create Stripe checkout session: …`), raw PayPal text, land in `oxideshop.log`.
- payment-base, stripe, mollie, paypal: no logger call writes keys, client secrets, `contractToken`,
  card data or request bodies. Stripe's `RequestLogService` logs ids/amounts/status; its
  `WebhookLogService` logs metadata only (`payload_bytes`, `has_signature`). Two stack-trace logs in
  stripe (`ControllerRequestHelper.php:249`, `StripeCaptureRequestHandler.php:280`). PayPal's redacting
  `RequestLogService` has no caller.
- `oe_payments_webhooklogs.OXPAYLOAD` is written only through `WebhookPayloadSanitizer` (customer
  details, e-mail, name, address, last4, metadata redacted); the unsanitised `logEventReceived()` has no
  caller.
- OPC has no GraphQL surface; its unreachable `OnePageController` methods log `sessionId` and traces.

## 4. Browser side

- `localStorage`: `oxfe.token` (the JWT with the e-mail claim), `oxfe_shop_cart` (products only),
  `oxfe_locale`, hydroxid `hydroxid_cookie_consent`. `sessionStorage`: `oxfe_checkout_attempt`.
  No cookies, IndexedDB, service worker. Sealed keys and the ECDH private key: memory only,
  non-extractable.
- `ResponseCache`: catalog reads only (60 s / 30 s), keyed by token; no customer, basket, checkout or
  payment response is cached (`tests/unit/cachePolicy.spec.ts`).
- No `console.*` in runtime code; no error reporting service; no sourcemaps in builds.
- **Raw error messages are rendered** to shoppers in `LoginView`, `ResetPasswordView`, the dashboard
  views and `CheckoutView` (`messageOf()`), including provider text and, for a misconfigured origin, the
  rejected return URL.

## 5. Repositories and artifacts (paths only)

| Repo | Finding | Severity |
| --- | --- | --- |
| mollie-payment | `docs/dev_day_log/20260923/reports/01-order-shipping-address-from-billing.md:17` — a real-looking **Mollie sandbox API key** together with the **admin login e-mail and password** | **high**: rotate both, remove |
| stripe | `docs/oe_payments_docs/daniil_dev_log/20260918/reports/01-fresh-session-run.log` — a Checkout Session **client_secret** (test mode), an OXID `force_sid`, the dev host | medium: remove |
| paypal (`main`) | `docs/paypal_sdk_docs/sdk_docs.md:79` — an `access_token` value inside a sample response | low–medium: replace with a placeholder |
| oxid-fe-user | `tests/e2e/.env.dist` — `TEST_USER_EMAIL` + `TEST_USER_PASSWORD`, a **working login to the public pay1 demo**; same password as fallback default in `tests/e2e/fixtures/test-data.ts:5`, hydroxid `tests/e2e/live.spec.ts:16`, `tests/e2e/helpers/payments.ts:43` | medium: placeholders + rotate the test user |
| oxid-fe-user, hydroxid-project | Stripe pk, PayPal client id, Mollie profile id removed from the tree in sprint 15 (`5c65b6c`, `767768b`) but **still in pushed history** (`810203b`, `04d8cf8`); truncated prefixes in `docs/dev_log/20261019/sprints/sprint-15-payment-keys-from-backend.md` | low (public ids) — rotate or rewrite is your call |
| oxid-fe-user | local `test-results/*/trace.zip` (gitignored, not committed) hold bearer tokens, a login password and a `contractToken` (`trace: 'retain-on-failure'`) | hygiene on dev machines |
| all | gitleaks CI exists in stripe, payment-base, mollie; **not** in paypal, oxid-fe-user, hydroxid; rules know Stripe/Mollie key shapes, not `cs_…_secret_…`, `force_sid`, `A21AA…` | add |

Good: `.env`, `test-results/`, `playwright-report/` are gitignored and nothing of them is committed;
the public config renderer refuses secret-shaped values and key fields; `noKeysInTheRepo.spec.ts` greps
tracked files in both frontends; YAML is never served (404 rule, deploy smoke test); the deploy workflow
never echoes its keys and ships no `.env`/YAML.

## 6. Webhooks (integrity, not traces)

Stripe: signature verified (`Webhook::constructEvent`). Mollie: unsigned by design, verified by
re-fetching the payment. **PayPal (`main`): `PayPalAdapter::verifyAndParse()` only checks that a webhook
id is configured and the JSON parses — no signature verification** ("wired in sprint 15" per the comment).

## 7. Verdict per repo

| Repo | Keys | PII | Traces to fix |
| --- | --- | --- | --- |
| payment-base | sealed ✓ | none of its own | `headless_token` unhashed; rejected-URL message carries the full URL; guard gap for Mollie/PayPal shapes |
| stripe | sealed ✓ | none | error text with provider message; committed run log with a client secret |
| mollie-payment | sealed ✓ | none | `contract_id` on the return URL; committed API key + admin credentials |
| paypal | sealed ✓ | none | `contract_id` on the return URL; webhook signature; sample access token in docs |
| one-page-checkout | n/a (no GraphQL) | — | — |
| oxid-fe-core | memory-only ✓ | — | JWT in localStorage incl. e-mail, no revoke on logout; no refresh |
| oxid-fe-user | — | not cached ✓ | `oxfe_checkout_attempt` lifetime; raw error messages; nginx query-string logging; `uid` in URL; test credentials; history |
| hydroxid-project | — | — | nginx query-string logging; fallback test password; history |

Remediation plan: `sprints/sprint-138-no-traces-sensitive-data.md`.
