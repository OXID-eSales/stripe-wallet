# Sprint 55: GraphQL Product Discovery — Hybrid Approach

**Date:** 2026-02-16
**Status:** TODO
**Priority:** High
**Prerequisites:** Sprint 48 (Product Feed) — `OxidProductService`, `OxidArticleQueryService`, `OxidProductFieldMapper`
**Depends on:** Sprint 47 (`ListProductsTool`, `AcpProductServiceInterface`), Sprint 48 (current direct model implementation)
**Based on:** [GraphQL vs Direct MCP Report](../20260213/reports/07-graphql-vs-direct-mcp-report.md) — Phase 2 recommendation

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | SRP, OCP, LSP, ISP, DIP in every class |
| DI | Depend on abstractions, wire via services.yaml |
| LSP | `GraphqlProductService` must be substitutable for `OxidProductService` |
| DRY | Reuse existing `HttpClientInterface`, `ShopAdapterInterface` |
| No Overengineering | Option A (HTTP loopback) only — no direct GraphQL service calls |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

---

## Objective

Replace the direct-model product listing chain (`OxidProductService` → `OxidArticleQueryService` → `OxidProductFieldMapper`) with a **GraphQL-backed service** that calls OXID's `graphql-storefront` API internally, returning richer product data (variants, SEO URLs, images, reviews, ratings) with built-in filtering, pagination, and sorting.

**Key principle:** Decorator pattern with fallback — if GraphQL is unavailable, gracefully degrade to the existing direct model implementation.

### What This Sprint Covers

- `GraphqlProductService` implementing `AcpProductServiceInterface` via internal HTTP to `/graphql/`
- `GraphqlQueryBuilder` for building product queries with filters/pagination/sorting
- `GraphqlResponseMapper` for mapping GraphQL response to ACP product format
- `FallbackProductService` decorator: tries GraphQL first, falls back to `OxidProductService`
- Automatic GraphQL availability detection (probes endpoint on first call)
- services.yaml rewiring to use fallback decorator as primary `AcpProductServiceInterface`

### What This Sprint Does NOT Cover

- GraphQL for checkout operations (incompatible with smart-contract architecture — see report Section 4.2)
- Option B (direct GraphQL service call) — too coupled to internal API
- GraphQL mutations (baskets, orders) — never appropriate for our architecture
- GraphQL authentication (product queries are public — no token needed)
- Variant selection queries (future enhancement)

---

## Why GraphQL for Product Discovery

From the [analysis report](../20260213/reports/07-graphql-vs-direct-mcp-report.md):

| Aspect | Current (Direct) | GraphQL |
|--------|------------------|---------|
| **Data richness** | 13 fields, manual mapping | Full product graph: variants, SEO, reviews, images |
| **Search** | Basic OXID full-text | Same OXID full-text via `StringFilter.contains` |
| **Filtering** | Custom SQL WHERE clauses | Built-in: price range, category, manufacturer, active |
| **Pagination** | Manual LIMIT/OFFSET in raw SQL | Native `pagination: { offset, limit }` with metadata |
| **Sorting** | Hardcoded `OXTIMESTAMP DESC` | `sort: { price: "ASC", title: "DESC" }` |
| **Variants** | Not supported | `variantSelections` query available |
| **SEO URLs** | Not included | Included in product graph |
| **Maintenance** | We maintain field mapper + SQL | OXID maintains, auto-updates with shop version |

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│  AI Agent (MCP Client)                                            │
│  calls tools/call: list_products                                  │
└───────────────────────────┬───────────────────────────────────────┘
                            │
┌───────────────────────────▼───────────────────────────────────────┐
│  payment-component                                                  │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ ListProductsTool → AcpProductServiceInterface                 │  │
│  └──────────────────────────────────────────────────────────────┘  │
└───────────────────────────┬───────────────────────────────────────┘
                            │ resolves to
