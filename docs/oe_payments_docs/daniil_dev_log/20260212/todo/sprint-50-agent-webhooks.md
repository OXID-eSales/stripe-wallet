# Sprint 50: Webhook Delivery to AI Agents — Fulfillment Updates

**Date:** 2026-02-09
**Status:** TODO
**Priority:** Medium
**Prerequisites:** Sprint 47 (MCP/ACP foundations), Sprint 49 (custom condition types)
**Principle:** Agents need to know when orders are fulfilled, shipped, or cancelled. Build a webhook relay that sends contract lifecycle events to registered agent callback URLs — plus handle Stripe's SPT lifecycle webhooks.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | SRP: notification service separate from webhook processing |
| DI | Depend on abstractions, wire via services.yaml |
| LSP | Agent notification handlers interchangeable with existing handlers |
| DRY | Reuse existing `EventDispatcher` — agent notifications are just another handler |
| No Overengineering | HTTP POST callbacks only — no WebSocket, no SSE, no message queues |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

---

## Objective

Two complementary capabilities:

### 1. Outbound: Agent Notification (Fulfillment Updates)

When a contract transitions to a terminal or significant state, notify the originating AI agent via HTTP callback:

| Contract Event | Agent Notification | ACP Order Status |
|---------------|-------------------|-----------------|
| `ContractCommittedEvent` | `order.created` | `created` |
| `ContractFulfilledEvent` | `order.fulfilled` | `fulfilled` |
| `ContractCancelledEvent` | `order.canceled` | `canceled` |
| `ContractFailedEvent` | `order.failed` | `canceled` |
| Shipment tracking added | `order.shipped` | `shipped` |

### 2. Inbound: SPT Lifecycle Webhooks

Handle Stripe's Shared Payment Token webhook events:

| Stripe Event | Action |
|-------------|--------|
| `shared_payment.granted_token.used` | Log SPT usage, update contract metadata |
| `shared_payment.granted_token.deactivated` | Cancel contract if SPT expires/revokes before completion |

---

## Architecture

```
                        OUTBOUND (Agent Notifications)
┌──────────────────────────────────────────────────────────────────┐
│  Contract Lifecycle                                               │
│  ContractFulfilledEvent ──→ AgentNotificationHandler              │
│  ContractCancelledEvent ──→ AgentNotificationHandler              │
│  ContractCommittedEvent ──→ AgentNotificationHandler              │
│                              │                                    │
│                              ▼                                    │
│                    AgentNotificationService                        │
│                    └─ POST {agentCallbackUrl}                     │
│                       Body: ACP order status JSON                 │
│                       Headers: HMAC signature                     │
└──────────────────────────────────────────────────────────────────┘

                        INBOUND (SPT Webhooks)
┌──────────────────────────────────────────────────────────────────┐
│  Stripe                                                           │
│  shared_payment.granted_token.used ──→ SptTokenUsedHandler        │
│  shared_payment.granted_token.deactivated ──→ SptTokenDeactivated │
│                                                                   │
│  WebhookController (existing) routes new event types to handlers  │
└──────────────────────────────────────────────────────────────────┘
```

---

## Boundary Rule Applied

| Component | Provider-Agnostic? | Module | Rationale |
|-----------|-------------------|--------|-----------|
| `AgentNotificationServiceInterface` | Yes | payment-component | Any provider can notify agents |
| `AgentNotificationService` | Yes | payment-component | HTTP POST + HMAC — no provider specifics |
| `AgentCallbackRegistryInterface` | Yes | payment-component | Stores agent callback URLs |
| `AgentNotificationHandler` | Yes | payment-component | Event handler — listens to contract events |
| `AgentNotificationPayloadInterface` | Yes | payment-component | Payload format contract |
| `AcpOrderNotificationPayload` | Yes | payment-component | ACP-format payload |
| `SptTokenUsedHandler` | **No** | stripe | Stripe-specific webhook event |
| `SptTokenDeactivatedHandler` | **No** | stripe | Stripe-specific webhook event |

---

## Part A: payment-component Changes

### New Files

