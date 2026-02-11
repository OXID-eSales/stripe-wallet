# E2E Testing Strategy for Agentic ACP-Checkout

**Date:** 2026-02-11
**Sprint:** 54 (prerequisite research)
**Status:** Complete (7 decisions validated)

---

## Executive Summary

ACP checkout is fundamentally different from browser-based checkout: it is purely API-driven via JSON-RPC over HTTP. There is no browser UI, no Stripe Elements iframe, no cookie-based session. An AI agent authenticates with a Bearer token, discovers tools via MCP `initialize` + `tools/list`, then executes checkout operations via `tools/call`.

This report defines the testing strategy across three levels, addresses the SPT (Shared Payment Token) challenge for `complete_checkout`, and provides practical setup guidance that integrates with the existing Playwright and PHPUnit infrastructure.

---

## 1. Why ACP E2E Is Different

| Aspect | Browser E2E (existing) | ACP E2E (new) |
|--------|------------------------|---------------|
| Interface | Page Object Model, DOM selectors | `AgentTestHelper` HTTP client, JSON-RPC |
| Navigation | `page.goto()`, `page.click()` | `request.post()` with JSON-RPC payload |
| Authentication | Cookie/session (admin-auth.json) | Bearer token (`Authorization` header) |
| Browser requirement | Chromium via Playwright | None — pure HTTP requests |
| What is tested | UI rendering, Stripe Elements iframe, user flow | API contract compliance, state transitions, auth |
| Payment input | Card number typed into Stripe iframe | SPT token string passed in `payment_data` |
| Test isolation | Login/logout, cookie cleanup | Contract cleanup, `e2e_` prefix convention |

**Key insight:** Playwright's `APIRequestContext` (`request` fixture) is ideal for ACP tests — it provides HTTP client capabilities without launching a browser. This keeps ACP tests within the existing Playwright infrastructure while being fundamentally API tests.

---

## 2. Three Testing Levels

### Level 1: PHPUnit Integration Tests

**Purpose:** Test the PHP service layer with a real database — contract creation, state transitions, validation errors, repository queries.

**Why this matters most:** Integration tests catch bugs that unit tests with mocked repositories cannot — unique constraint violations (Sprint 42 lesson), state machine edge cases, and DI wiring issues.

**What to test:**

| Test Class | Scenarios |
|------------|-----------|
| `AcpCheckoutFlowTest` | create → get → cancel → verify DB state; double-cancel error; complete without token error |
| `McpServerIntegrationTest` | Server resolves tools from DI; initialize handshake; tools/list returns 6 tools; each tool has valid schema |
| `ProductFeedIntegrationTest` | Products returned with required fields; CSV generation valid; pagination works |
| `AgentNotificationTest` | Callback registration persists in metadata; payload format correct; skip when no callback |
| `SptWebhookIntegrationTest` | `spt.used` updates contract metadata; `spt.deactivated` cancels contract |
| `ConditionTypeRegistryIntegrationTest` | Registry contains all 6 types (4 core + 2 agent); factory methods work; invalid type throws |

**Execution:**
```bash
docker compose exec -T php php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml --testsuite McpIntegration
```

**phpunit.xml addition:**
```xml
<testsuite name="McpIntegration">
    <directory>tests/Integration/Mcp</directory>
</testsuite>
```

### Level 2: HTTP Integration Tests (PHPUnit + cURL)

**Purpose:** Test the actual `stripemcp` HTTP endpoint with real HTTP requests against the running OXID shop. This validates the full stack: OXID routing → `McpController` → auth guard → event dispatch → handler → `McpServer` → tool → service → response.

**What to test:**

| Test Class | Scenarios |
|------------|-----------|
| `McpEndpointTest` | Initialize handshake; unauthorized (no token) → 401; tools/list returns 6 tools; full flow: init → tools → create → update → get → cancel |
| `ProductFeedEndpointTest` | CSV returned with `text/csv` content type; auth required |
| `SptWebhookEndpointTest` | POST fake `spt.used` event to webhook endpoint with test signature; verify contract metadata updated |

**Key design decision:** Use `AgentTestHelper` (cURL-based HTTP client) rather than Guzzle to avoid adding a dependency. The helper simulates exactly what an AI agent does — sends JSON-RPC POST requests with Bearer auth.

**Execution:**
```bash
docker compose exec -T php php vendor/bin/phpunit \
    -c extensions/stripe/tests/phpunit.xml --testsuite McpHttp
```

**phpunit.xml addition:**
```xml
<testsuite name="McpHttp">
    <directory>tests/Http/Mcp</directory>
</testsuite>
```

**Environment variables required:**
```bash
SHOP_URL=http://localhost.local
AGENT_API_KEY=test-agent-key-for-e2e
```