┌───────────────────────────▼───────────────────────────────────────┐
│  stripe module                                                      │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ FallbackProductService (decorator)                            │  │
│  │  ├─ tries: GraphqlProductService                              │  │
│  │  │    ├─ GraphqlQueryBuilder (builds GraphQL queries)         │  │
│  │  │    ├─ HttpClientInterface → POST /graphql/                 │  │
│  │  │    └─ GraphqlResponseMapper (GraphQL → ACP format)         │  │
│  │  └─ falls back to: OxidProductService (existing direct model) │  │
│  │       ├─ OxidArticleQueryService (oxNew, raw SQL)             │  │
│  │       └─ OxidProductFieldMapper (manual field extraction)     │  │
│  └──────────────────────────────────────────────────────────────┘  │
└───────────────────────────────────────────────────────────────────┘
```

### Fallback Strategy

```
FallbackProductService.listProducts(filters)
  │
  ├─ Is GraphQL available? (cached check)
  │   ├─ Unknown → probe /graphql/ endpoint (HEAD request)
  │   │   ├─ 200/405 → mark available, use GraphQL
  │   │   └─ connection error/404 → mark unavailable, use direct
  │   ├─ Available → call GraphqlProductService
  │   │   ├─ Success → return GraphQL result
  │   │   └─ Failure → log warning, fall back to direct
  │   └─ Unavailable → call OxidProductService directly
  │
  └─ Return product list (same AcpProductServiceInterface contract)
```

---

## Boundary Rule Applied

| Component | Provider-Agnostic? | Module | Rationale |
|-----------|-------------------|--------|-----------|
| `AcpProductServiceInterface` | Yes | payment-component | Already defined (Sprint 47) — unchanged |
| `ProductFieldMapperInterface` | Yes | payment-component | Already defined (Sprint 48) — unchanged |
| `GraphqlProductService` | **No** | stripe | Depends on OXID GraphQL API shape |
| `GraphqlQueryBuilder` | **No** | stripe | Builds OXID-specific GraphQL queries |
| `GraphqlResponseMapper` | **No** | stripe | Maps OXID GraphQL response structure |
| `FallbackProductService` | **No** | stripe | Orchestrates OXID-specific implementations |
| `OxidProductService` | **No** | stripe | Already exists (Sprint 48) — unchanged |

---

## Part A: payment-component Changes

**No changes needed.** All required interfaces (`AcpProductServiceInterface`, `ProductFieldMapperInterface`) already exist.

---

## Part B: stripe Module Changes

### New Files

```
stripe/src/Stripe/Mcp/Service/
├── GraphqlProductService.php        # GraphQL-backed AcpProductServiceInterface
├── GraphqlQueryBuilder.php          # Builds GraphQL product queries
├── GraphqlResponseMapper.php        # Maps GraphQL response → ACP format
└── FallbackProductService.php       # Decorator: GraphQL → direct fallback
```

### B1. GraphqlProductService

**File:** `stripe/src/Stripe/Mcp/Service/GraphqlProductService.php`

Implements `AcpProductServiceInterface` by calling OXID's GraphQL Storefront API via internal HTTP loopback.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;

class GraphqlProductService implements AcpProductServiceInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GraphqlQueryBuilder $queryBuilder,
        private readonly GraphqlResponseMapper $responseMapper,
        private readonly string $graphqlEndpoint
    ) {
    }

    public function listProducts(array $filters = []): array
    {
        $query = $this->queryBuilder->buildProductsQuery($filters);
        $response = $this->sendQuery($query);

        if ($response === null) {
            throw new \RuntimeException('GraphQL endpoint returned invalid response');
        }

        return $this->responseMapper->mapProductListResponse($response, $filters);
    }

    public function getProduct(string $productId): ?array
    {
        $query = $this->queryBuilder->buildProductQuery($productId);
        $response = $this->sendQuery($query);

        if ($response === null) {
            return null;
        }

        return $this->responseMapper->mapSingleProductResponse($response);
    }

    /**
     * @return array<string, mixed>|null Decoded JSON response data
     */
    private function sendQuery(string $query): ?array
    {
        $body = json_encode(['query' => $query], JSON_THROW_ON_ERROR);
        $response = $this->httpClient->post(
            $this->graphqlEndpoint,
            $body,
            ['Content-Type' => 'application/json'],
            10
        );

        if (!$response->isSuccessful()) {
            return null;
        }

        $decoded = json_decode($response->getBody(), true);
        if (!is_array($decoded) || isset($decoded['errors'])) {
            return null;
        }

        return $decoded['data'] ?? null;
    }
}
```

