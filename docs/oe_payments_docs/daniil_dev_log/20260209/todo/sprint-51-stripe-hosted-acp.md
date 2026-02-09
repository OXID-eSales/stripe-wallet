# Sprint 51: Stripe Hosted ACP Endpoint Integration (Agentic Commerce Suite)

**Date:** 2026-02-09
**Status:** TODO
**Priority:** Medium
**Prerequisites:** Sprint 47 (MCP/ACP foundations), Sprint 48 (Product Feed)
**Principle:** Stripe's Agentic Commerce Suite hosts ACP endpoints on behalf of merchants — no self-hosted REST controllers needed. This sprint connects OXID's product catalog and order lifecycle to Stripe's hosted infrastructure via Stripe API, complementing the self-hosted MCP path from Sprint 47.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | SRP: catalog sync separate from order sync separate from config |
| DI | Depend on abstractions, wire via services.yaml |
| LSP | Hosted and self-hosted checkout services share `AcpCheckoutServiceInterface` |
| DRY | Reuse `OxidProductFieldMapper` from Sprint 48 for catalog data |
| No Overengineering | Dashboard config via Stripe API only — no custom admin UI yet |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

---

## Objective

Integrate with Stripe's **Agentic Commerce Suite** — Stripe's managed ACP hosting where Stripe handles:

- Product discovery and syndication to AI agents (ChatGPT, etc.)
- Checkout session creation and management
- Payment processing via SPTs
- Fraud protection via Stripe Radar

The merchant (OXID shop) provides:
- **Product catalog** (CSV upload via Stripe API)
- **Order fulfillment** (handle order webhooks, update shipment status)
- **Configuration** (agent selection, shipping, tax via Stripe API)

### Self-Hosted vs Hosted Comparison

| Aspect | Self-Hosted (Sprint 47) | Hosted (This Sprint) |
|--------|------------------------|---------------------|
| Entry point | MCP server at shop URL | Stripe-hosted endpoints |
| Product catalog | `list_products` MCP tool | CSV upload to Stripe |
| Checkout | Full control in module | Stripe manages |
| Payment | Module processes SPT | Stripe processes |
| Agent onboarding | Manual token exchange | One-click in Dashboard |
| Use case | Full control, custom flows | Quick setup, standard flows |

**Both paths coexist** — merchants choose based on their needs.

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  AI Agent (ChatGPT, etc.)                                         │
│  Discovers products, initiates checkout                           │
└───────────────────────────┬──────────────────────────────────────┘
                            │ ACP protocol
┌───────────────────────────▼──────────────────────────────────────┐
│  Stripe Agentic Commerce Suite (HOSTED)                           │
│  Manages: product search, checkout, payment, fraud                │
│  Sends webhooks to merchant for fulfillment                       │
└───────────────────────────┬──────────────────────────────────────┘
                            │ Webhooks + API
