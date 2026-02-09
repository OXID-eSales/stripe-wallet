# ACP/MCP Foundations for Agentic Commerce

**Date:** 2026-02-09
**Sprint:** 47 (prerequisite research)
**Status:** Complete

---

## Executive Summary

Two complementary protocols enable AI agents to conduct commerce on behalf of humans:

- **MCP (Model Context Protocol)** — the intelligence layer: how agents discover tools, resources, and capabilities
- **ACP (Agentic Commerce Protocol)** — the transaction layer: how agents execute purchases securely

Together they form the foundation for **agentic commerce** — AI agents that research, negotiate, and complete purchases without human micro-management of each step.

This report covers the technical foundations of both protocols and analyzes how they map to the existing stripe module architecture.

---

## 1. Model Context Protocol (MCP)

### 1.1 Origins

Released by Anthropic in November 2024. Adopted by OpenAI in March 2025. Donated to the **Agentic AI Foundation (AAIF)** under the Linux Foundation in December 2025, co-founded by Anthropic, Block, and OpenAI. Current spec version: `2025-11-25`.

### 1.2 Architecture

MCP is a **client-server protocol** built on JSON-RPC 2.0:

```
┌─────────────────────────────────────────────────────┐
│              MCP Host (AI Application)              │
│  e.g., Claude Desktop, ChatGPT, custom agent        │
│                                                      │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐    │
│  │ MCP Client │  │ MCP Client │  │ MCP Client │    │
│  └──────┬─────┘  └──────┬─────┘  └──────┬─────┘    │
└─────────┼───────────────┼───────────────┼───────────┘
          │               │               │
   ┌──────▼──────┐ ┌──────▼──────┐ ┌──────▼──────┐
   │ MCP Server  │ │ MCP Server  │ │ MCP Server  │
   │ (local)     │ │ (local)     │ │ (remote)    │
   │ Filesystem  │ │ Database    │ │ Stripe      │
   └─────────────┘ └─────────────┘ └─────────────┘
```

**Three participant types:**

| Role | Description |
|------|-------------|
| **Host** | AI application that coordinates MCP clients (Claude, ChatGPT, custom agent) |
| **Client** | Component within host maintaining a connection to one MCP server |
| **Server** | Program exposing tools, resources, and prompts to clients |

### 1.3 Transport Mechanisms

| Transport | Use Case | Auth |
|-----------|----------|------|
| **STDIO** | Local process communication, no network overhead | N/A (process-level) |
| **Streamable HTTP** | Remote servers, HTTP POST + optional SSE streaming | OAuth, Bearer tokens, API keys |

### 1.4 Server Primitives

MCP servers expose three types of capabilities:

**Tools** — executable functions the AI can invoke:

```json
{
  "name": "create_checkout_session",
  "title": "Create Checkout Session",
  "description": "Create a Stripe Checkout Session for the given cart",
  "inputSchema": {
    "type": "object",
    "properties": {
      "items": {
        "type": "array",
        "items": {
          "type": "object",
          "properties": {
            "product_id": { "type": "string" },
            "quantity": { "type": "integer" }
          },
          "required": ["product_id", "quantity"]
        }
      },
      "currency": { "type": "string", "default": "EUR" }
    },
    "required": ["items"]
  }
}
```

**Resources** — data sources (product catalogs, order history, configuration).

**Prompts** — reusable templates for LLM interactions (checkout assistance, product recommendations).

### 1.5 Lifecycle

Stateful protocol with capability negotiation handshake:

1. Client sends `initialize` with `protocolVersion`, `capabilities`, `clientInfo`
2. Server responds with its `protocolVersion`, `capabilities`, `serverInfo`
3. Client sends `notifications/initialized`
4. Connection active — client can call `tools/list`, `tools/call`, `resources/list`, etc.

### 1.6 JSON-RPC Message Format

Three message types:

```json
// Request (expects response)
{ "jsonrpc": "2.0", "id": 1, "method": "tools/call",
  "params": { "name": "create_checkout", "arguments": { "items": [...] } } }

// Response
{ "jsonrpc": "2.0", "id": 1,
  "result": { "content": [{ "type": "text", "text": "Session created: cs_abc" }] } }

// Notification (no response, no id)
{ "jsonrpc": "2.0", "method": "notifications/tools/list_changed" }
```

### 1.7 PHP SDK

Official PHP SDK (`mcp/sdk`) maintained with The PHP Foundation. PHP 8.1+, PSR standards. **Status: experimental pre-v1.0.**

```php
// Attribute-based tool registration
#[McpTool]
public function createCheckout(array $items, string $currency = 'EUR'): array
{
    // ...
}

// Server builder
$server = Server::builder()
    ->setServerInfo('oxid-stripe-mcp', '1.0.0')
    ->setDiscovery(__DIR__, ['src'])
    ->build();
$server->run(new StdioTransport());
```

### 1.8 Stripe's Existing MCP Server

Stripe already provides an MCP server at `https://mcp.stripe.com` with 30+ tools covering customers, products, payments, subscriptions, invoices. Authentication via OAuth or restricted API keys.

---

## 2. Agentic Commerce Protocol (ACP)

### 2.1 Origins

Co-developed by **Stripe and OpenAI**. First release: September 29, 2025. Apache 2.0 license.

**Spec versions:**

| Version | Key Addition |
|---------|-------------|
| `2025-09-29` | Initial release |
| `2025-12-12` | Fulfillment enhancements |
| `2026-01-16` | Capability negotiation |
| `2026-01-30` | Extensions, discounts, payment handlers |

### 2.2 Four Parties

```
┌──────────┐     ┌──────────┐     ┌──────────────┐     ┌──────────────┐
│  Buyer   │────▶│ AI Agent │────▶│  Merchant    │────▶│  Payment     │
│ (Human)  │     │ (ChatGPT,│     │ (OXID Shop   │     │  Provider    │
│          │     │  Claude)  │     │  + Stripe)   │     │  (Stripe)    │
└──────────┘     └──────────┘     └──────────────┘     └──────────────┘
 Discovers        Interfaces       Merchant of          Processes
 products,        with buyer,      record: products,    payment via
 approves         executes         pricing, tax,        Shared Payment
 payment          checkout         fulfillment          Tokens
```

The **merchant remains merchant of record** — full control over products, pricing, transactions, and fulfillment.

### 2.3 Three Specifications

**1. Product Feed Specification** — Catalog syndication to AI agents

- Formats: TSV (recommended), CSV, XML, JSON
- Required fields: `id`, `title`, `description`, `price`, `currency`, `availability`, `image_url`
- Refresh: up to every 15 minutes
- Security: HTTPS, Bearer token, IP whitelisting