### B2. GraphqlQueryBuilder

**File:** `stripe/src/Stripe/Mcp/Service/GraphqlQueryBuilder.php`

Builds GraphQL queries targeting OXID's `graphql-storefront` product schema.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

class GraphqlQueryBuilder
{
    private const PRODUCT_FIELDS = <<<'GRAPHQL'
        id
        title
        shortDescription
        longDescription
        price {
            price
            currency {
                name
                sign
            }
        }
        imageGallery {
            icon
            thumb
            images {
                image
            }
        }
        manufacturer {
            title
        }
        category {
            title
        }
        seo {
            url
        }
        rating
        stock
    GRAPHQL;

    /**
     * @param array<string, mixed> $filters
     */
    public function buildProductsQuery(array $filters): string
    {
        $limit = min(is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 20, 100);
        $offset = max(is_numeric($filters['offset'] ?? null) ? (int) $filters['offset'] : 0, 0);
        $search = isset($filters['search']) && is_string($filters['search']) ? $filters['search'] : null;
        $categoryId = isset($filters['category_id']) && is_string($filters['category_id'])
            ? $filters['category_id']
            : null;
        $sort = isset($filters['sort']) && is_string($filters['sort']) ? $filters['sort'] : null;

        $filterClause = $this->buildFilterClause($search, $categoryId);
        $sortClause = $this->buildSortClause($sort);
        $paginationClause = "pagination: { offset: {$offset}, limit: {$limit} }";

        $arguments = array_filter([$filterClause, $paginationClause, $sortClause]);
        $argumentsStr = implode(', ', $arguments);

        return <<<GRAPHQL
            {
                products({$argumentsStr}) {
                    {$this->getProductFields()}
                }
            }
            GRAPHQL;
    }

    public function buildProductQuery(string $productId): string
    {
        $escapedId = addslashes($productId);

        return <<<GRAPHQL
            {
                product(productId: "{$escapedId}") {
                    {$this->getProductFields()}
                }
            }
            GRAPHQL;
    }

    private function getProductFields(): string
    {
        return self::PRODUCT_FIELDS;
    }

    private function buildFilterClause(?string $search, ?string $categoryId): string
    {
        $filters = [];

        if ($search !== null && $search !== '') {
            $escapedSearch = addslashes($search);
            $filters[] = "title: { contains: \"{$escapedSearch}\" }";
        }

        if ($categoryId !== null && $categoryId !== '') {
            $escapedCategory = addslashes($categoryId);
            $filters[] = "category: { equals: \"{$escapedCategory}\" }";
        }

        if ($filters === []) {
            return '';
        }

        return 'filter: { ' . implode(', ', $filters) . ' }';
    }