┌───────────────────────────▼──────────────────────────────────────┐
│  OXID Shop + Stripe Module                                        │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ StripeProductCatalogSyncService                           │    │
│  │  └─ Generates CSV from OXID articles                      │    │
│  │  └─ Uploads to Stripe via stripe.products.import API      │    │
│  │  └─ Handles partial updates (inventory, price)            │    │
│  └──────────────────────────────────────────────────────────┘    │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ HostedAcpOrderHandler (Webhook)                           │    │
│  │  └─ Receives order.created from Stripe                    │    │
│  │  └─ Creates OXID order from hosted checkout data          │    │
│  │  └─ Manages fulfillment status updates                    │    │
│  └──────────────────────────────────────────────────────────┘    │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ ProductCatalogSyncCommand (CLI)                           │    │
│  │  └─ bin/oe-console stripe:catalog:sync                    │    │
│  └──────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────┘
```

---

## Boundary Rule Applied

| Component | Provider-Agnostic? | Module |
|-----------|-------------------|--------|
| `HostedCommerceServiceInterface` | Yes | payment-component |
| `CatalogSyncResultInterface` | Yes | payment-component |
| `StripeProductCatalogSyncService` | **No** | stripe |
| `HostedAcpOrderHandler` | **No** | stripe |
| `ProductCatalogSyncCommand` | **No** | stripe |
| `StripeFulfillmentService` | **No** | stripe |

---

## Part A: payment-component Changes

### New Files

```
payment-component/src/Mcp/Acp/
├── HostedCommerceServiceInterface.php
└── CatalogSyncResult.php
```

### A1. HostedCommerceServiceInterface

Abstraction for hosted commerce platforms (Stripe, future providers).

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

interface HostedCommerceServiceInterface
{
    /**
     * Upload/sync product catalog to the hosted commerce platform.
     *
     * @param string $feedContent Generated feed content (CSV, JSONL)
     * @param string $feedFormat Format identifier ('csv', 'jsonl')
     * @return CatalogSyncResult Upload result
     */
    public function syncCatalog(string $feedContent, string $feedFormat): CatalogSyncResult;

    /**
     * Upload partial inventory update.
     *
     * @param array<array{id: string, availability: string, quantity?: int}> $inventoryUpdates
     */
    public function syncInventory(array $inventoryUpdates): CatalogSyncResult;

    /**
     * Update fulfillment status for a hosted order.
     *
     * @param string $orderId Hosted platform order ID
     * @param string $status New status ('shipped', 'fulfilled', 'canceled')
     * @param array<string, mixed> $metadata Tracking info, carrier, etc.
     */
    public function updateFulfillmentStatus(
        string $orderId,
        string $status,
        array $metadata = []
    ): bool;
}
```

### A2. CatalogSyncResult

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

readonly class CatalogSyncResult
{
    private function __construct(
        private bool $successful,
        private int $productsProcessed,
        private int $productsCreated,
        private int $productsUpdated,
        private int $errors,
        private array $errorMessages
    ) {}

    public static function success(int $processed, int $created, int $updated): self
    {
        return new self(true, $processed, $created, $updated, 0, []);
    }

    public static function partial(int $processed, int $created, int $updated, int $errors, array $errorMessages): self
    {
        return new self($errors === 0, $processed, $created, $updated, $errors, $errorMessages);
    }

    public static function failed(string $error): self
    {
        return new self(false, 0, 0, 0, 1, [$error]);
    }

    public function isSuccessful(): bool { return $this->successful; }
    public function getProductsProcessed(): int { return $this->productsProcessed; }
    public function getProductsCreated(): int { return $this->productsCreated; }
    public function getProductsUpdated(): int { return $this->productsUpdated; }
    public function getErrors(): int { return $this->errors; }
    /** @return array<string> */
    public function getErrorMessages(): array { return $this->errorMessages; }
}
```

---

## Part B: stripe Module Changes

### New Files

```
stripe/src/Stripe/Mcp/
├── Service/
│   ├── StripeProductCatalogSyncService.php
│   └── StripeFulfillmentService.php
├── WebhookHandler/
│   └── HostedAcpOrderHandler.php
└── Command/
    └── ProductCatalogSyncCommand.php
