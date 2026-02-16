# Development Log — 2026-02-16

## Focus: Sprint 55 — GraphQL Product Discovery (Hybrid Approach)

**Branch:** `b-7.4.x-mcp-STRP-88`
**Previous:** Sprints 48-52, 54 completed 2026-02-13

---

## Context

Following the GraphQL vs Direct MCP analysis (report 07, 2026-02-13), the recommendation was a **hybrid approach**:
- **GraphQL for product discovery** (`list_products`) — richer data, built-in filtering, less custom code
- **Direct model access for checkout** — smart-contract architecture incompatible with GraphQL's PlaceOrder

Sprint 55 implements Phase 2 of this recommendation: replace the direct-model product listing
(`OxidProductService` → `OxidArticleQueryService` → `OxidProductFieldMapper`) with a
`GraphqlProductService` that calls OXID's GraphQL Storefront API internally, with fallback
to the existing direct implementation when the GraphQL module is unavailable.

---

## Sprint Queue

| Sprint | Title | Priority | Status |
|--------|-------|----------|--------|
| 55 | GraphQL Product Discovery — Hybrid Approach | High | DONE |

---

## Test Baseline (after Sprint 55)

- **Unit Tests:** 1099 tests, 2787 assertions (up from 992/2549)
- **New unit tests added:** 97 tests, 193 assertions across 4 test files
- **PHPCS:** 0 errors
- **PHPStan:** 0 errors (level max, new files)
- **PHPMD:** 0 new violations (4 baselined)

---

## Progress Log

### Sprint 55: GraphQL Product Discovery
- **Status:** DONE
- **Started:** 2026-02-16
- **Completed:** 2026-02-16
- **Files:** 4 source + 4 tests (97 tests, 193 assertions)
- **Report:** `reports/01-sprint-55-completion-report.md`

---

## Work Done Today

### Sprint 55 Implementation

**New source files (4):**

| File | Lines | Purpose |
|------|-------|---------|
| `GraphqlQueryBuilder.php` | ~110 | Builds GraphQL product queries with filters/pagination/sorting |
| `GraphqlResponseMapper.php` | ~228 | Maps OXID GraphQL response to ACP product format |
| `GraphqlProductService.php` | ~69 | AcpProductServiceInterface via internal HTTP to `/graphql/` |
| `FallbackProductService.php` | ~77 | Decorator: tries GraphQL, falls back to OxidProductService |

**New test files (4):**

| File | Tests | Assertions |
|------|-------|------------|
| `GraphqlQueryBuilderTest.php` | 25 | 50 |
| `GraphqlResponseMapperTest.php` | 42 | 62 |
| `GraphqlProductServiceTest.php` | 15 | 39 |
| `FallbackProductServiceTest.php` | 15 | 42 |
| **Total** | **97** | **193** |

**Modified files:**
- `services.yaml` — Added 4 new services, rebound `AcpProductServiceInterface` to `FallbackProductService`, added `stripe.graphql.endpoint` parameter

**Key design decisions:**
- **Decorator pattern with fallback** — `FallbackProductService` wraps both GraphQL and direct implementations
- **Probe-and-cache** — GraphQL availability checked once via `{ __typename }` introspection query (3s timeout), cached for request lifecycle
- **Extracted helpers to pass PHPMD** — `mapProduct()` refactored into 8 small helper methods to keep NPath complexity under 5000
- **PHPStan safe** — All mixed-type GraphQL response data guarded with `is_string()`, `is_array()`, `is_numeric()` checks
- **Zero breaking changes** — `ListProductsTool`, feed generation, and all existing services unchanged

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
- [07-graphql-vs-direct-mcp-report.md](../20260213/reports/07-graphql-vs-direct-mcp-report.md) — GraphQL vs Direct MCP analysis (basis for Sprint 55)
- [01-sprint-55-completion-report.md](reports/01-sprint-55-completion-report.md) — Sprint 55 completion report

### Todo
_(none — Sprint 55 complete)_

### Done
| Sprint | Document | Completed |
|--------|----------|-----------|
| **55** | [sprint-55-graphql-product-discovery.md](done/sprint-55-graphql-product-discovery.md) | 2026-02-16 |

---

## Sprint Dependency Graph

```
Sprints 47-54 (MCP/ACP/UCP + Product Feed + Tests) ← ALL DONE
└──→ Sprint 55 (GraphQL Product Discovery) ← DONE
     └──→ Replaces OxidProductService chain with GraphQL + fallback
```

---

## Quality Gates

```
PHP CodeSniffer (PSR-12)     -- 0 errors
PHPUnit (Unit)               -- 1099 tests, 2787 assertions — ALL PASS
PHPStan (level max)          -- 0 errors (new files)
PHPMD (--strict + baseline)  -- 0 new violations
Status: COMMITABLE
```

---

## Quick Links

- Previous day: [20260213](../20260213/status.md)
- GraphQL report: [07-graphql-vs-direct-mcp-report.md](../20260213/reports/07-graphql-vs-direct-mcp-report.md)
- Sprint 48 (product feed, preserved): [sprint-48-product-feed.md](../20260213/done/sprint-48-product-feed.md)
- Sprint 47 (MCP/ACP foundations): [sprint-47-acp-ucp-mcp-support.md](../20260212/done/sprint-47-acp-ucp-mcp-support.md)