**2. Agentic Checkout Specification** — REST endpoints for checkout lifecycle

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/checkouts` | POST | Create checkout session |
| `/checkouts/:id` | GET | Retrieve checkout |
| `/checkouts/:id` | PUT | Update checkout (shipping, options) |
| `/checkouts/:id/complete` | POST | Finalize payment |
| `/checkouts/:id/cancel` | POST | Cancel checkout |

**Checkout statuses:** `not_ready_for_payment` → `ready_for_payment` → `completed` (or `canceled`)

**3. Delegated Payment Specification** — Shared Payment Tokens (SPTs)

SPTs are Stripe's payment primitive for agentic commerce:

| Property | Description |
|----------|-------------|
| Scoped | Bound to a specific seller (`network_id`) |
| Time-bound | Expires at specified timestamp |
| Amount-limited | Maximum transaction amount |
| One-time use | Linked to specific checkout session |
| Revocable | Can be cancelled at any time |
| Observable | Webhook events for lifecycle tracking |

### 2.4 Checkout Create Request

```json
{
  "items": [
    { "id": "sku_123", "quantity": 2 }
  ],
  "buyer": {
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com"
  },
  "fulfillment_address": {
    "name": "Jane Doe",
    "line_one": "123 Main St",
    "city": "San Francisco",
    "state": "CA",
    "country": "US",
    "postal_code": "94105"
  }
}
```

### 2.5 Checkout Complete Request

```json
{
  "payment_data": {
    "token": "spt_granted_abc123",
    "provider": "stripe",
    "billing_address": {
      "line_one": "123 Main St",
      "city": "San Francisco",
      "state": "CA",
      "country": "US",
      "postal_code": "94105"
    }
  }
}
```

### 2.6 Checkout Response Model

```json
{
  "id": "cs_abc123",
  "status": "ready_for_payment",
  "currency": "eur",
  "line_items": [
    {
      "id": "li_1",
      "item": { "id": "sku_123", "quantity": 2 },
      "base_amount": 5000,
      "discount": 0,
      "subtotal": 5000,
      "tax": 950,
      "total": 5950
    }
  ],
  "fulfillment_options": [
    {
      "id": "ship_standard",
      "type": "shipping",
      "label": "Standard Shipping",
      "subtotal": 500,
      "tax": 95,
      "total": 595
    }
  ],
  "totals": [
    { "type": "subtotal", "amount": 5000 },
    { "type": "fulfillment", "amount": 500 },
    { "type": "tax", "amount": 1045 },
    { "type": "total", "amount": 6545 }
  ],
  "payment_providers": [
    { "provider": "stripe", "supported_payment_methods": ["card"] }
  ]
}
```

### 2.7 Order Response (post-completion)

```json
{
  "id": "order_abc123",
  "checkout_session_id": "cs_abc123",
  "permalink_url": "https://shop.example.com/orders/abc123"
}
```

**Order status lifecycle:** `created` → `manual_review` → `confirmed` → `shipped` → `fulfilled` (or `canceled`)

### 2.8 Error Handling

**Error types:** `invalid_request`, `request_not_idempotent`, `processing_error`, `service_unavailable`

**Message types:**

| Type | Purpose |
|------|---------|
| `InfoMessage` | Informational notification (plain/markdown) |
| `ErrorMessage` | Error with code, param, content |

**Error codes:** `missing`, `invalid`, `out_of_stock`, `payment_declined`, `requires_sign_in`, `requires_3ds`

### 2.9 ACP Can Be Implemented as MCP Server

ACP endpoints can be exposed as MCP tools. This means a single MCP server can provide both data access (resources) and transactional capabilities (ACP checkout tools).

---

## 3. How MCP/ACP Map to the Stripe Module

### 3.1 The Relationship

```
┌─────────────────────────────────────────────────────────────┐
│                    AI Agent (MCP Client)                     │
│              Claude, ChatGPT, custom agent                   │
└───────────────────────┬─────────────────────────────────────┘
                        │ JSON-RPC 2.0 / HTTP
┌───────────────────────▼─────────────────────────────────────┐
│              MCP Server Layer (NEW)                          │
│  Exposes: tools, resources, prompts                          │
│  Transport: Streamable HTTP (remote)                         │
│                                                              │
│  ┌──────────────────┐  ┌──────────────────────────────┐     │
│  │ ACP Checkout      │  │ MCP Resources                │     │
│  │ Tools             │  │ (product catalog,             │     │
│  │ (create, update,  │  │  order status,               │     │
│  │  complete, cancel)│  │  shop config)                │     │
│  └────────┬─────────┘  └──────────────┬───────────────┘     │
└───────────┼────────────────────────────┼────────────────────┘
            │                            │
