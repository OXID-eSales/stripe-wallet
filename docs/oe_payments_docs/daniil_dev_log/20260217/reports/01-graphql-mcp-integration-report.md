# Report: How OXID GraphQL Storefront API Is Used by Our MCP Layer

**Date:** 2026-02-17
**Author:** Daniil (Claude-assisted)
**Context:** STRP-88 — documenting the GraphQL integration within the MCP/ACP tool layer

---

## 1. Executive Summary

Our MCP (Model Context Protocol) layer provides AI agents with commerce tools (`list_products`, `create_checkout`, etc.). For **product discovery**, the layer uses OXID's GraphQL Storefront API via an internal HTTP loopback, with automatic fallback to direct OXID model access when GraphQL is unavailable. For **checkout/payment**, the layer bypasses GraphQL entirely and uses the smart-contract architecture directly.

This hybrid approach was decided in Sprint 55 (2026-02-16) based on the analysis in [07-graphql-vs-direct-mcp-report.md](../20260213/reports/07-graphql-vs-direct-mcp-report.md).

---

## 2. Architecture Overview

```
AI Agent (MCP Client)
  |
  | JSON-RPC over HTTP (POST /mcp/)
  v
McpController → McpServer → ListProductsTool
                                |
                                v
                   AcpProductServiceInterface (abstraction)
                                |
                                v (resolves to)
                   FallbackProductService (decorator)
                    |                        |
                    v (primary)              v (fallback)
           GraphqlProductService      OxidProductService
            |         |       |        |              |
            v         v       v        v              v
     QueryBuilder  HTTP    Mapper  ArticleQuery  FieldMapper
                    |                   |
                    v                   v
           POST /graphql/        oxNew(ArticleList)
           (OXID GraphQL         (direct PHP model
            Storefront)           access, raw SQL)
```

### Key Design Decisions

1. **Decorator pattern** — `FallbackProductService` wraps both implementations transparently
2. **Interface segregation** — `AcpProductServiceInterface` is the only dependency for tools
3. **Probe-and-cache** — GraphQL availability checked once per request lifecycle via `{ __typename }` introspection (3s timeout)
4. **Zero breaking changes** — `ListProductsTool` is unchanged; service binding in `services.yaml` handles the switch

---

## 3. Component Details

### 3.1 ListProductsTool (payment-component)

**File:** `payment-component/src/Mcp/Acp/Tool/ListProductsTool.php`

The entry point for AI agents. Accepts search queries, category filters, pagination:

```php
public function execute(array $arguments, AgentContextInterface $agentContext): array
{
    return $this->productService->listProducts($arguments);
}
```

Input schema:
- `search` (string) — free-text search on title/description
- `category_id` (string) — filter by OXID category
- `limit` (int, max 100, default 20)
- `offset` (int, default 0)

The tool depends **only** on `AcpProductServiceInterface` — it has no knowledge of whether GraphQL or direct model access is used.

### 3.2 FallbackProductService (stripe module — orchestrator)

**File:** `stripe/src/Stripe/Mcp/Service/FallbackProductService.php`

The decorator that implements the failover strategy:

```
isGraphqlAvailable()?
  ├─ Yes → try GraphqlProductService
  │         ├─ Success → return enriched data
  │         └─ Failure → log warning, fall back to OxidProductService
  └─ No  → use OxidProductService directly
```

GraphQL availability is probed **once** via:
```php
$response = $this->httpClient->post(
    $this->graphqlEndpoint,          // http://localhost/graphql/
    json_encode(['query' => '{ __typename }']),
    ['Content-Type' => 'application/json'],
    3  // 3-second timeout
);
$this->graphqlAvailable = $response->isSuccessful();  // cached
```

### 3.3 GraphqlQueryBuilder (stripe module — query construction)

**File:** `stripe/src/Stripe/Mcp/Service/GraphqlQueryBuilder.php`

Translates MCP tool input into OXID GraphQL Storefront queries:

```graphql
{
  products(
    filter: { title: { contains: "shoes" } }
    pagination: { offset: 0, limit: 20 }
    sort: { price: "ASC" }
  ) {
    id
    title
    shortDescription
    longDescription
    price { price currency { name sign } }
    imageGallery { icon thumb images { image } }
    manufacturer { title }
    category { title }
    seo { url }
    rating
    stock
  }
}
```

Features:
- **Sanitization**: `addslashes()` on all user-provided strings
- **Sort whitelist**: Only `price_asc`, `price_desc`, `title_asc`, `title_desc`
- **Bounds checking**: limit capped at 100, offset floored at 0
- **Category filter**: Uses GraphQL's `category: { equals: "..." }` filter

### 3.4 GraphqlProductService (stripe module — HTTP transport)

**File:** `stripe/src/Stripe/Mcp/Service/GraphqlProductService.php`

Sends the built query to OXID's GraphQL endpoint via internal HTTP loopback:

```php
$body = json_encode(['query' => $query]);
$response = $this->httpClient->post(
    $this->graphqlEndpoint,   // configured as stripe.graphql.endpoint parameter
    $body,
    ['Content-Type' => 'application/json'],
    10  // 10-second timeout for product queries
);
```

Error handling:
- HTTP failure → returns null → caught by FallbackProductService → triggers fallback
- GraphQL `errors` key in response → returns null → fallback
- Invalid JSON → returns null → fallback

### 3.5 GraphqlResponseMapper (stripe module — data transformation)

**File:** `stripe/src/Stripe/Mcp/Service/GraphqlResponseMapper.php`

Maps OXID GraphQL response structure to the ACP product format (same contract as `OxidProductFieldMapper`):

```php
[
    'id'           => 'prod123',
    'title'        => 'Premium Wakeboard',          // truncated to 150 chars
    'description'  => 'Professional wakeboard...',   // strip_tags, truncated to 5000
    'url'          => '/Watersports/Wakeboard.html', // SEO URL (GraphQL-only)
    'brand'        => 'Liquid',                      // manufacturer.title
    'price'        => '299.99',                      // number_format(2)
    'currency'     => 'EUR',                         // price.currency.name
    'availability' => 'in_stock',                    // stock > 0 ? in_stock : out_of_stock
    'image_url'    => '/images/wakeboard.jpg',       // imageGallery.images[0]
    'rating'       => 4.5,                           // GraphQL-only
    'category'     => 'Watersports',                 // GraphQL-only
    'seo_url'      => '/Watersports/Wakeboard.html', // GraphQL-only
    'gtin'         => null,                          // not in GraphQL schema
    'mpn'          => null,                          // not in GraphQL schema
    'weight'       => null,                          // not in GraphQL schema
    'group_id'     => null,                          // not in GraphQL schema
]
```

All mixed-type GraphQL data is guarded with `is_string()`, `is_array()`, `is_numeric()` for PHPStan compliance.

---

## 4. Data Enrichment: GraphQL vs Direct

| Field | Direct Model | GraphQL | Benefit |
|-------|-------------|---------|---------|
| `url` | `?cl=details&anid=ID` | **SEO URL** | Clean, shareable URLs |
| `currency` | Shop config default | **Per-product** from price graph | Multi-currency support |
| `image_url` | OXPIC1 field only | **imageGallery** (icon/thumb/full) | Higher quality images |
| `rating` | N/A | **Product rating** (0-5) | AI agent decision support |
| `category` | N/A | **category.title** | Contextual grouping |
| `seo_url` | N/A | **seo.url** | SEO-friendly links |
| `gtin`/`mpn`/`weight` | Available | null | Only from direct path |

---

## 5. Service Wiring (services.yaml)