    private function buildSortClause(?string $sort): string
    {
        if ($sort === null || $sort === '') {
            return '';
        }

        $allowed = ['price_asc', 'price_desc', 'title_asc', 'title_desc'];
        if (!in_array($sort, $allowed, true)) {
            return '';
        }

        [$field, $direction] = explode('_', $sort);

        return "sort: { {$field}: \"" . strtoupper($direction) . '" }';
    }
}
```

### B3. GraphqlResponseMapper

**File:** `stripe/src/Stripe/Mcp/Service/GraphqlResponseMapper.php`

Maps GraphQL response structure to ACP product format (same shape as `OxidProductFieldMapper` output).

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

class GraphqlResponseMapper
{
    /**
     * Map a GraphQL products list response to ACP format.
     *
     * @param array<string, mixed> $data GraphQL response data
     * @param array<string, mixed> $filters Original request filters
     * @return array<string, mixed> ACP product list
     */
    public function mapProductListResponse(array $data, array $filters): array
    {
        $graphqlProducts = $data['products'] ?? [];
        if (!is_array($graphqlProducts)) {
            return $this->emptyResult($filters);
        }

        $products = array_map(
            fn (array $product) => $this->mapProduct($product),
            $graphqlProducts
        );

        $limit = min(is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 20, 100);
        $offset = max(is_numeric($filters['offset'] ?? null) ? (int) $filters['offset'] : 0, 0);

        return [
            'products' => $products,
            'total' => count($products),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Map a single GraphQL product response to ACP format.
     *
     * @param array<string, mixed> $data GraphQL response data
     * @return array<string, mixed>|null ACP product or null
     */
    public function mapSingleProductResponse(array $data): ?array
    {
        $product = $data['product'] ?? null;
        if (!is_array($product)) {
            return null;
        }

        return $this->mapProduct($product);
    }

    /**
     * @param array<string, mixed> $product GraphQL product node
     * @return array<string, mixed> ACP product
     */
    private function mapProduct(array $product): array
    {
        $price = $product['price'] ?? [];
        $manufacturer = $product['manufacturer'] ?? [];
        $imageGallery = $product['imageGallery'] ?? [];
        $seo = $product['seo'] ?? [];
        $category = $product['category'] ?? [];

        $bruttoPrice = is_numeric($price['price'] ?? null) ? (float) $price['price'] : 0.0;
        $currencyName = is_string($price['currency']['name'] ?? null) ? $price['currency']['name'] : 'EUR';

        return [
            'id' => $product['id'] ?? '',
            'title' => $this->truncate((string) ($product['title'] ?? ''), 150),
            'description' => $this->truncate(
                strip_tags((string) ($product['longDescription'] ?? $product['shortDescription'] ?? '')),
                5000
            ),
            'url' => is_string($seo['url'] ?? null) ? $seo['url'] : '',
            'brand' => is_string($manufacturer['title'] ?? null) ? $manufacturer['title'] : '',
            'price' => number_format($bruttoPrice, 2, '.', ''),
            'currency' => $currencyName,
            'availability' => $this->mapAvailability($product),
            'image_url' => $this->extractPrimaryImage($imageGallery),
            'gtin' => null,
            'mpn' => null,
            'weight' => null,
            'group_id' => null,
            'rating' => is_numeric($product['rating'] ?? null) ? (float) $product['rating'] : null,
            'category' => is_string($category['title'] ?? null) ? $category['title'] : null,
            'seo_url' => is_string($seo['url'] ?? null) ? $seo['url'] : null,
        ];
    }

    private function mapAvailability(array $product): string
    {
        $stock = is_numeric($product['stock'] ?? null) ? (int) $product['stock'] : 0;

        if ($stock > 0) {
            return 'in_stock';
        }

        return 'out_of_stock';
    }

    private function extractPrimaryImage(array $gallery): string
    {
        $images = $gallery['images'] ?? [];
        if (is_array($images) && isset($images[0]['image']) && is_string($images[0]['image'])) {
            return $images[0]['image'];
        }

        if (is_string($gallery['thumb'] ?? null) && $gallery['thumb'] !== '') {
            return $gallery['thumb'];
        }

        return is_string($gallery['icon'] ?? null) ? $gallery['icon'] : '';
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function emptyResult(array $filters): array
    {
        $limit = min(is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 20, 100);
        $offset = max(is_numeric($filters['offset'] ?? null) ? (int) $filters['offset'] : 0, 0);

        return [
            'products' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
```

### B4. FallbackProductService (Decorator)

**File:** `stripe/src/Stripe/Mcp/Service/FallbackProductService.php`

Decorator that tries `GraphqlProductService` first and falls back to `OxidProductService` when GraphQL is unavailable or returns errors.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use Psr\Log\LoggerInterface;

class FallbackProductService implements AcpProductServiceInterface
{
    private ?bool $graphqlAvailable = null;

