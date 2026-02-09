# Development Log: 2026-02-09

**Focus:** ACP/MCP Protocol Support — Full Feature Track Planning (Sprints 47-54)
**Status:** 8 sprints planned, all TODO

---

## Context

Previous work (20260206) completed Sprints 42-46: idempotency, interface creation, refund setState bug fix, customer lifecycle, static analysis cleanup. Test baseline: 799 tests, 2263 assertions, all quality gates green.

Today begins a new feature track: **Agentic Commerce Protocol (ACP) and Model Context Protocol (MCP) support** at the stripe module level. This enables AI agents to discover products, create checkout sessions, and complete purchases programmatically through the stripe payment module.

The full feature track spans **8 sprints** covering both protocols (ACP + UCP), product feeds, custom conditions, webhook delivery, Stripe's hosted endpoints, OAuth authentication, and comprehensive E2E testing.

---

## Architecture Decisions (2026-02-09)

Resolved during architecture verification of all sprint documents:

| # | Question | Decision | Impact |
|---|----------|----------|--------|
| 1 | Should protocol controllers (MCP, UCP, Feed) follow event-only pattern? | **Strict event-only for ALL controllers** — no exceptions | Sprints 47, 48, 53 updated |
| 2 | Should handlers access repositories directly? | **Always through services** — no direct repository access | Sprints 50, 51 updated |
| 3 | How to handle outbound HTTP calls? | **HttpClientInterface in payment-component** — concrete CurlHttpClient in stripe | Sprints 47, 50, 52 updated |
| 4 | Should existing WebhookController be refactored? | **Yes, in Sprint 47** — clean slate for all controllers | Sprint 47 updated |

### Pattern: Event-Only Controller

All controllers (including existing `WebhookController`) must follow:
```
1. Validate input (auth, headers, body)
2. Create EventContext — ONLY DATA, NO LOGIC
3. Dispatch event — HANDLERS DO THE WORK
4. Read result from context
```

Canonical reference: `StripeOrderController` (existing), `McpController` (Sprint 47).

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

- [01-acp-mcp-foundations.md](reports/01-acp-mcp-foundations.md) — Technical foundations of MCP, ACP, and agentic commerce protocols
- [02-mcp-extraction-analysis.md](reports/02-mcp-extraction-analysis.md) — Should MCP be a separate package? Comparative analysis (3 options)

### Todo

| Sprint | Document | Focus | Priority |
|--------|----------|-------|----------|
| 47 | [sprint-47-acp-mcp-support.md](todo/sprint-47-acp-mcp-support.md) | MCP server + ACP checkout tools (two-module) | High |
| 48 | [sprint-48-product-feed.md](todo/sprint-48-product-feed.md) | Product feed specification (catalog sync to AI agents) | High |
| 49 | [sprint-49-custom-condition-types.md](todo/sprint-49-custom-condition-types.md) | Extensible ContractCondition types for agent workflows | Medium |
| 50 | [sprint-50-agent-webhooks.md](todo/sprint-50-agent-webhooks.md) | Webhook delivery to AI agents (fulfillment updates) + SPT lifecycle | Medium |
| 51 | [sprint-51-stripe-hosted-acp.md](todo/sprint-51-stripe-hosted-acp.md) | Stripe Agentic Commerce Suite integration (hosted endpoints) | Medium |
| 52 | [sprint-52-oauth-agent-auth.md](todo/sprint-52-oauth-agent-auth.md) | OAuth 2.1 agent authentication (upgrade from Bearer token) | Low |
| 53 | [sprint-53-ucp-support.md](todo/sprint-53-ucp-support.md) | Google UCP (Universal Commerce Protocol) support | Low |
| 54 | [sprint-54-e2e-mcp-acp-tests.md](todo/sprint-54-e2e-mcp-acp-tests.md) | E2E tests for full MCP/ACP stack | High |

### Sprint Dependency Graph

```
Sprint 47 (MCP/ACP Foundations)
├──→ Sprint 48 (Product Feed)
│    └──→ Sprint 51 (Stripe Hosted ACP)
├──→ Sprint 49 (Custom Condition Types)
├──→ Sprint 50 (Agent Webhooks)
├──→ Sprint 52 (OAuth Agent Auth)
│    └──→ Sprint 53 (UCP Support)
└──→ Sprint 54 (E2E Tests) ← depends on ALL above
```

### Done

_(none yet)_

---

## Estimated Scope

| Sprint | payment-component | stripe | Tests |
|--------|-------------------|--------|-------|
| 47 | 22 files (~1,030 lines) | 6 files (~348 lines) + 3 modified | ~14 files (~800 lines) |
| 48 | 2 files (~45 lines) | 9 files (~455 lines) | ~10 files (~400 lines) |
| 49 | 6 files (~148 lines) | 0 (services.yaml only) | ~6 files (~200 lines) |
| 50 | 7 files (~453 lines) | 2 files (~110 lines) | ~8 files (~350 lines) |
| 51 | 2 files (~75 lines) | 4 files (~245 lines) | ~5 files (~200 lines) |
| 52 | 6 files (~280 lines) | 1 file (~20 lines) | ~7 files (~250 lines) |
| 53 | 7 files (~391 lines) | 4 files (~155 lines) | ~9 files (~350 lines) |
| 54 | — | — | 13 files (~850 lines) |
| **Total** | **~52 files** | **~26 files** | **~72 files** |

---

## Test Baseline (carried from Sprint 46)

```
✓ PHP CodeSniffer (PSR-12)    -- 0 errors
✓ PHPUnit (Unit+Integration)  -- 799 tests, 2263 assertions
✓ PHPStan (level max)         -- 0 errors
✓ PHPMD                       -- 0 new violations (4 baselined)
Status: COMMITABLE
```

---

## Quick Links

- Previous day: [20260206](../20260206/status.md)
- Architecture docs: [docs/architecture/](../../../docs/architecture/)
- Developer docs: [docs/for_developer/](../../../docs/for_developer/)