```

### B1. StripeProductCatalogSyncService

Uploads OXID product catalog to Stripe's Agentic Commerce Suite via CSV.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\CatalogSyncResult;
use OxidEsales\PaymentComponent\Mcp\Acp\HostedCommerceServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;

class StripeProductCatalogSyncService implements HostedCommerceServiceInterface
{
    public function __construct(
        private readonly StripeAdapterInterface $stripeAdapter,
        private readonly AcpProductServiceInterface $productService,
        private readonly ProductFeedGeneratorInterface $feedGenerator,
        private readonly ?FileLoggerInterface $logger = null
    ) {}

    public function syncCatalog(string $feedContent, string $feedFormat): CatalogSyncResult
    {
        $this->logger?->log('CatalogSync: starting upload', [
            'format' => $feedFormat,
            'contentLength' => strlen($feedContent),
        ]);

        try {
            $result = $this->stripeAdapter->uploadProductCatalog($feedContent, $feedFormat);

            $this->logger?->log('CatalogSync: upload complete', [
                'processed' => $result['products_processed'] ?? 0,
                'created' => $result['products_created'] ?? 0,
                'updated' => $result['products_updated'] ?? 0,
            ]);

            return CatalogSyncResult::success(
                $result['products_processed'] ?? 0,
                $result['products_created'] ?? 0,
                $result['products_updated'] ?? 0
            );
        } catch (\Throwable $e) {
            $this->logger?->log('CatalogSync: upload failed', ['error' => $e->getMessage()]);
            return CatalogSyncResult::failed($e->getMessage());
        }
    }

    public function syncInventory(array $inventoryUpdates): CatalogSyncResult
    {
        $csvLines = ["ID,Availability\n"];
        foreach ($inventoryUpdates as $update) {
            $csvLines[] = $update['id'] . ',' . $update['availability'] . "\n";
        }

        return $this->syncCatalog(implode('', $csvLines), 'csv');
    }

    public function updateFulfillmentStatus(
        string $orderId,
        string $status,
        array $metadata = []
    ): bool {
        try {
            $this->stripeAdapter->updateAgenticOrderStatus($orderId, $status, $metadata);
            return true;
        } catch (\Throwable $e) {
            $this->logger?->log('FulfillmentUpdate: failed', [
                'orderId' => $orderId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Convenience: generate feed from OXID articles and upload in one step.
     */
    public function syncAllProducts(): CatalogSyncResult
    {
        $result = $this->productService->listProducts(['limit' => 10000]);
        $feedContent = $this->feedGenerator->generate($result['products']);

        return $this->syncCatalog($feedContent, $this->feedGenerator->getFileExtension());
    }
}
```

### B2. HostedAcpOrderHandler

Handles orders created by Stripe's hosted ACP — creates corresponding OXID orders.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;

class HostedAcpOrderHandler implements WebhookEventHandlerInterface
{
    public function __construct(
        private readonly ContractServiceInterface $contractService,
        private readonly ShopOrderServiceInterface $shopOrderService,
        private readonly ?FileLoggerInterface $logger = null
    ) {}

    public function getEventType(): string
    {
        return 'checkout_session.completed';
    }

    public function handle(array $eventData): void
    {
        $session = $eventData['data']['object'] ?? [];
        $metadata = $session['metadata'] ?? [];

        // Only handle agentic commerce sessions
        if (($metadata['source'] ?? '') !== 'agentic_commerce') {
            return;
        }

        $contractId = $metadata['contract_id'] ?? null;
        if ($contractId === null) {
            $this->logger?->log('HostedAcpOrder: no contract_id in metadata');
            return;
        }

        $contract = $this->contractService->findContract($contractId);
        if ($contract === null) {
            $this->logger?->log('HostedAcpOrder: contract not found', [
                'contractId' => $contractId,
            ]);
            return;
        }

        // Store hosted checkout details via service
        $this->contractService->updateContractMetadata($contractId, [
            'hosted_checkout_session_id' => $session['id'] ?? '',
            'hosted_payment_intent' => $session['payment_intent'] ?? '',
            'hosted_customer_email' => $session['customer_details']['email'] ?? '',
        ]);

        $this->logger?->log('HostedAcpOrder: processed', [
            'contractId' => $contractId,
            'sessionId' => $session['id'] ?? '',
        ]);
    }
}
```

### B3. ProductCatalogSyncCommand

**File:** `stripe/src/Stripe/Mcp/Command/ProductCatalogSyncCommand.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Command;

use OxidEsales\Payments\Stripe\Mcp\Service\StripeProductCatalogSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProductCatalogSyncCommand extends Command
{
    protected static $defaultName = 'stripe:catalog:sync';

    public function __construct(
        private readonly StripeProductCatalogSyncService $syncService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Sync OXID product catalog to Stripe Agentic Commerce Suite');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Syncing product catalog to Stripe...');

        $result = $this->syncService->syncAllProducts();

        if ($result->isSuccessful()) {
            $output->writeln(sprintf(
                'Sync complete: %d processed, %d created, %d updated',
                $result->getProductsProcessed(),
                $result->getProductsCreated(),
                $result->getProductsUpdated()
            ));
            return Command::SUCCESS;
        }

        $output->writeln('<error>Sync failed:</error>');
        foreach ($result->getErrorMessages() as $error) {
            $output->writeln("  - {$error}");
        }

        return Command::FAILURE;
    }
}
```

### B4. StripeAdapterInterface Additions

Two new methods needed on `StripeAdapterInterface`:

```php
/**
 * Upload product catalog CSV to Stripe Agentic Commerce Suite.
 *
 * @return array{products_processed: int, products_created: int, products_updated: int}
 */