    public function __construct(
        private readonly GraphqlProductService $graphqlService,
        private readonly OxidProductService $directService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $graphqlEndpoint
    ) {
    }

    public function listProducts(array $filters = []): array
    {
        if (!$this->isGraphqlAvailable()) {
            return $this->directService->listProducts($filters);
        }

        try {
            return $this->graphqlService->listProducts($filters);
        } catch (\Throwable $e) {
            $this->logger->warning('GraphQL product query failed, falling back to direct model', [
                'error' => $e->getMessage(),
            ]);

            return $this->directService->listProducts($filters);
        }
    }

    public function getProduct(string $productId): ?array
    {
        if (!$this->isGraphqlAvailable()) {
            return $this->directService->getProduct($productId);
        }

        try {
            return $this->graphqlService->getProduct($productId);
        } catch (\Throwable $e) {
            $this->logger->warning('GraphQL single product query failed, falling back to direct model', [
                'error' => $e->getMessage(),
            ]);

            return $this->directService->getProduct($productId);
        }
    }

    private function isGraphqlAvailable(): bool
    {
        if ($this->graphqlAvailable !== null) {
            return $this->graphqlAvailable;
        }

        try {
            $response = $this->httpClient->post(
                $this->graphqlEndpoint,
                json_encode(['query' => '{ __typename }'], JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json'],
                3
            );

            $this->graphqlAvailable = $response->isSuccessful();
        } catch (\Throwable) {
            $this->graphqlAvailable = false;
        }

        if (!$this->graphqlAvailable) {
            $this->logger->info('GraphQL endpoint not available, using direct model access for products');
        }

        return $this->graphqlAvailable;
    }
}
```

---

## B5. services.yaml Changes

```yaml
# === Sprint 55: GraphQL Product Discovery ===

# GraphQL query builder (stateless)
OxidEsales\Payments\Stripe\Mcp\Service\GraphqlQueryBuilder: ~

# GraphQL response mapper (stateless)
OxidEsales\Payments\Stripe\Mcp\Service\GraphqlResponseMapper: ~

# GraphQL-backed product service (primary)
OxidEsales\Payments\Stripe\Mcp\Service\GraphqlProductService:
    arguments:
        $httpClient: '@OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface'
        $queryBuilder: '@OxidEsales\Payments\Stripe\Mcp\Service\GraphqlQueryBuilder'
        $responseMapper: '@OxidEsales\Payments\Stripe\Mcp\Service\GraphqlResponseMapper'
        $graphqlEndpoint: '%stripe.graphql.endpoint%'

# Fallback decorator: tries GraphQL, falls back to direct OXID model
OxidEsales\Payments\Stripe\Mcp\Service\FallbackProductService:
    arguments:
        $graphqlService: '@OxidEsales\Payments\Stripe\Mcp\Service\GraphqlProductService'
        $directService: '@OxidEsales\Payments\Stripe\Mcp\Service\OxidProductService'
        $httpClient: '@OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface'
        $logger: '@logger'
        $graphqlEndpoint: '%stripe.graphql.endpoint%'

# REBIND: AcpProductServiceInterface → FallbackProductService (was OxidProductService)
OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\FallbackProductService
    arguments:
        $graphqlService: '@OxidEsales\Payments\Stripe\Mcp\Service\GraphqlProductService'
        $directService: '@OxidEsales\Payments\Stripe\Mcp\Service\OxidProductService'
        $httpClient: '@OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface'
        $logger: '@logger'
        $graphqlEndpoint: '%stripe.graphql.endpoint%'

# New parameter
parameters:
    stripe.graphql.endpoint: 'http://localhost/graphql/'
```

**Key change:** `AcpProductServiceInterface` was bound to `OxidProductService` (Sprint 48). Now it binds to `FallbackProductService` which wraps both GraphQL and direct implementations.

**Existing services stay unchanged:**
- `OxidProductService` — still wired, used as fallback
- `OxidArticleQueryServiceInterface` — still wired for direct path
- `OxidProductFieldMapper` — still wired for direct path and CSV/JSONL feed generation
- `ProductFeedRequestHandler` — still uses feed generators (independent of product listing)

---

## Enhanced Product Data (GraphQL vs Current)

With GraphQL, `list_products` returns additional fields that AI agents can use:

| Field | Current (Direct) | GraphQL (New) |
|-------|------------------|---------------|
| `id` | OXID | OXID |
| `title` | OXTITLE (truncated) | title (truncated) |
| `description` | OXLONGDESC stripped | longDescription stripped |
| `url` | `?cl=details&anid=ID` | **SEO URL** (e.g., `/Wakeboarding/Trapeze.html`) |
| `brand` | manufacturer lookup | manufacturer.title |
| `price` | OXPRICE formatted | price.price formatted |
| `currency` | ShopAdapter config | **price.currency.name** (from product) |
| `availability` | stock+flag logic | stock-based |
| `image_url` | OXPIC1 resolved | **imageGallery** (multiple images) |
| `rating` | _not available_ | **product rating** (0-5) |
| `category` | _not available_ | **category.title** |
| `seo_url` | _not available_ | **seo.url** (clean URL) |
| `gtin` | OXEAN | _not in standard GraphQL_ |
| `mpn` | OXMPN | _not in standard GraphQL_ |
| `weight` | OXWEIGHT | _not in standard GraphQL_ |
| `group_id` | OXPARENTID | _not in standard GraphQL_ |

**Note:** GTIN, MPN, weight, and group_id are not in the standard GraphQL schema. When GraphQL is used, these return `null`. The direct model fallback provides them. This is an acceptable trade-off: the GraphQL path provides richer browsing data (SEO URLs, ratings, categories, images), while the direct path provides richer catalog-feed data (GTIN, MPN, weight).

---

## File Summary

| # | Module | File | Purpose | Est. Lines |
|---|--------|------|---------|-----------|
| 1 | stripe | `src/Stripe/Mcp/Service/GraphqlProductService.php` | GraphQL-backed product service | ~65 |
| 2 | stripe | `src/Stripe/Mcp/Service/GraphqlQueryBuilder.php` | Builds GraphQL product queries | ~100 |
| 3 | stripe | `src/Stripe/Mcp/Service/GraphqlResponseMapper.php` | Maps GraphQL → ACP format | ~130 |
| 4 | stripe | `src/Stripe/Mcp/Service/FallbackProductService.php` | Decorator with fallback | ~75 |
| | | **Total source** | | **~370** |

### Modified Files

| File | Change |
|------|--------|
| `services.yaml` | Add 4 new services, rebind `AcpProductServiceInterface`, add `stripe.graphql.endpoint` parameter |

### Unchanged Files (explicitly preserved)

| File | Reason |
|------|--------|
| `OxidProductService.php` | Still needed as fallback and for feed generation |
| `OxidArticleQueryService.php` | Still needed for direct model path |
| `OxidProductFieldMapper.php` | Still needed for direct model path and CSV/JSONL feeds |
| `ProductFeedRequestHandler.php` | Feed generation is independent of product listing source |
| `ListProductsTool.php` | Depends only on `AcpProductServiceInterface` — no changes needed |

---

## TDD Approach

### Step 1: GraphqlQueryBuilder Tests
- Test `buildProductsQuery()` with no filters (default pagination)
- Test with search filter (`title: { contains: "..." }`)
- Test with category filter
- Test with sort parameter (price_asc, title_desc)
- Test with combined search + pagination + sort
- Test limit capped at 100, offset minimum 0
- Test `buildProductQuery()` with product ID
- Test input sanitization (addslashes on user input)
- Test invalid sort values are ignored

### Step 2: GraphqlResponseMapper Tests
- Test `mapProductListResponse()` with full product data
- Test with minimal product data (missing optional fields)
- Test empty product list
- Test `mapSingleProductResponse()` with valid product
- Test null product returns null
- Test title truncation at 150 chars
- Test description HTML stripping and truncation at 5000 chars
- Test image extraction priority: images[0] > thumb > icon
- Test availability mapping: stock > 0 = in_stock, stock 0 = out_of_stock
- Test price formatting (2 decimal places)
- Test currency extraction from GraphQL price node
- Test SEO URL extraction
- Test rating extraction

### Step 3: GraphqlProductService Tests
- Mock `HttpClientInterface` and `GraphqlQueryBuilder` and `GraphqlResponseMapper`
- Test successful `listProducts()` flow (query → HTTP → parse → map)
- Test `listProducts()` with HTTP failure returns RuntimeException
- Test `listProducts()` with GraphQL errors in response returns RuntimeException
- Test `getProduct()` successful flow
- Test `getProduct()` returns null on HTTP failure
- Test `getProduct()` returns null on empty response

### Step 4: FallbackProductService Tests
- Mock `GraphqlProductService`, `OxidProductService`, `HttpClientInterface`, `LoggerInterface`
- Test uses GraphQL when endpoint is available (probe returns 200)
- Test falls back to direct when endpoint unavailable (probe fails)
- Test falls back to direct when GraphQL throws exception
- Test availability check is cached (probe called only once)
- Test `getProduct()` follows same fallback logic
- Test logger.warning called on GraphQL failure with fallback
- Test logger.info called when GraphQL unavailable

### Step 5: Full Validation
```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] `GraphqlQueryBuilder` generates valid GraphQL syntax
- [ ] `GraphqlQueryBuilder` handles empty/null filters gracefully
- [ ] `GraphqlQueryBuilder` sanitizes user input (search strings)
- [ ] `GraphqlResponseMapper` produces same ACP field structure as `OxidProductFieldMapper`
- [ ] `GraphqlResponseMapper` adds new fields (rating, category, seo_url)
- [ ] `GraphqlResponseMapper` handles missing/null fields without errors
- [ ] `GraphqlProductService` sends POST to configured endpoint with correct headers
- [ ] `GraphqlProductService` handles HTTP errors gracefully
- [ ] `FallbackProductService` probes GraphQL endpoint on first call only
- [ ] `FallbackProductService` caches availability check result
- [ ] `FallbackProductService` falls back to direct model on any GraphQL failure
- [ ] `FallbackProductService` logs warnings when falling back
- [ ] `ListProductsTool` works unchanged (depends only on interface)
- [ ] Feed generation (`ProductFeedRequestHandler`) works unchanged
- [ ] All existing 992+ tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Acceptance Criteria

