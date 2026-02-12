# End-to-End LLM Testing Guide (Stripe)

This guide shows how to verify the complete Stripe MCP/ACP flow with a real LLM: the agent discovers products, creates a basket, pays with a Stripe SPT token, and receives an order confirmation.

## Prerequisites

1. **Shop running via Docker**
   ```bash
   cd /path/to/project-root
   make up
   ```
   Verify: `curl -s http://localhost.local/ | head -5` returns HTML.

2. **Stripe test keys configured** in OXID Admin > Modules > Stripe > Settings:
   - `sStripeMode`: `test`
   - `sStripeTestToken`: `sk_test_...`
   - `sStripeTestPk`: `pk_test_...`

3. **Agent API key set**: `sStripeAgentApiKey` in module settings (e.g., `test_agent_key_32chars_minimum_ok`)

4. **Test products in database**: The OXID demo data includes products. Verify:
   ```bash
   docker compose exec php php -r "
     require 'bootstrap.php';
     \$db = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();
     \$count = \$db->getOne('SELECT COUNT(*) FROM oxarticles WHERE OXACTIVE=1');
     echo \"Active products: \$count\n\";
   "
   ```

5. **MCP endpoint accessible**: Verify with curl:
   ```bash
   curl -s -X POST 'http://localhost.local/?cl=stripemcp' \
     -H 'Authorization: Bearer test_agent_key_32chars_minimum_ok' \
     -H 'Content-Type: application/json' \
     -d '{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-06-18"}}' \
     | jq .
   ```
   Expected: `{"jsonrpc": "2.0", "id": 1, "result": {"protocolVersion": "2025-06-18", ...}}`

## Option A: HuggingFace Tiny Agents (Recommended)

Zero-config, works with free models, no API key needed for the LLM itself.

### Install

```bash
pip install "huggingface_hub[mcp]>=0.32.0"
```

### Create Agent Config

Create a directory `agent-config/` with a single file:

**`agent-config/agent.json`**:
```json
{
  "model": "Qwen/Qwen2.5-72B-Instruct",
  "provider": "nebius",
  "servers": [
    {
      "type": "http",
      "url": "http://localhost.local/?cl=stripemcp",
      "headers": {
        "Authorization": "Bearer test_agent_key_32chars_minimum_ok"
      }
    }
  ],
  "systemPrompt": "You are a shopping assistant. You help users browse products and complete purchases. When the user wants to buy something, use the available tools to create a checkout, and complete it with a test Stripe payment token (spt_granted_test_token). Always confirm the order details before completing."
}
```

### Run

```bash
tiny-agents run ./agent-config/
```

This opens an interactive chat. The agent will automatically call `initialize` and `tools/list` on startup.

### Example Conversation

```
You: What products do you have?
Agent: [calls list_products] Here are the available products...

You: I'd like to buy the Kite (product ID dc5ffdf380e15674b56dd562a7cb6aec)
Agent: [calls create_checkout] I've created a checkout for 1x Kite...
       The total is EUR 35.89. Shall I complete the purchase?

You: Yes, go ahead
Agent: [calls complete_checkout with spt_granted_test_token]
       Your order has been placed! Order permalink: https://localhost.local/...
```

## Option B: Claude Desktop / Claude Code

### Claude Desktop

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `~/.config/claude/claude_desktop_config.json` (Linux):

```json
{
  "mcpServers": {
    "oxid-shop": {
      "type": "http",
      "url": "http://localhost.local/?cl=stripemcp",
      "headers": {
        "Authorization": "Bearer test_agent_key_32chars_minimum_ok"
      }
    }
  }
}
```

Restart Claude Desktop. The 6 tools should appear in the tool picker.

### Claude Code

Add an MCP server in Claude Code settings or via `.mcp.json`:

```json
{
  "mcpServers": {
    "oxid-shop": {
      "type": "http",
      "url": "http://localhost.local/?cl=stripemcp",
      "headers": {
        "Authorization": "Bearer test_agent_key_32chars_minimum_ok"
      }
    }
  }
}
```

