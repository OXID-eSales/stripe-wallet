# Subsprint 138e — The browser keeps only what the next request needs

**Repos:** oxid-fe-core, oxid-fe-user · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Findings addressed (audit §1, §4)

- The JWT (claims incl. the customer's e-mail) lives in `localStorage` `oxfe.token` for 8 h; logout clears
  storage only — graphql-base offers `tokenDelete(tokenId)` and `customerTokensDelete`.
- `sessionStorage` `oxfe_checkout_attempt` (contractId, contractToken, orderNumber) survives a failed or
  pending return and a logout.
- No refresh-token flow; a session dies at access-token expiry.

## Decision to settle in the spec (first story)

Either **(a)** keep the JWT in `localStorage` but shorten `sJsonWebTokenLifetime` and add the
`login`/`refresh` flow (fingerprint cookie works same-origin), or **(b)** move it to `sessionStorage` (gone
with the tab, shoppers log in per tab). Recommendation: (a); it is what the API documentation describes.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                              |
| -- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| E1 | core: `AuthService.logout()` calls `tokenDelete(tokenId)` (claim from the stored JWT) before clearing storage; a failure to revoke still clears locally. Unit test: the mutation is sent with the token id; e2e: a request with the old JWT after logout is refused by the shop. |
| E2 | core: refresh flow — `login` returns accessToken + refreshToken; a scheduler refreshes before `exp`; `refresh` failure drops the session (reuses sprint 14's rejected-token path). Unit tests with fake clocks; e2e with a short lifetime configured on the test shop. |
| E3 | user: `checkout` store clears `oxfe_checkout_attempt` on failed and pending returns (after the pending timeout), on cancel, on logout (`CoreEvents.AuthLogout`). Unit tests on the store; e2e asserts `sessionStorage` after each outcome. |
| E4 | Docs: `oxid-fe-user/docs/architecture.md` "sessions" section (what is stored where and for how long); note that the e-mail claim is graphql-base's.                                                                                              |

## Acceptance

After logout the old JWT is refused server-side and no attempt is left in `sessionStorage`; a token
expires on the configured short lifetime and the session is refreshed silently while the tab is open.

## Out of scope

Changing the JWT claims (graphql-base); encrypting local storage (no key to protect it with).
