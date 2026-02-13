# Development Log: 2026-02-12

**Focus:** Sprint 47 Implementation + Security Hardening
**Status:** Sprint 47 DONE

---

## Context

Continuing from 20260211. Sprint 47 planning complete (merged ACP + UCP), E2E testing strategy validated (7 decisions), serverless LLM testing approach documented. Today: implemented Sprint 47, added security hardening (rate limiting, input validation, error sanitization), fixed PHPMD config bug, all quality gates green.

---

## Work Done Today

### 1. Event-Driven Architecture Proof (Report 02)

Validated that the planned UCP/ACP functionality uses the event system exclusively — no direct data or service calls from controllers. Key findings:

- All 3 new controllers (McpController, UcpCheckoutController, UcpProfileController) follow the strict event-only pattern
- Handlers tagged via `payment.event_handler` in services.yaml — same DI pattern as existing production code
- Existing codebase (StripeOrderController, OrderActionDispatcher) already proves this pattern works
- Agent notifications (Sprint 50) also event-driven — subscribes to contract lifecycle events

### 2. Sprint Roadmap Documentation (Sprints 48-52)

Expanded the todo folder with full specification documents for all remaining sprints:

| Sprint | Document | Focus | Priority |
|--------|----------|-------|----------|
| **47** | sprint-47-acp-ucp-mcp-support.md | MCP server + ACP tools + UCP REST | **DONE** |
| **48** | sprint-48-product-feed.md | Product feed (CSV/JSONL) + field mapping | **High** |
| 49 | sprint-49-custom-condition-types.md | Extensible condition type registry | Medium |
| 50 | sprint-50-agent-webhooks.md | Agent notifications + SPT lifecycle | Medium |
| 51 | sprint-51-stripe-hosted-acp.md | Stripe Agentic Commerce Suite integration | Medium |
| 52 | sprint-52-oauth-agent-auth.md | OAuth 2.1 resource server | Low |

### 3. Sprint 54: MCP/ACP Integration Tests + LLM E2E

Created comprehensive test sprint plan with three levels:

- **Level 1: Integration** — PHP service layer + DB (4 test files)
- **Level 2: HTTP** — Real HTTP to running shop (3 test files)
- **Level 3: LLM E2E** — Real LLM via Featherless API -> MCP -> shop (2 test files)

Key decisions (from Q&A):
- Both payment-component ACP and Stripe SPT layers
- Real LLM in CI using Featherless headless inference (`FEATHERLESS_API_KEY`)
- Configurable model via `FEATHERLESS_MODEL` env var (default: `Qwen/Qwen2.5-72B-Instruct`)
- All PHPUnit inside Docker, skip gracefully when credentials missing
- 13 new files, ~1,015 lines

### 4. Sprint 47 Implementation (DONE)

Implemented the full MCP/ACP/UCP agent commerce layer:

- **payment-component**: 29 new files — McpServer, Auth, ACP Tools (6), ACP/UCP formatters, AbstractAcpCheckoutService, UCP profile/capability/negotiation, rate limiter
- **stripe**: 10 new files — 3 controllers, event+handler, StripeAcpCheckoutService, SptPaymentService, CurlHttpClient
- **Modified**: metadata.php (+3 controllers, +1 setting), services.yaml (full wiring)

### 5. Security Hardening

Added defense-in-depth to all MCP/UCP controllers:

- **Rate limiting**: APCu per-IP (60 req/60s), configurable via services.yaml parameters
- **Body size limit**: 1 MB hard limit with double-check (Content-Length + strlen)
- **Content-Type validation**: 415 for non-application/json
- **Error sanitization**: McpServer returns generic "Tool execution failed" instead of exception messages
- **PHPStan-safe `$_SERVER`**: `is_string()`/`is_int()` guards on all superglobal reads

### 6. PHPMD Config Bug Fix

Fixed `tests/PhpMd/phpmd.xml`: bulk `<rule ref="rulesets/codesize.xml">` loaded CyclomaticComplexity/NPathComplexity with default thresholds (CC=10, NPath=200), shadowing custom thresholds (CC=20, NPath=5000). Fixed by excluding rules that are re-added individually with custom thresholds.

---

## Test Results (Sprint 47)

### MCP Unit Tests

| Suite | Tests | Assertions | Skipped | Result |
|-------|-------|------------|---------|--------|
| stripe MCP | 48 | 189 | 0 | PASS |
| payment-component MCP | 89 | 173 | 4 (APCu) | PASS |
| **Total MCP** | **137** | **362** | **4** | **PASS** |

### Coverage (MCP classes only)

| Module | Classes | Lines | % |
|--------|---------|-------|---|
| payment-component | 11/11 | 278/282 | 98.6% |
| stripe | 5/5 | 141/168 | 83.9% |
| **Combined** | **16/16** | **419/450** | **93.1%** |

