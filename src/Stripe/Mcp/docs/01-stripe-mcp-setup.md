# Stripe MCP/ACP/UCP Setup Guide

This guide covers the Stripe-specific implementation of MCP/ACP/UCP agent commerce. For the provider-agnostic framework documentation, see `payment-component/src/Mcp/docs/`.

## Setup

### 1. Configure Stripe Keys

In **OXID Admin > Extensions > Modules > Stripe > Settings > General**:

- `sStripeMode`: `test` (for development)
- `sStripeTestToken`: `sk_test_...` (secret key)
- `sStripeTestPk`: `pk_test_...` (publishable key)

### 2. Configure Agent API Key

Set **`sStripeAgentApiKey`** to a secure random string (min 32 characters). This authenticates all MCP and UCP requests via Bearer token.

### 3. Endpoint URLs

All endpoints use OXID's `?cl=` controller routing:

| Endpoint | Controller Key | URL | Method |
|----------|---------------|-----|--------|
| MCP (JSON-RPC) | `stripemcp` | `https://shop.example.com/?cl=stripemcp` | POST |
| UCP Checkout | `stripeucp` | `https://shop.example.com/?cl=stripeucp` | POST/GET/PUT |
| UCP Profile | `stripeucpprofile` | `https://shop.example.com/?cl=stripeucpprofile` | GET |

### 4. Verify Endpoint

```bash
curl -s -X POST 'http://localhost.local/?cl=stripemcp' \
  -H 'Authorization: Bearer YOUR_AGENT_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-06-18"}}' \
  | jq .
```

Expected:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2025-06-18",
    "capabilities": { "tools": {} },
    "serverInfo": { "name": "oxid-stripe-acp", "version": "1.0.0" }
  }
}
```

## SPT Payment Flow

SPT (Shared Payment Token) is Stripe's delegated payment mechanism for agent commerce. The end-to-end flow:

```
1. Agent obtains SPT from Stripe    →  spt_granted_xxx
2. Agent calls complete_checkout     →  passes SPT as payment_data.token
3. StripeAcpCheckoutService          →  validates contract, delegates to completePayment()
4. SptPaymentService.confirmWithSpt  →  creates PaymentIntent via StripeAdapter
5. Stripe confirms payment           →  returns status: succeeded/requires_capture
6. PaymentAuthorizedEvent dispatched  →  triggers contract state transition
7. Contract → READY_TO_COMMIT        →  order creation begins
8. ACP order response returned        →  includes order permalink
```

`SptPaymentService` creates a `CreatePaymentRequest` with:
- `amount` from contract
- `currency` from contract (lowercased)
- `paymentMethod`: `stripe_spt`
- `paymentMethodId`: the SPT token
- `metadata`: `{contract_id, source: 'acp'}`

On success (`succeeded` or `requires_capture`), returns `SptPaymentResult::success(paymentIntentId, status)`.
On failure, returns `SptPaymentResult::failed(errorMessage)`.

## Manual Testing with curl

### MCP — Initialize + List Tools

```bash
# Initialize
curl -s -X POST 'http://localhost.local/?cl=stripemcp' \
  -H 'Authorization: Bearer YOUR_AGENT_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {"protocolVersion": "2025-06-18"}
  }' | jq .

# List tools
curl -s -X POST 'http://localhost.local/?cl=stripemcp' \
  -H 'Authorization: Bearer YOUR_AGENT_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc": "2.0", "id": 2, "method": "tools/list"}' | jq .
```

### MCP — Create Checkout

```bash
curl -s -X POST 'http://localhost.local/?cl=stripemcp' \
  -H 'Authorization: Bearer YOUR_AGENT_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "tools/call",
    "params": {
      "name": "create_checkout",
      "arguments": {
        "items": [{"id": "dc5ffdf380e15674b56dd562a7cb6aec", "quantity": 1}],
        "buyer": {"email": "test@example.com", "first_name": "Test", "last_name": "User"},
        "fulfillment_address": {
          "line_one": "123 Main St",
          "city": "Berlin",
          "country": "DE",
          "postal_code": "10115"
        }
      }
    }
  }' | jq .
```

### MCP — Complete Checkout with SPT

```bash
curl -s -X POST 'http://localhost.local/?cl=stripemcp' \
  -H 'Authorization: Bearer YOUR_AGENT_API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{
    "jsonrpc": "2.0",
    "id": 4,
    "method": "tools/call",
    "params": {
      "name": "complete_checkout",
      "arguments": {
        "checkout_id": "CONTRACT_ID",
        "payment_data": {
          "token": "spt_granted_xxx",
          "provider": "stripe"
        }
      }
    }
  }' | jq .