public function uploadProductCatalog(string $feedContent, string $format): array;

/**
 * Update fulfillment status for an agentic commerce order.
 */
public function updateAgenticOrderStatus(string $orderId, string $status, array $metadata = []): void;
```

### B5. services.yaml Additions

```yaml
# === Stripe Hosted ACP ===

OxidEsales\PaymentComponent\Mcp\Acp\HostedCommerceServiceInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\StripeProductCatalogSyncService
    arguments:
        $logger: '@stripe.request_file_logger'

OxidEsales\Payments\Stripe\Mcp\Service\StripeProductCatalogSyncService:
    alias: OxidEsales\PaymentComponent\Mcp\Acp\HostedCommerceServiceInterface

OxidEsales\Payments\Stripe\WebhookHandler\HostedAcpOrderHandler:
    arguments:
        $contractService: '@OxidEsales\PaymentComponent\Service\ContractServiceInterface'
        $logger: '@stripe.webhooks.file_logger'

OxidEsales\Payments\Stripe\Mcp\Command\ProductCatalogSyncCommand:
    tags: [{ name: console.command }]
```

---

## File Summary

| # | Module | File | Purpose | Est. Lines |
|---|--------|------|---------|-----------|
| 1 | payment-component | `Mcp/Acp/HostedCommerceServiceInterface.php` | Hosted commerce contract | ~30 |
| 2 | payment-component | `Mcp/Acp/CatalogSyncResult.php` | Sync result value object | ~45 |
| 3 | stripe | `Mcp/Service/StripeProductCatalogSyncService.php` | Stripe catalog upload | ~90 |
| 4 | stripe | `WebhookHandler/HostedAcpOrderHandler.php` | Hosted order webhook | ~70 |
| 5 | stripe | `Mcp/Command/ProductCatalogSyncCommand.php` | CLI catalog sync | ~45 |
| | | **Total** | | **~280** |
| | stripe | `StripeAdapterInterface.php` | **Modified** — 2 new methods | ~+10 |
| | stripe | `StripeAdapter.php` | **Modified** — implementations | ~+40 |

---

## TDD Approach

### Step 1: CatalogSyncResult Tests
Test static factories. Test success/partial/failed states. Test error message collection.

### Step 2: StripeProductCatalogSyncService Tests
Mock StripeAdapter. Test CSV upload. Test inventory sync generates correct CSV. Test `syncAllProducts()` composes product service + generator + upload.

### Step 3: HostedAcpOrderHandler Tests
Test only handles `source: agentic_commerce` sessions. Test contract metadata updates. Test missing contract_id. Test non-agentic sessions are skipped.

### Step 4: ProductCatalogSyncCommand Tests
Test success output. Test failure output with error messages.

### Step 5: Full Validation
```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] `stripe:catalog:sync` generates CSV and uploads to Stripe
- [ ] CSV format matches Stripe's product catalog specification
- [ ] Inventory-only sync sends minimal CSV (ID + Availability)
- [ ] Fulfillment status updates reach Stripe API
- [ ] Hosted order webhook creates/updates contract with session details
- [ ] Non-agentic checkout sessions are ignored by `HostedAcpOrderHandler`
- [ ] `CatalogSyncResult` captures partial failures with error messages
- [ ] StripeAdapter methods are properly mocked in tests
- [ ] All 799+ existing tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Acceptance Criteria

1. `bin/oe-console stripe:catalog:sync` uploads OXID catalog to Stripe
2. Products synced to Stripe are discoverable by AI agents via ChatGPT
3. Orders placed through Stripe's hosted ACP create contracts in OXID
4. Fulfillment status updates propagate from OXID to Stripe to agents
5. Inventory changes (stock 0) mark products as `out_of_stock` in the feed
6. Self-hosted MCP path (Sprint 47) and hosted path coexist without conflict