### Level 3: Playwright E2E Tests (TypeScript, API-only)

**Purpose:** Test the agentic flow from an external HTTP client — the same perspective an AI agent has. Validates that the JSON-RPC contract is correct, responses are parseable, and the full lifecycle works from outside Docker.

**What to test:**

| Spec File | Scenarios |
|-----------|-----------|
| `mcp-discovery.spec.ts` | Agent init + tool discovery; unauthorized rejection |
| `acp-checkout-flow.spec.ts` | Full lifecycle: list_products → create → get → cancel; double-cancel error; complete without token error |
| `product-feed.spec.ts` | CSV download + validation; auth required; MCP products match feed data |

**Playwright config addition:**
```typescript
{
    name: 'agentic',
    testMatch: /tests\/agentic\/.*.spec.ts/,
    use: {
        baseURL: process.env.SHOP_URL || 'https://localhost.local',
    },
    // No browser launched — pure APIRequestContext
}
```

**Directory structure:**
```
tests/e2e/playwright/playwright/tests/agentic/
├── mcp-discovery.spec.ts
├── acp-checkout-flow.spec.ts
└── product-feed.spec.ts
```

**Execution:**
```bash
cd tests/e2e/playwright/playwright && \
    SHOP_URL=http://localhost.local \
    AGENT_API_KEY=test-agent-key \
    npx playwright test tests/agentic/
```

---

## 3. The SPT Challenge: Testing `complete_checkout`

### The Problem

`complete_checkout` requires a Shared Payment Token (`spt_granted_*`) — a Stripe-issued delegated payment credential. In production:

1. Agent calls `create_checkout` → gets checkout session with `payment_providers`
2. Agent's host (Claude, ChatGPT) requests SPT from Stripe on behalf of the buyer
3. Agent passes SPT to `complete_checkout`
4. `SptPaymentService` creates a `PaymentIntent` using that token

**The problem:** In test environments, obtaining a real SPT requires the agent host to interact with Stripe's delegated payment API. This is a multi-party flow that can't be triggered from a single test client.

### Three Approaches Evaluated

| # | Approach | How | Pros | Cons |
|---|----------|-----|------|------|
| 1 | **Mock at adapter level** | Integration test with mocked `StripeAdapterInterface` | Fast, deterministic, no Stripe dependency | Doesn't test real Stripe API |
| 2 | **Stripe test mode SPT** | Use Stripe's test mode to create SPT programmatically | Tests real flow | SPT API is new; test mode support uncertain; requires `sk_test_*` key in test env |
| 3 | **Test up to validation only** | Call `complete_checkout` with empty/invalid token, assert validation error | Simple, tests everything except payment | Missing the payment confirmation path |

### Recommended: Layered Approach

**For automated CI (always run):**
- Use Approach 3 (validation-only) in HTTP and Playwright E2E tests — call `complete_checkout` with empty token, verify `"Payment token is required"` error
- Use Approach 1 (mocked adapter) in PHPUnit integration tests — test the full `SptPaymentService` → `StripeAdapter.createPaymentIntent()` flow with a mock that returns a fake PaymentIntent

**For manual/sandbox runs (run with Stripe test keys):**
- Use Approach 2 if Stripe's test mode supports SPT creation — create a dedicated `SptLiveIntegrationTest` that runs only when `STRIPE_TEST_SECRET_KEY` env var is set, skipped otherwise

**Why this works:**
- The automated tests verify the entire create → get → update → cancel flow and the complete → validation-error path — covering 95% of the ACP contract
- The mocked adapter integration test verifies that `SptPaymentService` correctly constructs the `PaymentIntent` with `shared_payment_granted_token`
- The optional sandbox test proves the real Stripe API integration when keys are available

### Code Pattern: Mocked SPT Integration Test

```php
public function testCompleteCheckoutWithMockedSpt(): void
{
    // Arrange: create a real contract in DB
    $createResult = $this->checkoutService->createCheckout([
        'items' => [['id' => $this->getTestArticleId(), 'quantity' => 1]],
        'buyer' => ['email' => 'spt-test@example.com'],
    ], $this->agentContext);
    $checkoutId = $createResult['id'];

    // Arrange: mock StripeAdapter to return fake PaymentIntent
    $mockAdapter = $this->createMock(StripeAdapterInterface::class);
    $mockAdapter->method('createPaymentIntent')
        ->willReturn((object) [
            'id' => 'pi_test_mock_123',
            'status' => 'succeeded',
        ]);

    // Inject mock into SptPaymentService
    $sptService = new SptPaymentService($mockAdapter);
    // ... inject into StripeAcpCheckoutService

    // Act
    $result = $this->checkoutService->completeCheckout(
        $checkoutId,
        ['token' => 'spt_granted_test_123', 'provider' => 'stripe'],
        $this->agentContext
    );

    // Assert: order created
    $this->assertArrayHasKey('id', $result);
    $this->assertArrayHasKey('permalink_url', $result);

    // Assert: contract state advanced
    $contract = $this->contractRepository->findById($checkoutId);
    $this->assertContains($contract->getStateValue(), ['committed', 'fulfilled']);
}
```