```

### UCP — Create Checkout

```bash
curl -s -X POST 'http://localhost.local/?cl=stripeucp' \
  -H 'Authorization: Bearer YOUR_AGENT_API_KEY' \
  -H 'Content-Type: application/json' \
  -H 'Request-Id: test-001' \
  -d '{
    "items": [{"id": "dc5ffdf380e15674b56dd562a7cb6aec", "quantity": 1}],
    "buyer": {"email": "test@example.com"},
    "fulfillment_address": {
      "line_one": "123 Main St",
      "city": "Berlin",
      "country": "DE",
      "postal_code": "10115"
    }
  }' -w "\nHTTP Status: %{http_code}\n"
```

### UCP — Get Checkout Status

```bash
curl -s 'http://localhost.local/?cl=stripeucp&checkout_id=CONTRACT_ID' \
  -H 'Authorization: Bearer YOUR_AGENT_API_KEY' \
  -H 'Request-Id: test-002' | jq .
```

### UCP — Get Profile

```bash
curl -s 'http://localhost.local/?cl=stripeucpprofile' | jq .
```

## Source File Index

### `src/Stripe/Mcp/` — 11 files

| File | Description |
|------|-------------|
| **Controller/** | |
| `McpController.php` | HTTP entry point for MCP JSON-RPC requests |
| `UcpCheckoutController.php` | HTTP entry point for UCP REST checkout requests |
| `UcpProfileController.php` | Returns UCP profile JSON (cacheable, 1h max-age) |
| **Handler/** | |
| `UcpCheckoutRequestHandler.php` | Routes UCP REST requests to `AcpCheckoutService` by method+path |
| **Event/** | |
| `UcpCheckoutRequestEvent.php` | Event emitted by `UcpCheckoutController` |
| **Service/** | |
| `StripeAcpCheckoutService.php` | Stripe implementation — `createCheckout()` + `completePayment()` via SPT |
| `StripeAcpProductService.php` | Product listing (stub — not yet implemented) |
| `SptPaymentService.php` | Confirms payment via Stripe SPT → PaymentIntent |
| `SptPaymentServiceInterface.php` | Interface for SPT payment |
| `SptPaymentResult.php` | Readonly result VO with `success()`/`failed()` factories |
| **Http/** | |
| `CurlHttpClient.php` | cURL-based HTTP client implementing `HttpClientInterface` |

## Test Coverage (5 files)

| Test File | Covers |
|-----------|--------|
| `Mcp/Controller/McpControllerTest.php` | Auth flow, empty body (400), no handler response (500), success path |
| `Mcp/Handler/UcpCheckoutRequestHandlerTest.php` | REST routing: POST create (201), GET (200), PUT (200), complete (200), cancel (200), 404 |
| `Mcp/Service/SptPaymentResultTest.php` | `success()` / `failed()` factories, getters |
| `Mcp/Service/SptPaymentServiceTest.php` | SPT confirmation: success, failure, exception handling, request construction |
| `Mcp/Service/StripeAcpCheckoutServiceTest.php` | `createCheckout` event dispatch, `completePayment` SPT flow, error cases |

### Running Stripe MCP Tests

```bash
# All Stripe MCP tests
docker compose exec php php vendor/bin/phpunit \
  -c extensions/stripe/tests/phpunit.xml \
  --testsuite Unit \
  --filter Mcp

# Single test file
docker compose exec php php vendor/bin/phpunit \
  -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Stripe/Mcp/Service/SptPaymentServiceTest.php

# Full pre-commit check
cd source/extensions/stripe && ./bin/pre-commit-check.sh --full
```

## DI Wiring (services.yaml)

Key Stripe-specific service definitions:

```yaml
# MCP Server — named "oxid-stripe-acp"
McpServerInterface:
  class: McpServer
  arguments:
    $serverName: 'oxid-stripe-acp'
    $serverVersion: '1.0.0'

# Auth guard — reads from sStripeAgentApiKey
McpAuthGuardInterface:
  class: McpAuthGuard
  arguments:
    $expectedToken: '%stripe.agent_api_key%'

# Stripe checkout service
AcpCheckoutServiceInterface:
  class: StripeAcpCheckoutService

# SPT payment service
SptPaymentServiceInterface:
  class: SptPaymentService
  arguments:
    $stripeAdapter: '@LazyStripeAdapter'
    $requestLogger: '@stripe.request_file_logger'

# UCP profile with Stripe payment handler
UcpProfileInterface:
  class: UcpProfile
  arguments:
    $capabilities:
      - {name: 'dev.ucp.shopping.checkout', version: '2026-01-11'}
    $paymentHandlers:
      - {id: 'stripe', spec: 'https://stripe.com/ucp-handler', version: '2026-01-11'}
```