1. When `graphql-storefront` is installed: `list_products` returns GraphQL-enriched data (SEO URLs, ratings, categories, image galleries)
2. When `graphql-storefront` is NOT installed: `list_products` returns the same data as before (direct model access — transparent fallback)
3. GraphQL endpoint availability is probed once and cached for the request lifecycle
4. Any GraphQL failure (network, parse, schema error) triggers automatic fallback with a warning log
5. Feed generation (`CsvFeedGenerator`, `JsonlFeedGenerator`) continues to work unchanged via `ProductFeedRequestHandler`
6. No breaking changes to `AcpProductServiceInterface` or `ListProductsTool`
7. The `stripe.graphql.endpoint` parameter is configurable via `services.yaml`
8. All quality gates pass: PHPCS, PHPStan (level max), PHPMD, 992+ existing tests

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| `graphql-storefront` not installed | Product tools return direct-model data | Automatic fallback, no user impact |
| GraphQL API shape changes across OXID versions | Response mapping breaks | Fallback catches exceptions, logs warning, uses direct model |
| HTTP loopback latency (~5-20ms) | Slightly slower product queries | Acceptable for AI agent tool calls (not real-time UI) |
| GraphQL returns fewer fields (no GTIN, MPN, weight) | Feed generation might miss fields | Feed generation uses `ProductFeedRequestHandler` which has its own `OxidProductFieldMapper` via direct path — unaffected |
| Probe request adds startup latency | First call slightly slower | Probe is fast (introspection query `{ __typename }`), result cached |
