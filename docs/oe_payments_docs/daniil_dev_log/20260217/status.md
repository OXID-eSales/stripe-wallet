# Development Log — 2026-02-17

## Focus: Sprint 56 — Fix MCP create_checkout + GraphQL Integration Documentation

**Branch:** `b-7.4.x-mcp-STRP-88`
**Previous:** Sprint 55 (GraphQL Product Discovery) completed 2026-02-16

---

## Context

During a live MCP agent session, the `create_checkout` tool failed with "User ID is required" despite valid buyer data being provided. The `AcpContextResolverHandler` (priority 200) is supposed to resolve the buyer email into an OXID User and set `userId` on the EventContext before `StripeContractCreationHandler` (priority 100) validates it.

Additionally, documenting how the OXID GraphQL Storefront API integrates with our MCP layer (completed in Sprint 55).

---

## Sprint Queue

| Sprint | Title | Priority | Status |
|--------|-------|----------|--------|
| 56 | Fix MCP create_checkout "User ID is required" | Critical | DONE |
| 56b | Fix MCP create_checkout invalid_payment_method + basket validation | Critical | DONE |

---

## Test Baseline (after Sprint 56b)

- **Unit Tests:** 1114 tests, 2775 assertions (up from 1113/2773)
- **New/updated unit tests:** 16 tests across 1 test file (expanded from 8 to 16)
- **PHPCS:** 0 errors
- **PHPStan:** 0 errors (level max)
- **PHPMD:** 0 new violations (4 baselined)

---

## Work Done Today

### 1. GraphQL-MCP Integration Report (Report 01)

Documented how OXID's GraphQL Storefront API is used by our MCP layer:

- **Product discovery** (`list_products`) uses GraphQL via internal HTTP loopback to `POST /graphql/`
- **Decorator pattern**: `FallbackProductService` wraps `GraphqlProductService` + `OxidProductService`
- **Probe-and-cache**: GraphQL availability checked once via `{ __typename }` introspection query
- **Enriched data**: SEO URLs, ratings, categories, image galleries — not available via direct model
- **Checkout** (`create_checkout`) bypasses GraphQL — uses smart-contract architecture directly (incompatible with GraphQL's `placeOrder`)
- Full architecture diagram, sequence diagram, data comparison table

### 2. Sprint 56: Fix create_checkout "User ID is required" (DONE)

**Root cause:** `AcpContextResolverHandler::createGuestUser()` only set `oxusername`, `oxfname`, `oxlname`, `oxactive` on the OXID User object. The `oxuser` table requires `OXCOUNTRYID` and address fields. OXID's `User::save()` can fail silently, leaving `$user->getId()` as null. The handler then set `context.userId = null`, and downstream `ContractCreationHandler` threw "User ID is required" — a misleading error message.

**Fixes applied to `AcpContextResolverHandler`:**

| Fix | Description |
|-----|-------------|
| **Use fulfillment address data** | Guest user creation now sets `oxstreet`, `oxcity`, `oxzip`, `oxcountryid` from `acp_fulfillment_address` |
| **Country code resolution** | New `resolveCountryId()` method converts ISO 3166-1 alpha-2 code (e.g., "DE") to OXID country ID |
| **User ID validation** | New `validateUserId()` method throws actionable `RuntimeException` if user ID is empty after resolution |
| **Extracted session methods** | `setSession()` and `getSessionId()` extracted as protected methods for testability |
| **Testable subclass simplified** | `TestableAcpContextResolverHandler` now overrides targeted methods instead of duplicating `handle()` logic |

### 3. Sprint 56b: Fix create_checkout invalid_payment_method + basket validation (DONE)

After fixing "User ID is required", two new errors surfaced during live MCP agent session:

1. `ShopOrderException: "Order finalization failed with state: 5 (invalid_payment_method)"` — order creation failed because payment ID `oxidstripe` doesn't exist in `oxpayments` table
2. `ArticleInputException: "ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE"` — some products silently removed from basket by OXID (not buyable, variant required, etc.)

**Root causes:**

| Error | Root Cause |
|-------|------------|
| **invalid_payment_method** | `PAYMENT_ID` constant was `'oxidstripe'` — wrong ID. Correct is `'oe_payments_stripe_wallet'` from `StripeDefinitions::STRIPE_WALLET_PAYMENT_ID` |
| **article_not_buyable** | OXID's `Basket::addToBasket()` silently swallows `ArticleInputException` and removes unbuyable items. `buildBasket()` had no post-validation. |

**Fixes applied:**

| Fix | Description |
|-----|-------------|
| **Correct payment ID** | Changed `PAYMENT_ID` from `'oxidstripe'` to `StripeDefinitions::STRIPE_WALLET_PAYMENT_ID` (`'oe_payments_stripe_wallet'`) |
| **Basket item validation** | After adding items, validates `getProductsCount() > 0`. Throws `RuntimeException` with actionable message if all items were silently removed. |
| **Partial add warning** | Logs a warning when some (but not all) items couldn't be added — helps agent debug without blocking checkout |
| **Empty items guard** | Throws `InvalidArgumentException` if no valid items provided in the request |
| **Test for payment ID** | New unit test uses reflection to verify `PAYMENT_ID === StripeDefinitions::STRIPE_WALLET_PAYMENT_ID` |

**Files modified (1 source, 1 test):**

| File | Changes |
|------|---------|
| `src/Stripe/Mcp/Handler/AcpContextResolverHandler.php` | +correct PAYMENT_ID, +basket validation, +import StripeDefinitions |
| `tests/Unit/.../AcpContextResolverHandlerTest.php` | +1 test: payment ID constant verification (16 total) |

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | SRP, OCP, LSP, ISP, DIP in every class |
| DI | Depend on abstractions, wire via services.yaml |
| LSP | Subtypes must be substitutable for their base types |
| DRY | No duplication — extract shared logic |
| No Overengineering | Build only what is needed now |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

---

## Documents

### Reports
- [01-graphql-mcp-integration-report.md](reports/01-graphql-mcp-integration-report.md) — How OXID GraphQL is used by MCP layer

### Todo
_(none — Sprint 56 complete)_

### Done
| Sprint | Document | Completed |
|--------|----------|-----------|
| **56** | [sprint-56-fix-mcp-create-checkout.md](done/sprint-56-fix-mcp-create-checkout.md) | 2026-02-17 |
| **56b** | [sprint-56b-fix-payment-id-basket-validation.md](done/sprint-56b-fix-payment-id-basket-validation.md) | 2026-02-17 |

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

- Previous day: [20260216](../20260216/status.md)
- GraphQL analysis (basis): [07-graphql-vs-direct-mcp-report.md](../20260213/reports/07-graphql-vs-direct-mcp-report.md)
- Sprint 55 (GraphQL impl): [01-sprint-55-completion-report.md](../20260216/reports/01-sprint-55-completion-report.md)
- Sprint 47 (MCP foundations): [sprint-47-acp-ucp-mcp-support.md](../20260212/done/sprint-47-acp-ucp-mcp-support.md)
