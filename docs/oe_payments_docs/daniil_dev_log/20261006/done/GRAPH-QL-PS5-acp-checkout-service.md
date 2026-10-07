# GRAPH-QL / PS5 — Stripe ACP checkout service on the headless path (DONE 2026-10-06)

**Sprint:** [../sprints/GRAPH-QL-stripe-provider-story.md](../sprints/GRAPH-QL-stripe-provider-story.md) · **Branch:** `b-7.4.x-GRAPH-QL`

## What changed

| Piece | Job |
|---|---|
| `Mcp\StripeAcpCheckoutService extends AbstractAcpCheckoutService` (payment-base) | `paymentId()` = `oe_payments_stripe_wallet`, `providerName()` = `stripe`; `create_checkout` is the base class's default (payment-base S7: buyer → shop user, items → user basket paying with Stripe, contract opened through the shared chain — no Checkout Session); `completePayment()` charges the agent's delegated payment token as a confirmed PaymentIntent via the existing adapter path (`StripeAdapter::createPayment` → `PaymentIntentHelper::createPaymentIntent` with `payment_method` + `confirm`), capture mode from the module setting, metadata `contract_id` / `order_id` / `order_number` / `channel=acp` / `agent_id`; captured or authorized ⇒ `commitPaid()` (payment-base S4, `requiresCapture` for authorized, `source: acp`) ⇒ `formatOrder()`; anything else ⇒ ACP validation error on `payment_data.token` (adapter exceptions included); a refused commit names the PaymentIntent so the merchant can find the money |
| `services.yaml` | `AcpResponseFormatterInterface` (payment-providers `['stripe']`), the service (public) with payment-base's four headless collaborators, `AcpCheckoutServiceInterface` aliased to it — all under `services:` |

## Red → green

`Unit\Stripe\Mcp\StripeAcpCheckoutServiceTest` (7): is the ACP service; default `create_checkout` with the Stripe
payment id; complete charges the token (request fields, metadata) and commits (`PaymentConfirmation` fields, `source
acp`), then `formatOrder` with the account-orders permalink and `acp_agent_id` on the contract; authorized ⇒
`requiresCapture`; unconfirmed ⇒ validation error, no commit; Stripe exception ⇒ validation error; refused commit ⇒
error naming the PaymentIntent.

## Gates

- Unit (standalone) **1622** green (7 new) · Integration **102** green, 1 skip (container compiles with the ACP wiring)
- PHPStan level max No errors · phpcs clean · phpmd clean

## Scope note — what this is and is not

This is the **provider half** of the agentic-commerce layer: what payment-base's `AbstractAcpCheckoutService` asks a
provider for. It is not reachable yet: payment-base's MCP / UCP *transport* (McpServer with the six tagged tools, the
auth guard and its API-key setting, the MCP / UCP controllers in `metadata.php`, a product service) is not wired in
Stripe — that is the "Building provider modules" guide's steps 2–8, a feature of its own, not part of GRAPH-QL. The
`permalink_url` is the shop's account-orders page until a dedicated order permalink exists.
