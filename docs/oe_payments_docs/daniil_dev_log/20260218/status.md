# Development Log — 2026-02-18

## Focus: Sprint 56c/d — Fix ACP Checkout Return Flow + Guest User Clone Crash

**Branch:** `b-7.4.x-mcp-STRP-88`
**Previous:** Sprint 56b (Payment ID + Basket Validation) completed 2026-02-17

---

## Context

After Sprint 56b fixed the `create_checkout` tool errors, two remaining issues surfaced during live MCP agent testing:

1. **MCP response format issues** — Empty line item IDs, zero amounts, missing Stripe checkout URL
2. **ACP checkout return flow** — After Stripe payment, OXID's ThankYou page didn't work (missing session data)
3. **New user crash** — Creating checkout with a new email (not in DB) crashed with `__clone method called on non-object`

---

## Sprint Queue

| Sprint | Title | Priority | Status |
|--------|-------|----------|--------|
| 56c | Fix MCP response format (line items, checkout URL) | High | DONE |
| 56d | Fix ACP ThankYou page (basket restoration) | High | DONE |
| 56e | Fix guest user __clone crash (missing OXID fields) | Critical | DONE |

---

## Test Baseline (after Sprint 56e)

- **Unit Tests:** 1114 tests, 2775 assertions
- **PHPCS:** 0 errors
- **PHPStan:** 0 errors (level max)
- **PHPMD:** 0 new violations (4 baselined)

---

## Work Done Today

### 1. Sprint 56c: Fix MCP Response Format (DONE)

Three issues in the MCP `create_checkout` response:

| Issue | Root Cause | Fix |
|-------|-----------|-----|
| **Empty line item IDs** | `AcpResponseFormatter` expected `articleId` but `ContractService` outputs `productId` | Updated formatter to check `productId` first, fall back to `articleId` |
| **Zero line item amounts** | `ContractService` didn't extract per-item `netPrice`/`vatValue`; formatter expected `grossPrice` not `totalPrice` | Updated both: ContractService extracts net/vat per item; formatter handles all field name variants |
| **Missing checkout URL** | `setProvider()` stored shop success URL instead of Stripe checkout URL; formatter didn't include it | Pass `$result->getCheckoutUrl()` to `setProvider()`; add `checkout_url` to response |

**Files modified (4 files across 2 packages):**

| File | Changes |
|------|---------|
| `stripe/.../Handler/StripeCheckoutSessionHandler.php` | Store Stripe checkout URL on contract via `setProvider()`, append `&source=acp` to success URL |
| `payment-component/.../Mcp/Acp/AcpResponseFormatter.php` | Include `checkout_url` in response, fix field name mapping for line items |
| `payment-component/.../Service/ContractService.php` | Extract per-item `netPrice` and `vatValue` from basket items |
| `payment-component/.../Contract/PaymentContractInterface.php` | Add `getProviderRedirectUrl()` to interface |

### 2. Sprint 56d: Fix ACP ThankYou Page (DONE)

**Problem:** After Stripe payment, OXID's ThankYouController redirected to start page because the session basket was empty.

**Root cause:** ThankYouController requires `getProductsCount() > 0` on the session basket. In ACP flow, the basket was created during an MCP API call session. When the user returns from Stripe via `force_sid`, the API session may not properly restore the basket (session expiry, different PHP process, or basket consumed during order creation).

**Fix:** Added `ensureBasketForThankYouPage()` to `StripeOrderController::checkoutSuccess()`. After the event chain creates the order, if the session basket is empty, it rebuilds the basket from the contract's `BasketSnapshot` (always available from DB).

| File | Changes |
|------|---------|
| `stripe/.../Controller/StripeOrderController.php` | +`ensureBasketForThankYouPage()`, +`rebuildBasketFromSnapshot()` — rebuild basket from contract snapshot when session basket is empty |

### 3. Sprint 56e: Fix Guest User __clone Crash (DONE)

**Problem:** Creating checkout with a new email (`daniel@localhost.local`) crashed: `__clone method called on non-object`.

**Root cause:** `AcpContextResolverHandler::createGuestUser()` only initialized 7 fields via `assign()`. OXID's `Order::assignUserInformation()` tries to `clone` ~15 user field objects (`oxcompany`, `oxstreetnr`, `oxsal`, `oxfon`, etc.). Missing fields are `null` in OXID's magic property bag, and `clone null` crashes.

**Why existing users work:** When `User::load()` fetches from DB, ALL fields get initialized as Field objects (even empty ones). New users via `assign()` only initialize provided keys.

**Fix:** Added all fields that `Order::assignUserInformation()` needs to the `$userData` array with empty string defaults. Also mapped `phone_number` from buyer data to `oxfon`.

| File | Changes |
|------|---------|
| `stripe/.../Mcp/Handler/AcpContextResolverHandler.php` | Added `oxsal`, `oxcompany`, `oxstreetnr`, `oxaddinfo`, `oxstateid`, `oxustid`, `oxfon`, `oxfax` to guest user creation |

---

## Open Discussion: ACP User Account Access

**Issue:** Orders created via ACP are linked to user records, but new users have no password and can't log in to see their orders.

**Options discussed:**
- **Option A:** User uses "Forgot Password" flow (works today, bad UX)
- **Option B:** Auto-send "claim your account" email after ACP order (best UX, agent never sees credentials)
- **Option C:** Agent sets initial password (simple but password flows through agent)

**Decision:** TBD — Option B recommended for security.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | SRP, OCP, LSP, ISP, DIP in every class |
| DI | Depend on abstractions, wire via services.yaml |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |
| No Overengineering | Build only what is needed now |

---

## Documents

### Reports
- [01-architecture-audit-report.md](reports/01-architecture-audit-report.md) — Architecture vs implementation audit (7 findings)

### Todo
_(none — Sprint 56c/d/e complete)_

### Done
| Sprint | Document | Completed |
|--------|----------|-----------|
| **56c** | [sprint-56c-fix-response-format.md](done/sprint-56c-fix-response-format.md) | 2026-02-18 |
| **56d** | [sprint-56d-fix-thankyou-page.md](done/sprint-56d-fix-thankyou-page.md) | 2026-02-18 |
| **56e** | [sprint-56e-fix-guest-user-clone.md](done/sprint-56e-fix-guest-user-clone.md) | 2026-02-18 |

---

## Quality Gates

```
PHP CodeSniffer (PSR-12)     -- 0 errors
PHPUnit (Unit)               -- 1114 tests, 2775 assertions — ALL PASS
PHPStan (level max)          -- 0 errors
PHPMD (--strict + baseline)  -- 0 new violations
Status: COMMITABLE
```

---

## Quick Links

- Previous day: [20260217](../20260217/status.md)
- Sprint 56 (User ID fix): [sprint-56-fix-mcp-create-checkout.md](../20260217/done/sprint-56-fix-mcp-create-checkout.md)
- Sprint 56b (Payment ID fix): [sprint-56b-fix-payment-id-basket-validation.md](../20260217/done/sprint-56b-fix-payment-id-basket-validation.md)