### Code Pattern: Validation-Only E2E Test

```typescript
test('complete checkout fails without payment token', async ({ request }) => {
    // Create checkout first
    const createResult = await mcpCall(request, 'tools/call', {
        name: 'create_checkout',
        arguments: {
            items: [{ id: productId, quantity: 1 }],
            buyer: { email: 'notoken@example.com' },
        },
    });
    const checkout = JSON.parse(createResult.result.content[0].text);

    // Try to complete without token
    const completeResult = await mcpCall(request, 'tools/call', {
        name: 'complete_checkout',
        arguments: {
            checkout_id: checkout.id,
            payment_data: { token: '', provider: 'stripe' },
        },
    });

    const result = JSON.parse(completeResult.result.content[0].text);
    expect(result.error.message).toBe('Payment token is required');
});
```

---

## 4. Test Data Management

### Conventions

| Convention | Rule |
|------------|------|
| Prefix | All test-created data uses `e2e_` prefix (existing convention) |
| Cleanup | Each test class cleans up contracts it creates in `tearDown()` |
| Article IDs | Self-discovering: call `list_products` first to get real IDs, `markTestSkipped()` if empty |
| Agent tokens | Use `test-agent-key-for-e2e` — must match `sStripeAgentApiKey` module setting |
| Idempotency | Tests must be repeatable — no unique constraints tripped on re-run |

### Test Isolation

```php
protected function tearDown(): void
{
    // Clean up any contracts created during this test
    foreach ($this->createdContractIds as $id) {
        $contract = $this->contractRepository->findById($id);
        if ($contract !== null && !$contract->getState()->isTerminal()) {
            $contract->cancel();
            $this->contractRepository->save($contract);
        }
    }
    parent::tearDown();
}
```

---

## 5. Test Fixtures

### AgentTestHelper

A cURL-based HTTP client that simulates an AI agent. Provides:

- `initialize()` — MCP handshake
- `listTools()` — MCP tool discovery
- `callTool(name, arguments)` — MCP tool execution
- `fetchProductFeed()` — CSV feed download
- `ucpCheckout(method, path, body)` — UCP endpoint testing (future)

**Design decision:** cURL over Guzzle — no extra dependency, matches what a real HTTP client does.

### McpRequestBuilder

Static factory for JSON-RPC request payloads:

- `McpRequestBuilder::initialize()` — init handshake payload
- `McpRequestBuilder::toolsList()` — tools/list payload
- `McpRequestBuilder::toolsCall(name, arguments)` — generic tool call
- `McpRequestBuilder::createCheckout(items, buyer)` — convenience for create_checkout

---

## 6. Test Execution Order (CI Pipeline)

```
1. Unit tests (existing 639+, fast, no DB)
   └─ docker compose exec -T php phpunit --testsuite Unit

2. Integration tests (existing 178 + new MCP integration)
   └─ docker compose exec -T php phpunit --testsuite Integration
   └─ docker compose exec -T php phpunit --testsuite McpIntegration

3. HTTP integration tests (requires running OXID shop)
   └─ docker compose exec -T php phpunit --testsuite McpHttp

4. Playwright E2E tests (requires running shop + external HTTP access)
   └─ npx playwright test tests/agentic/

5. Style checks (PHPCS, PHPStan, PHPMD)
   └─ ./bin/pre-commit-check.sh --no-phpunit
```

Steps 1-2 run inside Docker. Step 3 runs inside Docker but hits the shop's HTTP endpoint. Step 4 runs outside Docker against the shop URL.

---

## 7. Flakiness Prevention

| Risk | Mitigation |
|------|------------|
| Network timing | No `sleep()` — assert on response content, not timing |
| Stale test data | Each test creates fresh contracts, cleans up in tearDown |
| Port conflicts | Use `SHOP_URL` env var, not hardcoded URLs |
| Auth token mismatch | Single source of truth: `AGENT_API_KEY` env var must match module config |
| Product availability | `list_products` tests check for empty results and `markTestSkipped()` |
| Parallel execution | ACP tests use `workers: 1` — contracts share DB state |

---

## 8. Coverage Matrix