```yaml
# Sprint 55 additions (services.yaml lines 1115-1143)

OxidEsales\Payments\Stripe\Mcp\Service\GraphqlQueryBuilder: ~
OxidEsales\Payments\Stripe\Mcp\Service\GraphqlResponseMapper: ~

OxidEsales\Payments\Stripe\Mcp\Service\GraphqlProductService:
  arguments:
    $httpClient: '@OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface'
    $queryBuilder: '@OxidEsales\Payments\Stripe\Mcp\Service\GraphqlQueryBuilder'
    $responseMapper: '@OxidEsales\Payments\Stripe\Mcp\Service\GraphqlResponseMapper'
    $graphqlEndpoint: '%stripe.graphql.endpoint%'

OxidEsales\Payments\Stripe\Mcp\Service\FallbackProductService:
  arguments:
    $graphqlService: '@...\GraphqlProductService'
    $directService: '@...\OxidProductService'
    $httpClient: '@...\HttpClientInterface'
    $logger: '@oxid_esales.monolog.logger'
    $graphqlEndpoint: '%stripe.graphql.endpoint%'

# Interface binding: AcpProductServiceInterface → FallbackProductService
OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface:
  class: OxidEsales\Payments\Stripe\Mcp\Service\FallbackProductService
  # ... (same arguments as above)

parameters:
  stripe.graphql.endpoint: 'http://localhost/graphql/'
```

---

## 6. Why GraphQL Only for Product Discovery

| Aspect | Product Discovery | Checkout |
|--------|------------------|----------|
| **GraphQL fit** | Excellent | Incompatible |
| **Reason** | Read-only queries, rich filtering, no auth needed | `placeOrder` creates orders immediately — conflicts with contract-first pattern |
| **Our approach** | GraphQL `products` query via HTTP loopback | Direct: `StripeAcpCheckoutService` → event chain → `PaymentContract` |
| **Session state** | None needed | Contract ID is the only state (stateless for agent) |
| **Cancellation** | N/A | Contract state machine `cancel()` — GraphQL has no equivalent |

The smart-contract architecture (`DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED`) is fundamentally incompatible with GraphQL's `placeOrder` mutation, which creates OXID orders immediately.

---

## 7. Test Coverage

| Test File | Tests | Assertions | Coverage |
|-----------|-------|------------|----------|
| `GraphqlQueryBuilderTest` | 25 | 50 | Pagination, filters, sorting, sanitization |
| `GraphqlResponseMapperTest` | 42 | 62 | Field mapping, truncation, null handling |
| `GraphqlProductServiceTest` | 15 | 39 | HTTP flow, 5 failure modes |
| `FallbackProductServiceTest` | 15 | 42 | Availability caching, fallback logic, logging |
| **Total** | **97** | **193** | |

---

## 8. Sequence Diagram: Full list_products Call

```
Agent          McpController      McpServer      ListProductsTool     FallbackProductService    GraphqlProductService    OXID GraphQL
  |                  |                |                |                      |                        |                     |
  |--POST /mcp/----->|                |                |                      |                        |                     |
  |                  |--handleJsonRpc>|                |                      |                        |                     |
  |                  |                |--execute------->|                      |                        |                     |
  |                  |                |                |--listProducts-------->|                        |                     |
  |                  |                |                |                      |--isGraphqlAvailable?    |                     |
  |                  |                |                |                      |  (first call only)      |                     |
  |                  |                |                |                      |--POST { __typename }----|-------------------->|
  |                  |                |                |                      |<---200 OK---------------|<--------------------|
  |                  |                |                |                      |  (cache: available=true) |                     |
  |                  |                |                |                      |                        |                     |
  |                  |                |                |                      |--listProducts---------->|                     |
  |                  |                |                |                      |                        |--buildQuery          |
  |                  |                |                |                      |                        |--POST /graphql/----->|
  |                  |                |                |                      |                        |<--products response--|
  |                  |                |                |                      |                        |--mapResponse         |
  |                  |                |                |                      |<--ACP products---------|                     |
  |                  |                |                |<--products-----------|                        |                     |
  |                  |                |<--result--------|                      |                        |                     |
  |                  |<--JSON-RPC-----|                |                      |                        |                     |
  |<--200 OK---------|                |                |                      |                        |                     |
```

---

## 9. Conclusion

The GraphQL integration is a **surgical, well-bounded enhancement** that enriches product discovery data for AI agents while preserving the existing direct-model infrastructure for checkout and feed generation. The decorator pattern ensures zero risk — any GraphQL failure silently degrades to the direct path, and no existing functionality is affected.
