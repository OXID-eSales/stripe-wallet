# Development Log: 2026-02-11

**Focus:** E2E Testing Strategy + Sprint 47/53 Merge (ACP + UCP from Day One)
**Status:** In Progress

---

## Context

Previous work (20260209) planned the full ACP/MCP feature track (Sprints 47-54) with UCP as a separate Sprint 53 (Low priority, after OAuth). Today we:

1. Researched E2E testing strategy for agentic ACP-checkout (report complete, 7 decisions validated)
2. Merged Sprint 53 (UCP) into Sprint 47 — both ACP and UCP are now supported from the beginning

**Merge rationale:** ACP and UCP serve the same purpose (agentic checkout) and share the same backend (`AbstractAcpCheckoutService`). Building both from the start ensures the architecture is protocol-agnostic from day one, avoids retrofitting UCP later, and removes Sprint 52 (OAuth) as a prerequisite for UCP.

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

- [01-e2e-acp-checkout-testing-approach.md](reports/01-e2e-acp-checkout-testing-approach.md) — E2E testing strategy: 3 levels, SPT challenge, 7 validated decisions

### Todo

| Sprint | Document | Focus | Priority |
|--------|----------|-------|----------|
| 47 | [sprint-47-acp-ucp-mcp-support.md](todo/sprint-47-acp-ucp-mcp-support.md) | MCP server + ACP tools + UCP REST (merged from 47+53) | High |

### Done

_(none yet)_

---

## Revised Sprint Roadmap (after merge)

Previously 8 sprints (47-54), now **7 sprints (47-54, no 53)**:

| Sprint | Focus | Priority |
|--------|-------|----------|
| **47** | **MCP server + ACP tools + UCP REST** (merged 47+53) | **High** |
| 48 | Product feed specification | High |
| 49 | Custom condition types for agent workflows | Medium |
| 50 | Agent webhooks + SPT lifecycle | Medium |
| 51 | Stripe hosted ACP endpoint integration | Medium |
| 52 | OAuth 2.1 agent authentication | Low |
| 54 | E2E tests for full MCP/ACP/UCP stack | High |

### Revised Dependency Graph

```
Sprint 47 (MCP/ACP + UCP Foundations) ← merged
├──→ Sprint 48 (Product Feed)
│    └──→ Sprint 51 (Stripe Hosted ACP)
├──→ Sprint 49 (Custom Condition Types)
├──→ Sprint 50 (Agent Webhooks)
├──→ Sprint 52 (OAuth Agent Auth)
└──→ Sprint 54 (E2E Tests) ← depends on ALL above
```

**Change:** Sprint 53 eliminated. UCP no longer depends on Sprint 52 (OAuth) — Bearer token auth is shared with ACP from the start.

### Revised Scope

| Sprint | payment-component | stripe | Tests |
|--------|-------------------|--------|-------|
| **47** | **29 files (~1,421 lines)** | **10 files (~503 lines) + 3 modified** | **~23 files (~1,150 lines)** |
| 48 | 2 files (~45 lines) | 9 files (~455 lines) | ~10 files (~400 lines) |
| 49 | 6 files (~148 lines) | 0 (services.yaml only) | ~6 files (~200 lines) |
| 50 | 7 files (~453 lines) | 2 files (~110 lines) | ~8 files (~350 lines) |
| 51 | 2 files (~75 lines) | 4 files (~245 lines) | ~5 files (~200 lines) |
| 52 | 6 files (~280 lines) | 1 file (~20 lines) | ~7 files (~250 lines) |
| 54 | — | — | 14 files (~890 lines) |
| **Total** | **~52 files** | **~26 files** | **~73 files** |

---

## Test Baseline (carried from Sprint 46)

```
PHP CodeSniffer (PSR-12)    -- 0 errors
PHPUnit (Unit+Integration)  -- 799 tests, 2263 assertions
PHPStan (level max)         -- 0 errors
PHPMD                       -- 0 new violations (4 baselined)
Status: COMMITABLE
```

---

## Quick Links

- Previous day: [20260209](../20260209/status.md)
- Merged sprint: [sprint-47-acp-ucp-mcp-support.md](todo/sprint-47-acp-ucp-mcp-support.md)
- Original Sprint 47: [sprint-47-acp-mcp-support.md](../20260209/todo/sprint-47-acp-mcp-support.md)
- Original Sprint 53: [sprint-53-ucp-support.md](../20260209/todo/sprint-53-ucp-support.md)
- E2E testing report: [01-e2e-acp-checkout-testing-approach.md](reports/01-e2e-acp-checkout-testing-approach.md)
- ACP/MCP foundations: [01-acp-mcp-foundations.md](../20260209/reports/01-acp-mcp-foundations.md)
