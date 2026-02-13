# Report: OXID GraphQL as Backend for MCP Tool Requests

**Date:** 2026-02-13
**Author:** Daniil (Claude-assisted)
**Context:** STRP-88 MCP/ACP/UCP integration — evaluating whether OXID's GraphQL Storefront API could replace or augment the current direct-model approach for MCP tool backends.

---

## 1. Executive Summary

OXID eShop provides a mature GraphQL API (`graphql-storefront` v12.0) that covers the full commerce lifecycle: product catalog, basket management, checkout, and order placement. Our current MCP tools use **direct OXID model access** (oxNew, ArticleList, raw SQL). This report evaluates whether switching to GraphQL as the backend for MCP requests would be beneficial.

**Recommendation:** Use a **hybrid approach** — adopt GraphQL for product discovery (`list_products`) to get richer data and built-in filtering, but keep direct model access for checkout/contract operations where GraphQL's PlaceOrder flow conflicts with our smart-contract architecture.

---

## 2. Current Architecture (Direct Model Access)

### Product Listing (`list_products`)
```
ListProductsTool
  → OxidProductService::listProducts()
    → OxidArticleQueryService::findArticles()   ← oxNew(ArticleList::class), raw SQL
    → OxidProductFieldMapper::mapProduct()       ← manual field extraction
```

- Uses `ArticleList::loadSearchArticles()` and `selectString()` (raw SQL)
- Manual field mapping: `getFieldData('oxtitle')`, `getPrice()`, etc.
- No pagination metadata, no variant selection, no SEO URLs
- Limited search: OXID's built-in full-text only

### Checkout Creation (`create_checkout`)
```
CreateCheckoutTool
  → StripeAcpCheckoutService::createCheckout()
    → StripeCheckoutSessionRequestEvent (dispatched)
      → StripeContractCreationHandler   ← creates PaymentContract
      → StripeCheckoutSessionHandler    ← creates Stripe session
```

- Creates a **PaymentContract** (smart-contract pattern), NOT an OXID order
- Basket stored as immutable JSON snapshot in `oe_payments_contract`
- Order created later via `EarlyOrderCreationHandler` when contract transitions
- No dependency on OXID's basket/session system

---

## 3. OXID GraphQL API Surface (Relevant Subset)

### Product Queries (No Auth Required)
| Query | Purpose | Filtering |
|-------|---------|-----------|
| `products(filter, pagination, sort)` | List products | title contains, price range, category, manufacturer |
| `product(productId)` | Single product | Direct ID lookup |
| `categories(filter, pagination, sort)` | Browse categories | title, active status |
| `variantSelections(productId, varSelIds)` | Variant options | Selection-based |

**Product data includes:** id, title, description, price (with currency), images, manufacturer, vendor, category, attributes, reviews, ratings, SEO URLs, stock status, variants.

### Basket & Checkout Mutations (Auth Required)
| Mutation | Purpose |
|----------|---------|
| `basketCreate(title, public)` | Create empty basket |
| `basketAddItem(basketId, productId, amount)` | Add products |
| `basketSetDeliveryMethod(basketId, deliveryMethodId)` | Set shipping |
| `basketSetPayment(basketId, paymentId)` | Set payment method |
| `placeOrder(basketId, confirmTermsAndConditions)` | Create order |

### Authentication
- `token(username, password)` → JWT (anonymous token if no credentials)
- Bearer token in `Authorization` header for all mutations
- Anonymous users can checkout via express flow with proper permissions

---

## 4. Comparison: GraphQL vs Direct Model Access

### 4.1 Product Listing (`list_products`)

| Aspect | Current (Direct) | GraphQL |
|--------|------------------|---------|
| **Data richness** | Manual field mapping, limited fields | Full product graph: variants, SEO, reviews, images |
| **Search** | Basic OXID full-text | Same OXID full-text via `StringFilter.contains` |
| **Filtering** | Custom SQL WHERE clauses | Built-in: price range, category, manufacturer, active status |
| **Pagination** | Manual LIMIT/OFFSET | Native `pagination: { offset, limit }` with metadata |
| **Sorting** | Hardcoded `OXTIMESTAMP DESC` | `sort: { price: "ASC", title: "DESC" }` |
| **Variants** | Not supported | `variantSelections` query |
| **SEO URLs** | Not included | Included in product graph |
| **Performance** | In-process PHP, fast | HTTP loopback to `/graphql/`, slower |
| **Maintenance** | We maintain field mapper + SQL | OXID maintains, auto-updates with shop |
| **Dependencies** | oxNew (OXID core) | `graphql-storefront` module must be installed |

**Verdict: GraphQL is better for `list_products`** — richer data, less custom code to maintain, and the performance overhead of a local HTTP call is acceptable for a tool that returns product catalogs.

### 4.2 Checkout Flow (`create_checkout`, `cancel_checkout`, `complete_checkout`)