```
payment-component/src/Mcp/
├── Notification/
│   ├── AgentNotificationServiceInterface.php
│   ├── AgentNotificationService.php
│   ├── AgentCallbackRegistryInterface.php
│   ├── AgentCallbackRegistry.php
│   ├── AgentNotificationPayload.php
│   └── AgentNotificationResult.php
└── Handler/
    └── AgentNotificationHandler.php
```

### A1. AgentCallbackRegistryInterface

**File:** `payment-component/src/Mcp/Notification/AgentCallbackRegistryInterface.php`

Stores and retrieves agent callback URLs. Each callback is associated with a contract (set during checkout creation).

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

interface AgentCallbackRegistryInterface
{
    /**
     * Register a callback URL for an agent on a specific contract.
     */
    public function register(string $contractId, string $agentId, string $callbackUrl): void;

    /**
     * Get the callback URL for a contract's agent.
     */
    public function getCallbackUrl(string $contractId): ?string;

    /**
     * Get the agent ID for a contract.
     */
    public function getAgentId(string $contractId): ?string;
}
```

### A2. AgentCallbackRegistry

Uses contract metadata to store callback URLs — no new database tables.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;

class AgentCallbackRegistry implements AgentCallbackRegistryInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository
    ) {}

    public function register(string $contractId, string $agentId, string $callbackUrl): void
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return;
        }

        $contract->setMetadata('agent_callback_url', $callbackUrl);
        $contract->setMetadata('agent_id', $agentId);
        $this->contractRepository->save($contract);
    }

    public function getCallbackUrl(string $contractId): ?string
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return null;
        }

        $value = $contract->getMetadata('agent_callback_url');
        return is_string($value) ? $value : null;
    }

    public function getAgentId(string $contractId): ?string
    {
        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return null;
        }

        $value = $contract->getMetadata('agent_id');
        return is_string($value) ? $value : null;
    }
}
```

### A3. AgentNotificationPayload

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