| ACP Operation | Unit Test | Integration Test | HTTP Test | Playwright E2E |
|---------------|-----------|-----------------|-----------|----------------|
| MCP `initialize` | McpServerTest | McpServerIntegrationTest | McpEndpointTest | mcp-discovery.spec.ts |
| MCP `tools/list` | McpServerTest | McpServerIntegrationTest | McpEndpointTest | mcp-discovery.spec.ts |
| `create_checkout` | CreateCheckoutToolTest | AcpCheckoutFlowTest | McpEndpointTest | acp-checkout-flow.spec.ts |
| `get_checkout` | GetCheckoutToolTest | AcpCheckoutFlowTest | McpEndpointTest | acp-checkout-flow.spec.ts |
| `update_checkout` | UpdateCheckoutToolTest | AcpCheckoutFlowTest | McpEndpointTest | — |
| `complete_checkout` (validation) | CompleteCheckoutToolTest | AcpCheckoutFlowTest | — | acp-checkout-flow.spec.ts |
| `complete_checkout` (SPT mock) | SptPaymentServiceTest | AcpCheckoutFlowTest (mocked) | — | — |
| `cancel_checkout` | CancelCheckoutToolTest | AcpCheckoutFlowTest | McpEndpointTest | acp-checkout-flow.spec.ts |
| `list_products` | ListProductsToolTest | ProductFeedIntegrationTest | McpEndpointTest | product-feed.spec.ts |
| Product feed CSV | — | ProductFeedIntegrationTest | ProductFeedEndpointTest | product-feed.spec.ts |
| Auth (401) | McpAuthGuardTest | — | McpEndpointTest | mcp-discovery.spec.ts |
| Agent notifications | — | AgentNotificationTest | — | — |
| SPT webhooks | — | SptWebhookIntegrationTest | SptWebhookEndpointTest | — |
| Condition types | — | ConditionTypeRegistryIntegrationTest | — | — |

---

## 9. Estimated Scope

| Category | Files | Lines |
|----------|-------|-------|
| PHPUnit Integration (Mcp/) | 6 | ~360 |
| PHPUnit HTTP (Mcp/) | 3 | ~160 |
| Playwright E2E (agentic/) | 3 | ~190 |
| Fixtures (AgentTestHelper, McpRequestBuilder) | 2 | ~180 |
| **Total** | **14** | **~890** |

---

## 10. Decision Summary (Validated 2026-02-11)

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| Q1 | L2 vs L3 overlap | **Keep both** | L2 (PHPUnit+cURL) tests inside Docker (fast, CI-friendly); L3 (Playwright) tests from outside (proves external access). Redundancy acceptable for confidence |
| Q2 | SPT mock level | **Integration (real DB + mock adapter)** | Create real contract in DB, swap only StripeAdapter mock for completeCheckout. Proves state machine advances with real persistence |
| Q3 | `update_checkout` coverage | **Add to HTTP only** | PHPUnit HTTP test covers the endpoint. Playwright doesn't need to duplicate a simple metadata write |
| Q4 | SPT webhook HTTP test | **Add HTTP webhook test** | POST fake `spt.used` event to webhook endpoint with test signature. Proves full inbound path including auth |
| Q5 | Test article resolution | **Call `list_products` first** | Self-discovering: each test gets real IDs from the shop. `markTestSkipped()` if empty. Works in any environment |
| Q6 | Agentic setup | **Pre-configured (no setup)** | Assume `AGENT_API_KEY` is already set in module config. Document as prerequisite. No admin dependency |
| Q7 | Fixture location | **Both in `tests/Fixture/`** | Single location for all shared test helpers. Simpler than splitting across directories |
| — | SPT testing strategy | Layered: validation + mock + optional sandbox | Covers 95% without real SPT; mock tests the code path; sandbox tests real API when possible |
| — | HTTP client | cURL (AgentTestHelper) | No Guzzle dependency; matches real agent behavior |
| — | Playwright approach | `APIRequestContext` (no browser) | ACP is API-only; browser adds cost with no value |
| — | Test execution | Sequential (workers: 1) | Contracts share DB; parallel would cause state conflicts |

---

## References

- Sprint 54 plan: [sprint-54-e2e-mcp-acp-tests.md](../../20260209/todo/sprint-54-e2e-mcp-acp-tests.md)
- Sprint 47 plan: [sprint-47-acp-mcp-support.md](../../20260209/todo/sprint-47-acp-mcp-support.md)
- ACP/MCP foundations: [01-acp-mcp-foundations.md](../../20260209/reports/01-acp-mcp-foundations.md)
- Existing Playwright config: `tests/e2e/playwright/playwright/playwright.config.ts`
- Stripe SPT docs: https://docs.stripe.com/agentic-commerce/concepts/shared-payment-tokens