Then in the chat:
```
You: Use the oxid-shop MCP tools to browse products and buy the cheapest item.
     Use spt_granted_test_token as the payment token with provider "stripe".
```

## Option C: Custom Python Script

For automated, repeatable E2E testing:

```python
"""E2E test: browses products, creates checkout, completes payment via Stripe SPT."""

import json
import requests

SHOP_URL = "http://localhost.local/?cl=stripemcp"
API_KEY = "test_agent_key_32chars_minimum_ok"

def main():
    session = requests.Session()
    session.headers.update({
        "Authorization": f"Bearer {API_KEY}",
        "Content-Type": "application/json",
    })

    # 1. Initialize
    resp = session.post(SHOP_URL, json={
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"protocolVersion": "2025-06-18"}
    }).json()
    print(f"Protocol: {resp['result']['protocolVersion']}")
    print(f"Server: {resp['result']['serverInfo']['name']}")

    # 2. List tools
    resp = session.post(SHOP_URL, json={
        "jsonrpc": "2.0", "id": 2, "method": "tools/list"
    }).json()
    tools = [t["name"] for t in resp["result"]["tools"]]
    print(f"Tools: {tools}")
    assert "create_checkout" in tools
    assert "complete_checkout" in tools
    assert "list_products" in tools

    # 3. List products
    resp = session.post(SHOP_URL, json={
        "jsonrpc": "2.0", "id": 3, "method": "tools/call",
        "params": {"name": "list_products", "arguments": {"limit": 5}}
    }).json()
    products = resp["result"]
    print(f"Products: {json.dumps(products, indent=2)}")

    # 4. Create checkout with first product
    product_id = products["products"][0]["id"] if products.get("products") else "dc5ffdf380e15674b56dd562a7cb6aec"
    resp = session.post(SHOP_URL, json={
        "jsonrpc": "2.0", "id": 4, "method": "tools/call",
        "params": {
            "name": "create_checkout",
            "arguments": {
                "items": [{"id": product_id, "quantity": 1}],
                "buyer": {
                    "first_name": "E2E",
                    "last_name": "Test",
                    "email": "e2e@test.local"
                },
                "fulfillment_address": {
                    "line_one": "Teststr. 1",
                    "city": "Berlin",
                    "country": "DE",
                    "postal_code": "10115"
                }
            }
        }
    }).json()
    checkout = resp["result"]
    checkout_id = checkout["id"]
    print(f"Checkout created: {checkout_id}, status: {checkout['status']}")

    # 5. Complete checkout with Stripe SPT token
    resp = session.post(SHOP_URL, json={
        "jsonrpc": "2.0", "id": 5, "method": "tools/call",
        "params": {
            "name": "complete_checkout",
            "arguments": {
                "checkout_id": checkout_id,
                "payment_data": {
                    "token": "spt_granted_test_token",
                    "provider": "stripe"
                }
            }
        }
    }).json()
    print(f"Complete result: {json.dumps(resp['result'], indent=2)}")

    # 6. Verify final status
    resp = session.post(SHOP_URL, json={
        "jsonrpc": "2.0", "id": 6, "method": "tools/call",
        "params": {
            "name": "get_checkout",
            "arguments": {"checkout_id": checkout_id}
        }
    }).json()
    print(f"Final status: {resp['result']['status']}")

if __name__ == "__main__":
    main()
```

Run:
```bash
pip install requests
python e2e_stripe_mcp_test.py
```

## The Complete Test Scenario

Expected sequence when an LLM interacts with the Stripe-powered shop:

### Step 1: Automatic Initialization

The LLM client sends `initialize` and `tools/list` automatically on connection.

```
← initialize (protocolVersion: 2025-06-18)
→ serverInfo: oxid-stripe-acp v1.0.0

← tools/list
→ 6 tools: create_checkout, get_checkout, update_checkout,
            complete_checkout, cancel_checkout, list_products
```

### Step 2: User Asks About Products

```
User: "What products do you have?"
```

LLM calls `list_products` → response includes product names, IDs, prices.