readonly class AgentNotificationPayload
{
    public function __construct(
        private string $eventType,
        private string $checkoutId,
        private string $status,
        private ?string $orderId = null,
        private ?string $permalinkUrl = null,
        private array $metadata = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'event_type' => $this->eventType,
            'checkout_session_id' => $this->checkoutId,
            'status' => $this->status,
            'timestamp' => time(),
        ];

        if ($this->orderId !== null) {
            $payload['order'] = [
                'id' => $this->orderId,
                'permalink_url' => $this->permalinkUrl,
            ];
        }

        if (!empty($this->metadata)) {
            $payload['metadata'] = $this->metadata;
        }

        return $payload;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
```

### A4. AgentNotificationResult

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

readonly class AgentNotificationResult
{
    private function __construct(
        private bool $delivered,
        private int $httpStatusCode,
        private ?string $errorMessage
    ) {}

    public static function success(int $httpStatusCode): self
    {
        return new self(true, $httpStatusCode, null);
    }

    public static function failed(int $httpStatusCode, string $error): self
    {
        return new self(false, $httpStatusCode, $error);
    }

    public static function noCallback(): self
    {
        return new self(false, 0, 'No callback URL registered');
    }

    public function isDelivered(): bool
    {
        return $this->delivered;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
```

### A5. AgentNotificationServiceInterface

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

interface AgentNotificationServiceInterface
{
    /**
     * Send a notification to the agent associated with a contract.
     *
     * @param string $contractId Contract to notify about
     * @param AgentNotificationPayload $payload Notification data
     */
    public function notify(string $contractId, AgentNotificationPayload $payload): AgentNotificationResult;
}
```

### A6. AgentNotificationService

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Notification;

use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

class AgentNotificationService implements AgentNotificationServiceInterface
{
    public function __construct(
        private readonly AgentCallbackRegistryInterface $callbackRegistry,
        private readonly HttpClientInterface $httpClient,
        private readonly string $signingSecret = '',
        private readonly ?FileLoggerInterface $logger = null
    ) {}

    public function notify(string $contractId, AgentNotificationPayload $payload): AgentNotificationResult
    {
        $callbackUrl = $this->callbackRegistry->getCallbackUrl($contractId);
        if ($callbackUrl === null) {
            return AgentNotificationResult::noCallback();
        }

        $body = $payload->toJson();
        $signature = $this->generateSignature($body);

        $this->logger?->log('AgentNotification: sending', [
            'contractId' => $contractId,
            'url' => $callbackUrl,
            'eventType' => $payload->toArray()['event_type'],
        ]);

        return $this->sendNotification($callbackUrl, $body, $signature);
    }

    private function generateSignature(string $body): string
    {
        if ($this->signingSecret === '') {
            return '';
        }

        $timestamp = time();
        $signedPayload = "{$timestamp}.{$body}";

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $signedPayload, $this->signingSecret);
    }

    private function sendNotification(string $url, string $body, string $signature): AgentNotificationResult
    {
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'OxidPaymentComponent/1.0',
        ];

        if ($signature !== '') {
            $headers['X-Webhook-Signature'] = $signature;
        }

        $response = $this->httpClient->post($url, $body, $headers, 10);

        if ($response->getError() !== null) {
            $this->logger?->log('AgentNotification: HTTP error', ['error' => $response->getError()]);
            return AgentNotificationResult::failed(0, $response->getError());
        }

        $httpCode = $response->getStatusCode();

        if ($response->isSuccessful()) {
            $this->logger?->log('AgentNotification: delivered', ['httpCode' => $httpCode]);
            return AgentNotificationResult::success($httpCode);
        }

        $this->logger?->log('AgentNotification: non-2xx response', [
            'httpCode' => $httpCode,
            'response' => substr($response->getBody(), 0, 200),
        ]);

        return AgentNotificationResult::failed($httpCode, "HTTP {$httpCode}");
    }
}
```

### A7. AgentNotificationHandler

**File:** `payment-component/src/Mcp/Handler/AgentNotificationHandler.php`

Listens to contract lifecycle events and triggers agent notifications.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFulfilledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCancelledEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractFailedEvent;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationPayload;
use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationServiceInterface;

class AgentNotificationHandler implements HandlerInterface
{
    /** @var array<string> Events this handler listens to */
    private const HANDLED_EVENTS = [
        ContractCommittedEvent::class,
        ContractFulfilledEvent::class,
        ContractCancelledEvent::class,
        ContractFailedEvent::class,
    ];

    public function __construct(
        private readonly AgentNotificationServiceInterface $notificationService
    ) {}

    public static function getHandledEventClass(): string
    {
        // Handles multiple events — checked in handle()
        return ContractCommittedEvent::class;
    }

    public function handle(object $event): void
    {
        $contract = $this->extractContract($event);
        if ($contract === null) {
            return;
        }

        // Only notify if this is an agent-initiated contract
        $agentId = $contract->getMetadata('acp_agent_id');
        if ($agentId === null) {
            return;
        }

        $payload = $this->buildPayload($event, $contract);
        if ($payload === null) {
            return;
        }

        $this->notificationService->notify($contract->getId(), $payload);
    }

    private function extractContract(object $event): ?PaymentContractInterface
    {
        if (method_exists($event, 'getContract')) {
            return $event->getContract();
        }
        return null;
    }

    private function buildPayload(object $event, PaymentContractInterface $contract): ?AgentNotificationPayload
    {
        $orderId = $contract->getOrderId();

        return match (true) {
            $event instanceof ContractCommittedEvent => new AgentNotificationPayload(
                'order.created',
                $contract->getId(),
                'created',
                $orderId
            ),
            $event instanceof ContractFulfilledEvent => new AgentNotificationPayload(
                'order.fulfilled',
                $contract->getId(),
                'fulfilled',
                $orderId
            ),
            $event instanceof ContractCancelledEvent => new AgentNotificationPayload(
                'order.canceled',
                $contract->getId(),
                'canceled'
            ),
            $event instanceof ContractFailedEvent => new AgentNotificationPayload(
                'order.failed',
                $contract->getId(),
                'canceled'
            ),
            default => null,
        };
    }
}
```

---

## Part B: stripe Module Changes

### New Files

```
stripe/src/Stripe/
├── WebhookHandler/
│   ├── SptTokenUsedHandler.php
│   └── SptTokenDeactivatedHandler.php
```

### B1. SptTokenUsedHandler

**File:** `stripe/src/Stripe/WebhookHandler/SptTokenUsedHandler.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;

class SptTokenUsedHandler implements WebhookEventHandlerInterface
{
    public function __construct(
        private readonly ContractServiceInterface $contractService,
        private readonly ?FileLoggerInterface $logger = null
    ) {}

    public function getEventType(): string
    {
        return 'shared_payment.granted_token.used';
    }

    public function handle(array $eventData): void
    {
        $sptData = $eventData['data']['object'] ?? [];
        $externalId = $sptData['seller_details']['external_id'] ?? null;

        if ($externalId === null) {
            $this->logger?->log('SptTokenUsed: no external_id in event');
            return;
        }

        $contract = $this->contractService->findContract($externalId);
        if ($contract === null) {
            $this->logger?->log('SptTokenUsed: contract not found', ['externalId' => $externalId]);
            return;
        }

        $this->contractService->updateContractMetadata($externalId, [
            'spt_token_id' => $sptData['id'] ?? '',
            'spt_used_at' => time(),
            'spt_card_brand' => $sptData['payment_method']['card']['brand'] ?? '',
            'spt_card_last4' => $sptData['payment_method']['card']['last4'] ?? '',
        ]);

        $this->logger?->log('SptTokenUsed: metadata updated', [
            'contractId' => $contract->getId(),
            'sptId' => $sptData['id'] ?? '',
        ]);
    }
}
```

### B2. SptTokenDeactivatedHandler

**File:** `stripe/src/Stripe/WebhookHandler/SptTokenDeactivatedHandler.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;

class SptTokenDeactivatedHandler implements WebhookEventHandlerInterface
{
    public function __construct(
        private readonly ContractServiceInterface $contractService,
        private readonly ?FileLoggerInterface $logger = null
    ) {}

    public function getEventType(): string
    {
        return 'shared_payment.granted_token.deactivated';
    }

    public function handle(array $eventData): void
    {
        $sptData = $eventData['data']['object'] ?? [];
        $externalId = $sptData['seller_details']['external_id'] ?? null;

        if ($externalId === null) {
            $this->logger?->log('SptTokenDeactivated: no external_id');
            return;
        }

        $contract = $this->contractService->findContract($externalId);
        if ($contract === null) {
            $this->logger?->log('SptTokenDeactivated: contract not found', ['externalId' => $externalId]);
            return;
        }

        $reason = $sptData['deactivated_reason'] ?? 'unknown';

        $this->contractService->updateContractMetadata($externalId, [
            'spt_deactivated_at' => time(),
            'spt_deactivated_reason' => $reason,
        ]);

        // Cancel the contract if it hasn't been completed yet
        if (!$contract->getState()->isTerminal()) {
            $this->contractService->cancelContract($externalId);
            $this->logger?->log('SptTokenDeactivated: contract cancelled', [
                'contractId' => $contract->getId(),
                'reason' => $reason,
            ]);
        }
    }
}
```

### B3. services.yaml Additions

```yaml
# === Agent Notification ===

OxidEsales\PaymentComponent\Mcp\Notification\AgentCallbackRegistryInterface:
    class: OxidEsales\PaymentComponent\Mcp\Notification\AgentCallbackRegistry

OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationServiceInterface:
    class: OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationService
    arguments:
        $httpClient: '@OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface'
        $signingSecret: '%stripe.agent_webhook_secret%'
        $logger: '@stripe.events.file_logger'

OxidEsales\PaymentComponent\Mcp\Handler\AgentNotificationHandler:
    tags:
        - { name: payment.event_handler, priority: 10 }

# === SPT Webhook Handlers (use ContractServiceInterface, not repository) ===

OxidEsales\Payments\Stripe\WebhookHandler\SptTokenUsedHandler:
    arguments:
        $contractService: '@OxidEsales\PaymentComponent\Service\ContractServiceInterface'
        $logger: '@stripe.webhooks.file_logger'

OxidEsales\Payments\Stripe\WebhookHandler\SptTokenDeactivatedHandler:
    arguments:
        $contractService: '@OxidEsales\PaymentComponent\Service\ContractServiceInterface'
        $logger: '@stripe.webhooks.file_logger'

# === Parameters ===

parameters:
    stripe.agent_webhook_secret: ''  # HMAC secret for signing agent notifications
```

### B4. StripeWebhookProcessor Modification

Register the two new SPT event types in the webhook processor's event routing:

```php
// In StripeWebhookProcessor — add to event type → handler mapping:
'shared_payment.granted_token.used' => SptTokenUsedHandler::class,
'shared_payment.granted_token.deactivated' => SptTokenDeactivatedHandler::class,
```

---

## File Summary

| # | Module | File | Purpose | Est. Lines |
|---|--------|------|---------|-----------|
| 1 | payment-component | `Mcp/Notification/AgentCallbackRegistryInterface.php` | Callback storage contract | ~18 |
| 2 | payment-component | `Mcp/Notification/AgentCallbackRegistry.php` | Metadata-based storage | ~45 |
| 3 | payment-component | `Mcp/Notification/AgentNotificationServiceInterface.php` | Notification contract | ~15 |
| 4 | payment-component | `Mcp/Notification/AgentNotificationService.php` | HTTP POST + HMAC | ~95 |
| 5 | payment-component | `Mcp/Notification/AgentNotificationPayload.php` | Payload value object | ~50 |
| 6 | payment-component | `Mcp/Notification/AgentNotificationResult.php` | Result value object | ~40 |
| 7 | payment-component | `Mcp/Handler/AgentNotificationHandler.php` | Event → notification | ~80 |
| 8 | stripe | `WebhookHandler/SptTokenUsedHandler.php` | SPT used webhook | ~55 |
| 9 | stripe | `WebhookHandler/SptTokenDeactivatedHandler.php` | SPT deactivated webhook | ~55 |
| | | **Total** | | **~453** |

---

## TDD Approach

### Step 1: AgentNotificationPayload Tests
Test `toArray()` structure. Test `toJson()` output. Test with/without order data. Test with/without metadata.

### Step 2: AgentNotificationResult Tests
Test static factories. Test `noCallback()` factory.

### Step 3: AgentCallbackRegistry Tests
Mock `ContractRepositoryInterface`. Test register stores metadata. Test getCallbackUrl retrieves from metadata. Test null contract returns null.

### Step 4: AgentNotificationService Tests
Mock callback registry and curl. Test notification delivery. Test HMAC signature generation. Test no-callback skip. Test timeout handling.

### Step 5: AgentNotificationHandler Tests
Test only fires for agent-initiated contracts (has `acp_agent_id` metadata). Test each event type maps to correct notification type. Test non-agent contracts are skipped.

### Step 6: SptTokenUsedHandler Tests
Mock contract repository. Test metadata updates. Test missing external_id. Test contract not found.

### Step 7: SptTokenDeactivatedHandler Tests
Test contract cancellation on deactivation. Test terminal-state contracts are not cancelled again. Test reason metadata storage.

### Step 8: Full Validation
```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] Agent callback URL stored in contract metadata during `createCheckout`
- [ ] `AgentNotificationHandler` only fires for contracts with `acp_agent_id`
- [ ] Notification sent on `ContractCommittedEvent` with `order.created` type
- [ ] Notification sent on `ContractFulfilledEvent` with `order.fulfilled` type
- [ ] Notification sent on `ContractCancelledEvent` with `order.canceled` type
- [ ] HMAC signature generated with `sha256` when signing secret is set
- [ ] Notification skipped (no error) when no callback URL registered
- [ ] HTTP timeout is 10 seconds (no hanging on dead agents)
- [ ] `SptTokenUsedHandler` updates contract metadata with SPT details
- [ ] `SptTokenDeactivatedHandler` cancels non-terminal contracts
- [ ] `SptTokenDeactivatedHandler` records deactivation reason
- [ ] Webhook processor routes `shared_payment.*` events correctly
- [ ] All 799+ existing tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Acceptance Criteria

1. When an ACP-created contract is fulfilled, the originating agent receives an HTTP POST
2. The notification payload follows ACP order status format
3. Notifications include HMAC signature when signing secret is configured
4. SPT `used` webhook updates contract metadata with card details
5. SPT `deactivated` webhook cancels the associated contract
6. Non-agent contracts (browser checkout) are not affected
7. Failed notification delivery is logged but does not block contract lifecycle
