# Sprint 54 Completion Report — MCP/ACP Integration Tests + LLM E2E

**Sprint:** 54
**Priority:** High
**Status:** DONE
**Date:** 2026-02-13
**Branch:** `b-7.4.x-mcp-STRP-88`

---

## Summary

Implemented the full MCP/ACP test infrastructure across 3 levels: PHP integration (DI container + DB), HTTP integration (real HTTP to running shop), and LLM E2E (real Featherless AI → MCP → checkout lifecycle). Created reusable test fixtures and added 3 new PHPUnit test suites.

## Files Created

### Fixtures (4 files)
- `tests/Fixture/Mcp/AgentTestHelper.php` — HTTP client simulating AI agent calling MCP/UCP endpoints (curl-based, supports POST/GET/PUT)
- `tests/Fixture/Mcp/McpRequestBuilder.php` — JSON-RPC 2.0 request factory (initialize, tools/list, tools/call, createCheckout)
- `tests/Fixture/Mcp/FeatherlessClient.php` — OpenAI-compatible client for Featherless headless inference (tool_use support, configurable model/timeout)
- `tests/Fixture/Mcp/LlmToolExecutor.php` — Bridges LLM tool_calls → MCP JSON-RPC → shop HTTP → OpenAI tool result messages

### Integration Tests (4 files)
- `tests/Integration/Mcp/McpServerIntegrationTest.php` — MCP server with real DI container: initialize handshake, 6 tools discovery, input schema validation, list_products, unknown method error (5 tests)
- `tests/Integration/Mcp/AcpCheckoutFlowTest.php` — Full checkout lifecycle: create, get, update metadata, cancel, double-cancel error, complete without token error, agent_id in metadata (8 tests)
- `tests/Integration/Mcp/UcpCheckoutFlowTest.php` — UCP REST routing: POST create, GET retrieve, POST cancel, unknown route 404 (4 tests)
- `tests/Integration/Mcp/SptPaymentServiceTest.php` — SPT against Stripe test API: invalid token returns failure (1 test, skipped without STRIPE_TEST_SECRET_KEY)

### HTTP Tests (3 files)
- `tests/Http/Mcp/McpEndpointTest.php` — MCP via real HTTP: initialize, unauthorized 401, tool discovery, full checkout lifecycle over HTTP (4 tests)
- `tests/Http/Mcp/UcpCheckoutEndpointTest.php` — UCP via real HTTP: create+get, cancel (2 tests)
- `tests/Http/Mcp/UcpProfileEndpointTest.php` — Profile endpoint: JSON response, ucp_version, services, capabilities, payment (1 test)

### E2E Tests (2 files)
- `tests/E2e/Mcp/LlmProductDiscoveryTest.php` — Real LLM → list_products: verifies LLM autonomously calls list_products tool (1 test)
- `tests/E2e/Mcp/LlmAcpCheckoutTest.php` — Real LLM → full checkout: LLM creates checkout + verifies DB state; LLM creates and cancels checkout + verifies cancelled state (2 tests)

### Configuration
- `tests/phpunit.xml` — Added 3 new test suites: McpIntegration, McpHttp, McpE2e

## Test Count by Level

| Level | Files | Tests | Requires |
|-------|-------|-------|----------|
| Integration | 4 | 18 | DI container + DB |
| HTTP | 3 | 7 | Running shop (SHOP_URL) |
| E2E | 2 | 3 | Featherless API key + shop |
| **Total** | **9** | **28** | |

## Graceful Degradation

All tests skip cleanly when credentials are missing:

| Missing Variable | Tests Skipped |
|-----------------|---------------|
| _(DI container unavailable)_ | Integration tests |
| `SHOP_URL` or `STRIPE_AGENT_API_KEY` | HTTP + E2E tests |
| `FEATHERLESS_API_KEY` | E2E tests only |
| `LLM_E2E_SKIP=true` | E2E tests only |
| `STRIPE_TEST_SECRET_KEY` | SPT integration test |

## Key Design Decisions
- Used `ContainerFactory::getInstance()->getContainer()` pattern (matching existing integration tests)
- All container resolution wrapped in try/catch with `markTestSkipped()` for graceful degradation
- Fixed spec syntax error: `$payload['tool_choice'] => 'auto'` corrected to `= 'auto'`
- Integration tests clean up created contracts in `tearDown()`
- FeatherlessClient uses `temperature: 0.0` for deterministic LLM behavior
- LLM E2E tests assert on tool calls (not text) to handle non-determinism
- Max turn limits (6-8) prevent infinite loops if LLM doesn't converge
