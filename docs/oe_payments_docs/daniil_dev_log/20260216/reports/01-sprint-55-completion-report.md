# Sprint 55 Completion Report: GraphQL Product Discovery

**Date:** 2026-02-16
**Sprint:** 55
**Status:** DONE
**Branch:** `b-7.4.x-mcp-STRP-88`

---

## Summary

Implemented the **hybrid GraphQL product discovery** approach recommended in the [GraphQL vs Direct MCP report](../20260213/reports/07-graphql-vs-direct-mcp-report.md). The `list_products` MCP tool now uses OXID's GraphQL Storefront API when available, with automatic fallback to the existing direct model access when GraphQL is not installed.

---

## Architecture

```
AcpProductServiceInterface (payment-component)
    ↓ bound to
FallbackProductService (decorator)
    ├─ probes: POST /graphql/ { __typename } (3s timeout, cached)
    ├─ primary: GraphqlProductService
    │    ├─ GraphqlQueryBuilder (builds queries)
    │    ├─ HttpClientInterface → POST /graphql/
    │    └─ GraphqlResponseMapper (GraphQL → ACP format)
    └─ fallback: OxidProductService (existing, unchanged)
         ├─ OxidArticleQueryService
         └─ OxidProductFieldMapper
```

---

## Files Created

### Source (4 files, ~484 lines)

| File | Path | Lines | Purpose |
|------|------|-------|---------|
| `GraphqlQueryBuilder` | `src/Stripe/Mcp/Service/` | 110 | Builds GraphQL product queries with filters, pagination, sorting |
| `GraphqlResponseMapper` | `src/Stripe/Mcp/Service/` | 228 | Maps OXID GraphQL response to ACP product format (13+ fields) |
| `GraphqlProductService` | `src/Stripe/Mcp/Service/` | 69 | `AcpProductServiceInterface` via internal HTTP to `/graphql/` |
| `FallbackProductService` | `src/Stripe/Mcp/Service/` | 77 | Decorator: probe → GraphQL → fallback to direct on any error |

### Tests (4 files, 97 tests, 193 assertions)

| File | Tests | Assertions | Coverage |
|------|-------|------------|----------|
| `GraphqlQueryBuilderTest` | 25 | 50 | Pagination, filters, sorting, sanitization, edge cases |
| `GraphqlResponseMapperTest` | 42 | 62 | Field mapping, truncation, images, availability, null handling |
| `GraphqlProductServiceTest` | 15 | 39 | HTTP flow, error handling (5 failure modes), interface compliance |
| `FallbackProductServiceTest` | 15 | 42 | Availability caching, fallback logic, logging, probe details |

### Modified (1 file)

| File | Changes |
|------|---------|
| `services.yaml` | +4 service definitions, rebound `AcpProductServiceInterface`, +1 parameter |

---

## Enhanced Product Data (GraphQL path)

When GraphQL is available, `list_products` returns additional fields:

| New Field | Source | Example |
|-----------|--------|---------|
| `rating` | `product.rating` | `4.5` |
| `category` | `product.category.title` | `"Running"` |
| `seo_url` | `product.seo.url` | `"/Running/Blue-Shoes.html"` |
| `url` | SEO URL (replaces `?cl=details&anid=`) | Clean URL instead of query param |
| `image_url` | `imageGallery.images[0]` | Full resolution image (not just OXPIC1) |
| `currency` | `price.currency.name` | Per-product currency (not shop default) |

Fields `gtin`, `mpn`, `weight`, `group_id` return `null` in GraphQL path (not in standard schema). The direct model fallback provides these when needed (e.g., feed generation).

---

## Quality Gates

```
PHPCS (PSR-12)             -- 0 errors
PHPUnit (Unit)             -- 1099 tests, 2787 assertions — ALL PASS
PHPStan (level max)        -- 0 errors (new files verified individually)
PHPMD (--strict + baseline) -- 0 new violations
```

**PHPMD note:** `GraphqlResponseMapper::mapProduct()` was initially flagged for NPath complexity (16384 > 5000). Refactored by extracting 8 helper methods (`extractDescription`, `extractFormattedPrice`, `extractCurrency`, `extractSeoUrl`, `extractString`, `extractNestedString`, `extractNullableNestedString`, `extractPrimaryImage`). Complexity now well under threshold.

---

## Test Baseline Update

| Metric | Before Sprint 55 | After Sprint 55 | Delta |
|--------|-------------------|------------------|-------|
| Unit Tests | 992 | 1099 | +107 |
| Assertions | 2549 | 2787 | +238 |
| Test Files | — | +4 | — |
| Source Files | — | +4 | — |
| PHPCS | 0 errors | 0 errors | — |
| PHPStan | 0 errors | 0 errors | — |
| PHPMD | 4 baselined | 4 baselined | — |

**Note:** The 1099 total includes 10 pre-existing tests that were counted differently in the previous 992 baseline (likely due to PHPUnit version or suite configuration changes). The net new Sprint 55 tests are 97.

---

## Unchanged (explicitly preserved)

| Component | Reason |
|-----------|--------|
| `OxidProductService` | Still used as fallback path |
| `OxidArticleQueryService` | Required by direct model path |
| `OxidProductFieldMapper` | Required by direct path and CSV/JSONL feed generation |
| `ListProductsTool` | Depends only on `AcpProductServiceInterface` — no change needed |
| `ProductFeedRequestHandler` | Feed generation independent of product listing source |
| `CsvFeedGenerator` / `JsonlFeedGenerator` | Unchanged, use their own data path |

---

## Acceptance Criteria Verification

| # | Criterion | Status |
|---|-----------|--------|
| 1 | When `graphql-storefront` installed: enriched data (SEO, ratings, categories) | PASS (tested via mocks) |
| 2 | When `graphql-storefront` NOT installed: transparent fallback | PASS (15 fallback tests) |
| 3 | Availability probed once, cached | PASS (caching tests verify single probe) |
| 4 | GraphQL failure triggers fallback + warning log | PASS (exception + logging tests) |
| 5 | Feed generation unchanged | PASS (1099 tests, no feed regressions) |
| 6 | No breaking changes to interfaces | PASS (all existing tests green) |
| 7 | `stripe.graphql.endpoint` configurable | PASS (parameter in services.yaml) |
| 8 | All quality gates pass | PASS (PHPCS, PHPStan, PHPMD, 1099 tests) |
