# Development Log: 2026-02-12

**Focus:** Sprint 47 Implementation (ACP + UCP + MCP Support)
**Status:** Not Started

---

## Context

Continuing from 20260211. Sprint 47 planning complete (merged ACP + UCP), E2E testing strategy validated (7 decisions), serverless LLM testing approach documented.

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

- [01-serverless-llm-acp-testing.md](reports/01-serverless-llm-acp-testing.md) — How to test ACP with free serverless LLMs (HuggingFace Tiny Agents, MCP Inspector, custom Python agent)

### Todo

| Sprint | Document | Focus | Priority |
|--------|----------|-------|----------|
| 47 | [sprint-47-acp-ucp-mcp-support.md](todo/sprint-47-acp-ucp-mcp-support.md) | MCP server + ACP tools + UCP REST | High |
| 48 | [sprint-48-product-feed.md](todo/sprint-48-product-feed.md) | Product feed specification | High |
| 49 | [sprint-49-custom-conditions.md](todo/sprint-49-custom-conditions.md) | Custom condition types | Medium |
| 50 | [sprint-50-agent-webhooks.md](todo/sprint-50-agent-webhooks.md) | Agent webhooks + SPT lifecycle | Medium |
| 51 | [sprint-51-stripe-hosted-acp.md](todo/sprint-51-stripe-hosted-acp.md) | Stripe hosted ACP endpoint | Medium |
| 52 | [sprint-52-oauth-agent-auth.md](todo/sprint-52-oauth-agent-auth.md) | OAuth 2.1 agent authentication | Low |

### Done

_(none yet)_

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

- Previous day: [20260211](../20260211/status.md)
- Sprint 47: [sprint-47-acp-ucp-mcp-support.md](todo/sprint-47-acp-ucp-mcp-support.md)
- E2E testing report: [01-e2e-acp-checkout-testing-approach.md](../20260211/reports/01-e2e-acp-checkout-testing-approach.md)
- LLM testing report: [01-serverless-llm-acp-testing.md](reports/01-serverless-llm-acp-testing.md)