┌───────────▼────────────────────────────▼────────────────────┐
│              Existing Stripe Module                          │
│  EventSystem → Handlers → Services → StripeAdapter           │
│  ContractService → ContractRepository                        │
└─────────────────────────────────────────────────────────────┘
```

### 3.2 Mapping ACP Endpoints to Existing Architecture

| ACP Endpoint | Maps To | Existing Component |
|-------------|---------|-------------------|
| `POST /checkouts` | Create contract + checkout session | `StripeCheckoutSessionRequestEvent` → handler chain |
| `GET /checkouts/:id` | Read contract state | `ContractRepositoryInterface::findById()` |
| `PUT /checkouts/:id` | Update contract (shipping, options) | `ContractServiceInterface` + metadata |
| `POST /checkouts/:id/complete` | Complete payment with SPT | `StripeCheckoutReturnHandler` pathway (adapted for SPT) |
| `POST /checkouts/:id/cancel` | Cancel contract | `PaymentContract::cancel()` |

### 3.3 Mapping MCP Primitives to OXID/Stripe

| MCP Primitive | OXID/Stripe Source |
|---------------|-------------------|
| **Tool: product_search** | OXID article repository (`oxarticles` table) |
| **Tool: create_checkout** | Event dispatch: `StripeCheckoutSessionRequestEvent` |
| **Tool: get_checkout_status** | `ContractRepository::findById()` + state mapping |
| **Tool: complete_checkout** | SPT-based payment confirmation (new) |
| **Tool: cancel_checkout** | Contract cancellation (existing) |
| **Resource: product_catalog** | OXID category/article APIs |
| **Resource: order_status** | Contract + OXID order state |
| **Resource: shop_config** | `ModuleConfigurationService` (currencies, shipping) |

### 3.4 What Is New vs. What Exists

**Already exists (reuse):**
- Contract lifecycle (state machine, conditions, metadata)
- Event system (handlers, dispatcher, context)
- Checkout session creation (handler chain)
- Contract repository (CRUD, state queries)
- Idempotency (duplicate prevention)
- Webhook processing pipeline

**Must be built:**
- MCP server layer (PHP, Streamable HTTP transport)
- ACP checkout endpoint controllers (REST or MCP tools)
- SPT payment flow (Shared Payment Token confirmation)
- Product feed generation (catalog → ACP format)
- ACP-to-contract state mapping
- ACP response formatters (line items, totals, fulfillment options)
- Agent authentication/authorization

---

## 4. Security Considerations

### 4.1 Authentication

- MCP remote servers require OAuth or Bearer token auth
- ACP requires HTTPS with Bearer token + HMAC webhook signatures
- SPTs are scoped, time-limited, and one-time-use by design

### 4.2 Fraud Risks

- **78% of financial institutions** expect increased fraud from agentic commerce
- Attack vectors: AI-created fake storefronts, bot-initiated transactions, conversational social engineering
- Mitigation: Stripe Radar integration (already in module), SPT amount limits, agent identity verification

### 4.3 Authorization Model

- SPTs enforce: seller scope, amount limit, time expiry, single use
- Agent must be pre-authorized by buyer (consent flow)
- Module must validate SPT before creating PaymentIntent

---

## 5. Competing Protocols

| Protocol | Creator | Transport | Status |
|----------|---------|-----------|--------|
| **ACP** | Stripe + OpenAI | REST, MCP | Active, Apache 2.0 |
| **UCP** | Google | REST, gRPC, GraphQL | Active, broader scope |
| **MCP** | Anthropic → Linux Foundation | STDIO, HTTP | Stable, widely adopted |

ACP and UCP will likely coexist. Our implementation should be protocol-agnostic where possible, with ACP as the first concrete implementation.

---

## References

- [MCP Specification (2025-11-25)](https://modelcontextprotocol.io/specification/2025-11-25)
- [ACP GitHub Repository](https://github.com/agentic-commerce-protocol/agentic-commerce-protocol)
- [Stripe ACP Docs](https://docs.stripe.com/agentic-commerce/protocol)
- [Stripe MCP Server](https://docs.stripe.com/mcp)
- [MCP PHP SDK](https://github.com/modelcontextprotocol/php-sdk)
- [Stripe Shared Payment Tokens](https://docs.stripe.com/agentic-commerce/concepts/shared-payment-tokens)
- [OpenAI ACP Docs](https://developers.openai.com/commerce/)
