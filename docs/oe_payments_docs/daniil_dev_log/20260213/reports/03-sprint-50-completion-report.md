# Sprint 50 Completion Report — Agent Webhooks: Fulfillment Updates

**Sprint:** 50
**Priority:** Medium
**Status:** DONE
**Date:** 2026-02-13
**Branch:** `b-7.4.x-mcp-STRP-88`

---

## Summary

Implemented agent webhook notification system and Stripe SPT (Shared Payment Token) webhook handlers. Agents can register callback URLs and receive HMAC-signed HTTP notifications when contract state changes. SPT handlers track token usage and cancel contracts on token deactivation.

## Files Created

### payment-component (notification system)
- `src/Mcp/Notification/AgentCallbackRegistryInterface.php` — `register()`, `getCallbackUrl()`, `getAgentId()`
- `src/Mcp/Notification/AgentCallbackRegistry.php` — Stores callback URL and agent ID in contract metadata
- `src/Mcp/Notification/AgentNotificationPayload.php` — Readonly value object with `toArray()`, `toJson()`, `getEventType()`
- `src/Mcp/Notification/AgentNotificationResult.php` — Readonly VO: `success()`, `failed()`, `noCallback()` factories
- `src/Mcp/Notification/AgentNotificationServiceInterface.php` — `notify(contractId, payload): AgentNotificationResult`
- `src/Mcp/Notification/AgentNotificationService.php` — HTTP POST with optional HMAC signature (`t=<ts>,v1=<hmac-sha256>`)
- `src/Mcp/Handler/AgentNotificationHandler.php` — Listens to Committed/Fulfilled/Cancelled/Failed events, fires only for agent contracts

### stripe (SPT webhook handlers)
- `src/Stripe/WebhookHandler/SptTokenUsedHandler.php` — Handles `shared_payment.granted_token.used`, updates contract metadata
- `src/Stripe/WebhookHandler/SptTokenDeactivatedHandler.php` — Handles `shared_payment.granted_token.deactivated`, cancels non-terminal contracts

### Tests (6 files, 58 tests, 179 assertions)
- `tests/Unit/Mcp/Notification/AgentNotificationPayloadTest.php` — 10 tests
- `tests/Unit/Mcp/Notification/AgentNotificationResultTest.php` — 6 tests
- `tests/Unit/Mcp/Notification/AgentNotificationServiceTest.php` — 8 tests
- `tests/Unit/Mcp/Handler/AgentNotificationHandlerTest.php` — 12 tests
- `tests/Unit/Stripe/WebhookHandler/SptTokenUsedHandlerTest.php` — 8 tests (non-terminal) contracts
- `tests/Unit/Stripe/WebhookHandler/SptTokenDeactivatedHandlerTest.php` — 14 tests

### services.yaml additions
- `AgentCallbackRegistryInterface`, `AgentNotificationServiceInterface` (with signing secret)
- `AgentNotificationHandler`, `SptTokenUsedHandler`, `SptTokenDeactivatedHandler`
- Parameter: `stripe.agent_webhook_secret`

## Key Design Decisions
- Adapted SPT handlers to existing `WebhookEventHandlerInterface` (`supports()` + `handle(WebhookEvent)`) instead of spec's proposed interface — matches existing codebase patterns (ChargeRefundedHandler)
- HMAC signatures use `t=<timestamp>,v1=<sha256>` format (Stripe convention)
- Agent notifications only fire for contracts with `acp_agent_id` metadata
- Deactivation handler checks terminal states before cancelling (fulfilled/cancelled/failed are terminal)
