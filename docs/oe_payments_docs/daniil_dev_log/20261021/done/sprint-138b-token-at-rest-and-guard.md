# Subsprint 138b — Contract token hashed at rest; client-config guard as an allow-list

**Repo:** payment-base · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Findings addressed (audit §1)

- `HeadlessCheckoutService::newToken()` stores the 128-bit `contractToken` in plaintext under
  `oe_payments_contract.OXMETADATA.headless_token`; a database read reuses it for return/cancel.
- `ClientConfigGuard` denies `sk_`/`rk_`/`whsec_` and `*secret*`/`apikey` names only; Mollie `live_/test_`
  shapes or a PayPal secret under a harmless name would pass.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                                                                      |
| -- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| B1 | `testAuthorisedContract_PlaintextTokenInMetadata_IsRefused` and `testStart_StoresOnlyTheHash`: a new value object `HeadlessTokenHash` (readonly) with `fromToken()` / `matches(token)` (sha256 + `hash_equals`); `start()` stores the hash, `authorisedContract()` compares hashes. SRP: hashing lives in the value object, not in the service. |
| B2 | Migration: existing plaintext `headless_token` values are hashed in place (one-off command or migration data script); test with a fixture row. Open attempts keep working because the client still holds the plaintext token. |
| B3 | Guard: `ClientConfigGuard` takes an allow-list map `provider → [names]` (`stripe: publishableKey`, `paypal: clientId`, `mollie: profileId, testMode`) from the providers themselves (`ClientConfigProviderInterface::publicValueNames()`, LSP-safe default: empty), keeps the deny shapes and adds Mollie `(live|test)_[A-Za-z0-9]{30}` and `access_`. Negative tests per shape; the registry test proves an unknown name is refused even when its value looks harmless. |
| B4 | payment-base 1.4.x CHANGELOG; `docs/graphql-headless-checkout.md` notes the hash; CI green (7.4/7.5/7.6, gitleaks).                                                                                                                                                                       |

## Acceptance

No plaintext `headless_token` in the database after the migration; return and cancel with the token the
client received still succeed; the guard refuses every shape in the negative tests; PHPStan level max, no
baseline entries added.

## Out of scope

Rotating the token per request; encrypting other metadata; the Twig contract-token HMAC (different mechanism).