### Full Suite Quality Gates

```
PHPCS (PSR-12)             -- 0 errors
PHPUnit (Unit)             -- 682 tests, 1756 assertions — ALL PASS
PHPStan (level max)        -- 0 errors
PHPMD (--strict + baseline) -- 0 new violations
Status: COMMITABLE
```

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

- [01-serverless-llm-acp-testing.md](reports/01-serverless-llm-acp-testing.md) — How to test ACP with free serverless LLMs
- [02-event-driven-architecture-proof.md](reports/02-event-driven-architecture-proof.md) — Proof that UCP/ACP uses event system
- [03-sprint-47-completion-report.md](reports/03-sprint-47-completion-report.md) — Sprint 47 completion report with coverage

### Todo

| Sprint | Document | Focus | Priority |
|--------|----------|-------|----------|
| 48 | [sprint-48-product-feed.md](todo/sprint-48-product-feed.md) | Product feed specification | High |
| 49 | [sprint-49-custom-condition-types.md](todo/sprint-49-custom-condition-types.md) | Custom condition types | Medium |
| 50 | [sprint-50-agent-webhooks.md](todo/sprint-50-agent-webhooks.md) | Agent webhooks + SPT lifecycle | Medium |
| 51 | [sprint-51-stripe-hosted-acp.md](todo/sprint-51-stripe-hosted-acp.md) | Stripe hosted ACP endpoint | Medium |
| 52 | [sprint-52-oauth-agent-auth.md](todo/sprint-52-oauth-agent-auth.md) | OAuth 2.1 agent authentication | Low |
| **54** | [sprint-54-mcp-acp-integration-tests.md](todo/sprint-54-mcp-acp-integration-tests.md) | Integration + HTTP + LLM E2E tests | **High** |

### Done

| Sprint | Document | Completed |
|--------|----------|-----------|
| **47** | [sprint-47-acp-ucp-mcp-support.md](done/sprint-47-acp-ucp-mcp-support.md) | 2026-02-12 |

---

## Sprint Dependency Graph

```
Sprint 47 (MCP/ACP + UCP Foundations) ← DONE
├──→ Sprint 48 (Product Feed) ← HIGH PRIORITY (next)
│    └──→ Sprint 51 (Stripe Hosted ACP)
├──→ Sprint 49 (Custom Condition Types)
├──→ Sprint 50 (Agent Webhooks)
├──→ Sprint 52 (OAuth Agent Auth)
└──→ Sprint 54 (Integration + LLM E2E Tests) ← HIGH PRIORITY (unblocked)
```

## Estimated Scope

| Sprint | payment-component | stripe | Tests |
|--------|-------------------|--------|-------|
| **47** | 29 files (~1,421 lines) | 10 files (~503 lines) + 3 modified | ~15 files (~1,150 lines) | **DONE** |
| 48 | 2 files (~45 lines) | 9 files (~455 lines) | ~10 files (~400 lines) |
| 49 | 6 files (~148 lines) | 0 (services.yaml only) | ~6 files (~200 lines) |
| 50 | 7 files (~453 lines) | 2 files (~110 lines) | ~8 files (~350 lines) |
| 51 | 2 files (~75 lines) | 4 files (~245 lines) | ~5 files (~200 lines) |
| 52 | 6 files (~280 lines) | 1 file (~20 lines) | ~7 files (~250 lines) |
| **54** | 0 | 4 fixtures + 9 tests | ~13 files (~1,015 lines) |

---

## Test Baseline (updated after Sprint 47)

```
PHP CodeSniffer (PSR-12)    -- 0 errors
PHPUnit (Unit)              -- 682 tests, 1756 assertions
PHPStan (level max)         -- 0 errors
PHPMD (--strict + baseline) -- 0 new violations (4 baselined)
MCP coverage                -- 93.1% lines (419/450)
Status: COMMITABLE
```

---

## Quick Links

- Previous day: [20260211](../20260211/status.md)
- Sprint 47 (done): [sprint-47-acp-ucp-mcp-support.md](done/sprint-47-acp-ucp-mcp-support.md)
- Sprint 47 report: [03-sprint-47-completion-report.md](reports/03-sprint-47-completion-report.md)
- E2E testing report: [01-e2e-acp-checkout-testing-approach.md](../20260211/reports/01-e2e-acp-checkout-testing-approach.md)
- LLM testing report: [01-serverless-llm-acp-testing.md](reports/01-serverless-llm-acp-testing.md)
- Architecture proof: [02-event-driven-architecture-proof.md](reports/02-event-driven-architecture-proof.md)
- Sprint 54: [sprint-54-mcp-acp-integration-tests.md](todo/sprint-54-mcp-acp-integration-tests.md)