### Step 3: User Wants to Buy

```
User: "Buy the Kite, ship to Berlin"
```

LLM calls `create_checkout` → response: checkout session with `status: not_ready_for_payment`, totals, line items.

### Step 4: LLM Completes Payment

LLM calls `complete_checkout` with the Stripe SPT token → response: order with permalink URL.

### Step 5: LLM Reports to User

```
Agent: "Your order has been placed! Here's your order link: https://..."
```

## Verification Checklist

After a successful E2E test:

### 1. Contract State in Database

```sql
SELECT OXID, OXSTATE, OXORDERID, OXTOTALAMOUNT, OXCURRENCY
FROM oe_payments_contract
WHERE OXID = 'CONTRACT_ID';
```

Expected: `OXSTATE` should be `fulfilled` (or `committed` if order creation is async).

### 2. OXID Order in Admin

Navigate to **OXID Admin > Administer Orders > Orders**. The most recent order should show:
- Customer email matching the buyer
- Correct items and totals
- Payment method: `stripe_spt` or similar

### 3. Stripe Dashboard

In [Stripe Dashboard > Payments](https://dashboard.stripe.com/test/payments):
- Find the PaymentIntent created by the SPT token
- Status should be `succeeded` or `requires_capture`
- Metadata should include `contract_id` and `source: acp`

### 4. Log Files

Check the Stripe request logger:
```bash
docker compose exec php cat /var/www/extensions/stripe/var/log/stripe-requests.log | tail -20
```

Look for `SptPaymentService` entries showing the payment confirmation flow.

## Troubleshooting

### Auth 401: "Authentication failed"

- **Check API key**: Verify `sStripeAgentApiKey` in OXID Admin matches the Bearer token
- **Check header format**: Must be `Authorization: Bearer <token>` (note the space after "Bearer")
- **Check module active**: Run `bin/oe-console oe:module:list` — `oe_payments_stripe_wallet` must be active
- **Clear cache**: `docker compose exec php rm -rf /var/www/var/cache/`

### Empty Product List

- **Check demo data**: `SELECT COUNT(*) FROM oxarticles WHERE OXACTIVE=1` should be > 0
- **Note**: `list_products` is currently a stub returning `{products: [], total: 0, message: "Not yet implemented"}`. Full product search is planned for a future sprint.

### SPT Token Errors

- **Invalid token format**: SPT tokens should start with `spt_granted_`
- **Stripe test mode**: Ensure `sStripeMode` is set to `test` and test secret key is configured
- **Check Stripe logs**: `docker compose exec php cat /var/www/extensions/stripe/var/log/stripe-requests.log`
- **PaymentIntent failure**: Check Stripe Dashboard > Developers > Logs for the exact error

### Contract Stuck in "draft"

- **Missing items**: `create_checkout` requires at least one item with valid product ID
- **Invalid product ID**: The article ID must exist in `oxarticles` and be active
- **Event handler not registered**: Check `services.yaml` has the `payment.event_handler` tag on handlers

### JSON-RPC Parse Error

```json
{"jsonrpc": "2.0", "id": null, "error": {"code": -32700, "message": "Parse error"}}
```

- **Invalid JSON**: Check for trailing commas, unquoted keys, or encoding issues
- **Empty body**: MCP endpoint returns 400 if `php://input` is empty

### 404 on UCP Endpoints

- **Check controller key**: URL must be `?cl=stripeucp` (not `?cl=ucp`)
- **Check metadata.php**: Controller registrations must include `stripeucp` and `stripeucpprofile`
- **Module reinstall**: If controllers were added after initial install:
  ```bash
  docker compose exec php bin/oe-console oe:module:deactivate oe_payments_stripe_wallet
  docker compose exec php bin/oe-console oe:module:activate oe_payments_stripe_wallet
  ```

### UCP Missing Request-Id Header

```json
{"error": {"type": "invalid_request", "message": "Missing required header: Request-Id"}}
```

UCP requires a `Request-Id` header on every request. Add:
```
Request-Id: any-unique-string
```