| Aspect | Current (Smart Contract) | GraphQL PlaceOrder |
|--------|--------------------------|-------------------|
| **Architecture** | Contract-first: contract → conditions → order | Order-first: basket → order directly |
| **Basket** | Immutable JSON snapshot in `oe_payments_contract` | Mutable OXID basket (session-tied) |
| **Order timing** | Order created when contract reaches `READY_TO_COMMIT` | Order created immediately on `placeOrder` |
| **Cancellation** | Contract state machine: `cancel()` transition | No built-in cancel (order must be stornoed) |
| **Agent context** | Stored in contract metadata (`acp_agent_id`) | No concept of agent context |
| **Payment** | Stripe Session + SPT token flow | `BeforePlaceOrder` event hook |
| **Idempotency** | Built-in via `oe_payments_idempotency` table | No built-in idempotency |
| **Stateless** | Contract ID is the only state needed | Requires maintaining basket session + JWT |

**Verdict: Direct model access is essential for checkout** — our smart-contract architecture fundamentally differs from GraphQL's PlaceOrder flow. GraphQL creates orders immediately; we create contracts that become orders only after all conditions are met. These are incompatible paradigms.

### 4.3 Hybrid Approach Details

```
MCP Tool Layer
├── list_products     → GraphQL `products` query (via internal HTTP or direct service call)
├── create_checkout   → Direct: StripeAcpCheckoutService (smart contract)
├── get_checkout      → Direct: ContractRepository::findById()
├── update_checkout   → Direct: ContractService::updateContract()
├── cancel_checkout   → Direct: ContractService::cancelContract()
└── complete_checkout → Direct: StripeAcpCheckoutService::completePayment()
```

---

## 5. Implementation Options for GraphQL Product Feed

### Option A: Internal HTTP Loopback (Recommended)
```php
class GraphqlProductService implements AcpProductServiceInterface
{
    public function listProducts(array $filters, int $limit, int $offset): array
    {
        $query = $this->buildProductsQuery($filters, $limit, $offset);
        $response = $this->httpClient->post('http://localhost/graphql/', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['query' => $query],
        ]);
        return $this->mapGraphqlResponse($response);
    }
}
```

**Pros:** Clean separation, uses OXID's official API contract, automatically inherits shop language/currency context.
**Cons:** HTTP overhead (~5-20ms per call), requires `graphql-storefront` module installed.

### Option B: Direct GraphQL Service Call (Advanced)
```php
// Call GraphQL resolver directly without HTTP, using OXID's internal service
$container = ContainerFactory::getInstance()->getContainer();
$productService = $container->get(ProductService::class); // GraphQL internal service
$products = $productService->products($filterList, $pagination, $sorting);
```

**Pros:** No HTTP overhead, in-process.
**Cons:** Tight coupling to GraphQL module internals, may break across versions, bypasses auth/middleware.

### Option C: Keep Direct + Add GraphQL Fields (Pragmatic)
Enhance the current `OxidProductFieldMapper` to include the missing fields (variants, SEO URLs, images) without switching to GraphQL. Essentially copy what GraphQL does internally.

**Pros:** No new dependencies, fastest performance.
**Cons:** More custom code to maintain, duplicates what GraphQL already does.

---

## 6. GraphQL Product Query Example

What our `list_products` MCP tool could return with GraphQL:

```graphql
query {
  products(
    filter: { title: { contains: "shirt" } }
    pagination: { offset: 0, limit: 20 }
    sort: { price: "ASC" }
  ) {
    id
    title
    shortDescription
    longDescription
    price {
      price
      currency { name sign }
    }
    imageGallery {
      icon
      thumb
      images { image }
    }
    manufacturer { title }
    category { title }
    seo { url }
    rating
    stock
    variantSelections(varSelIds: []) {
      name
      alternatives { name value }
    }
  }
}
```

This single query returns everything an AI agent needs to make purchasing decisions — far richer than our current 8-field manual mapping.

---

## 7. Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| `graphql-storefront` not installed | MCP product tools fail | Feature-flag: fall back to direct model if GraphQL unavailable |
| GraphQL API changes across OXID versions | Breaking changes | Pin to specific GraphQL module version in composer.json |
| HTTP loopback latency | Slower product queries | Use Option B (direct service call) or cache results |
| Anonymous auth complexity | Token management overhead | Use anonymous token (no credentials needed for product queries) |
| Product queries don't need auth | N/A for products | Products query is public — no token needed |

---

## 8. Recommendation

### Phase 1 (Now): Keep direct model access
The current implementation works, all tests pass, and the checkout flow requires our smart-contract architecture. No GraphQL needed for MVP.

### Phase 2 (Post-MVP): GraphQL for product discovery
Replace `OxidProductService` + `OxidArticleQueryService` + `OxidProductFieldMapper` with a single `GraphqlProductService` that:
1. Calls the OXID GraphQL `/graphql/` endpoint internally
2. Returns richer product data (variants, images, SEO, reviews)
3. Leverages built-in filtering, pagination, and sorting
4. Falls back to direct model access if GraphQL module is unavailable

### Never: GraphQL for checkout
The `placeOrder` mutation creates orders immediately — incompatible with our contract-first pattern. The `basketCreate` → `basketAddItem` → `placeOrder` flow assumes mutable server-side baskets with session state, while our ACP checkout uses immutable contract snapshots. These are fundamentally different paradigms.

---

## 9. Conclusion

OXID's GraphQL API is a **natural fit for product discovery** but a **poor fit for checkout** in an ACP/MCP context. The PlaceOrder flow assumes a traditional e-commerce session, while our smart-contract architecture decouples payment conditions from order creation. A hybrid approach — GraphQL for catalog, direct for contracts — gives us the best of both worlds with minimal risk.
