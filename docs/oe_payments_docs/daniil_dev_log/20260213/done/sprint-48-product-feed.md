# Sprint 48: Product Feed Specification — Catalog Sync to AI Agents

**Date:** 2026-02-09
**Status:** TODO
**Priority:** High
**Prerequisites:** Sprint 47 (MCP/ACP foundations) — `McpServer`, `McpToolInterface`, `AcpProductServiceInterface`
**Depends on:** Sprint 47 delivers `ListProductsTool` which delegates to `AcpProductServiceInterface` — this sprint provides the implementation.

---

## Core Requirements

| Principle | Enforcement |
|-----------|-------------|
| TDD-First | Write failing tests before implementation |
| SOLID | SRP, OCP, LSP, ISP, DIP in every class |
| DI | Depend on abstractions, wire via services.yaml |
| LSP | Subtypes must be substitutable for their base types |
| DRY | Single field-mapping source for all export formats |
| No Overengineering | Start with CSV/JSONL — no XML, no gRPC feed streaming |
| Clean Code | Small methods, early returns, meaningful names, PSR-12 |

---

## Objective

Enable AI agents to discover and search the shop's product catalog by:

1. **Implementing `AcpProductServiceInterface`** — the service behind Sprint 47's `list_products` MCP tool
2. **Generating product feeds** in Stripe CSV and OpenAI JSONL formats from OXID articles
3. **Providing a CLI command** for scheduled feed generation (cron-compatible)
4. **Exposing a hosted feed endpoint** for agent platforms to pull product data

### What This Sprint Covers

- Product feed field mapping (OXID article → ACP/Stripe/OpenAI fields)
- Feed generation in CSV (Stripe) and JSONL (OpenAI) formats
- `AcpProductServiceInterface` implementation for real-time MCP product queries
- CLI command for batch feed generation
- Feed endpoint controller for hosted delivery

### What This Sprint Does NOT Cover

- SFTP delivery (future — use CLI + cron + scp for now)
- Variant management beyond basic parent/child articles
- Dynamic pricing feeds (partial update feeds)
- Feed validation service (future sprint)

---

## ACP Product Feed Field Mapping

### Required Fields

| ACP/Stripe Field | OXID Source | Transformation |
|-----------------|------------|----------------|
| `id` / `item_id` | `OXID` | Direct |
| `title` | `OXTITLE` | UTF-8, max 150 chars |
| `description` | `OXSHORTDESC` + `OXLONGDESC` | Plaintext strip, max 5000 chars |
| `url` / `link` | `OXID` + shop URL | `{shopUrl}?cl=details&anid={OXID}` |
| `brand` | `OXMANUFACTURERID` → manufacturer `OXTITLE` | Lookup via manufacturer table |
| `price` | `OXPRICE` | Format: `"{price} {currency}"` (e.g., `"49.99 EUR"`) |
| `availability` | `OXSTOCK` + `OXSTOCKFLAG` | `>0` → `in_stock`, `0+flag=1` → `out_of_stock`, `0+flag=4` → `backorder` |
| `image_url` / `image_link` | `OXPIC1` | Resolve to absolute URL via `OxidShopAdapter` |

### Optional Fields

| ACP/Stripe Field | OXID Source | Notes |
|-----------------|------------|-------|
| `currency` | Shop config | ISO 4217 from module config |
| `sale_price` | `OXTPRICE` | Only if `OXTPRICE > 0` and `OXTPRICE < OXPRICE` |
| `target_countries` | Shop config | From OXID country settings |
| `store_country` | Shop config | From OXID shop address |
| `group_id` / `item_group_id` | `OXPARENTID` | Non-empty = variant of parent |
| `gtin` | `OXEAN` | EAN/GTIN code |
| `mpn` | `OXMPN` | Manufacturer part number |
| `weight` | `OXWEIGHT` | In kg |
| `shipping` | Delivery sets | `{country}:::{price} {currency}` format |

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  AI Agent (MCP Client)                                            │
│  calls tools/call: list_products                                  │
└───────────────────────────┬──────────────────────────────────────┘
                            │
┌───────────────────────────▼──────────────────────────────────────┐
│  payment-component                                                │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ ListProductsTool → AcpProductServiceInterface             │    │
│  └──────────────────────────────────────────────────────────┘    │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ ProductFeedGeneratorInterface                             │    │
│  │  └─ generateFeed(filters, format): string                 │    │
│  │ ProductFieldMapperInterface                               │    │
│  │  └─ mapArticle(article): array                            │    │
│  └──────────────────────────────────────────────────────────┘    │
└───────────────────────────┬──────────────────────────────────────┘
                            │ uses
┌───────────────────────────▼──────────────────────────────────────┐
│  stripe module                                                    │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ OxidProductService implements AcpProductServiceInterface  │    │
│  │  └─ queries oxarticles via OXID article repository        │    │
│  │ OxidProductFieldMapper implements ProductFieldMapperInterface │ │
│  │  └─ maps OXID fields → ACP/Stripe fields                 │    │
│  │ CsvFeedGenerator implements ProductFeedGeneratorInterface │    │
│  │ JsonlFeedGenerator implements ProductFeedGeneratorInterface│   │
│  │ ProductFeedCommand (CLI) — generates feeds to file        │    │
│  │ ProductFeedController — serves feed via HTTP              │    │
│  └──────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────┘
```

---

## Boundary Rule Applied

| Component | Provider-Agnostic? | Module | Rationale |
|-----------|-------------------|--------|-----------|
| `AcpProductServiceInterface` | Yes | payment-component | Already defined in Sprint 47 |
| `ProductFeedGeneratorInterface` | Yes | payment-component | Feed generation is protocol-level |
| `ProductFieldMapperInterface` | Yes | payment-component | Field mapping contract is generic |
| `OxidProductService` | **No** | stripe | Queries OXID-specific article tables |
| `OxidProductFieldMapper` | **No** | stripe | Maps OXID model fields |
| `CsvFeedGenerator` | **No** | stripe | Stripe CSV format specifics |
| `JsonlFeedGenerator` | **No** | stripe | OpenAI JSONL format specifics |
| `ProductFeedCommand` | **No** | stripe | OXID CLI integration |
| `ProductFeedController` | **No** | stripe | OXID controller, metadata.php |

---

## Part A: payment-component Changes

### New Files

```
payment-component/src/Mcp/Acp/
├── AcpProductServiceInterface.php   # Already exists from Sprint 47
├── ProductFeedGeneratorInterface.php
└── ProductFieldMapperInterface.php
```

### A1. ProductFeedGeneratorInterface

**File:** `payment-component/src/Mcp/Acp/ProductFeedGeneratorInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

interface ProductFeedGeneratorInterface
{
    /**
     * Generate a product feed string in the implementing format.
     *
     * @param array<int, array<string, mixed>> $products Mapped product data
     * @return string Feed content (CSV, JSONL, etc.)
     */
    public function generate(array $products): string;

    /**
     * Get the MIME type of the generated feed.
     */
    public function getContentType(): string;

    /**
     * Get the file extension for the generated feed.
     */
    public function getFileExtension(): string;
}
```

### A2. ProductFieldMapperInterface

**File:** `payment-component/src/Mcp/Acp/ProductFieldMapperInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Mcp\Acp;

interface ProductFieldMapperInterface
{
    /**
     * Map a shop-internal product representation to ACP feed fields.
     *
     * @param mixed $product Shop-specific product model
     * @return array<string, mixed> ACP-compatible field map
     */
    public function mapProduct(mixed $product): array;

    /**
     * Get the ordered list of field names for header generation.
     *
     * @return array<string>
     */
    public function getFieldNames(): array;
}
```

---

## Part B: stripe Module Changes

### New Files

```
stripe/src/Stripe/Mcp/
├── Service/
│   ├── OxidProductService.php
│   └── OxidProductFieldMapper.php
├── ProductFeed/
│   ├── CsvFeedGenerator.php
│   └── JsonlFeedGenerator.php
├── Controller/
│   └── ProductFeedController.php
├── Event/
│   └── ProductFeedRequestEvent.php
├── Handler/
│   └── ProductFeedRequestHandler.php
└── Command/
    └── ProductFeedCommand.php
```

### B1. OxidProductService

**File:** `stripe/src/Stripe/Mcp/Service/OxidProductService.php`

Implements `AcpProductServiceInterface`. Queries OXID `oxarticles` table with filters.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFieldMapperInterface;

class OxidProductService implements AcpProductServiceInterface
{
    public function __construct(
        private readonly ProductFieldMapperInterface $fieldMapper,
        private readonly OxidArticleQueryServiceInterface $articleQuery
    ) {}

    public function listProducts(array $filters = []): array
    {
        $limit = min((int) ($filters['limit'] ?? 20), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);
        $search = $filters['search'] ?? null;
        $categoryId = $filters['category_id'] ?? null;

        $articles = $this->articleQuery->findArticles($search, $categoryId, $limit, $offset);
        $total = $this->articleQuery->countArticles($search, $categoryId);

        $products = array_map(
            fn ($article) => $this->fieldMapper->mapProduct($article),
            $articles
        );

        return [
            'products' => $products,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function getProduct(string $productId): ?array
    {
        $article = $this->articleQuery->findArticleById($productId);
        if ($article === null) {
            return null;
        }

        return $this->fieldMapper->mapProduct($article);
    }
}
```

### B2. OxidArticleQueryServiceInterface

**File:** `stripe/src/Stripe/Mcp/Service/OxidArticleQueryServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

interface OxidArticleQueryServiceInterface
{
    /**
     * @return array<object> OXID article objects
     */
    public function findArticles(?string $search, ?string $categoryId, int $limit, int $offset): array;

    public function countArticles(?string $search, ?string $categoryId): int;

    public function findArticleById(string $articleId): ?object;
}
```

### B3. OxidProductFieldMapper

**File:** `stripe/src/Stripe/Mcp/Service/OxidProductFieldMapper.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFieldMapperInterface;

class OxidProductFieldMapper implements ProductFieldMapperInterface
{
    public function __construct(
        private readonly ShopAdapterInterface $shopAdapter
    ) {}

    public function mapProduct(mixed $product): array
    {
        $shopUrl = $this->shopAdapter->getShopUrl();

        return [
            'id' => $product->getId(),
            'title' => $this->truncate($product->getTitle(), 150),
            'description' => $this->truncate(
                strip_tags($product->getLongDescription()),
                5000
            ),
            'url' => $shopUrl . '?cl=details&anid=' . $product->getId(),
            'brand' => $product->getManufacturer()?->getTitle() ?? '',
            'price' => $this->formatPrice($product->getPrice()->getBruttoPrice()),
            'currency' => $this->shopAdapter->getActiveCurrency(),
            'availability' => $this->mapAvailability($product),
            'image_url' => $this->resolveImageUrl($product, $shopUrl),
            'gtin' => $product->getFieldData('oxean') ?: null,
            'mpn' => $product->getFieldData('oxmpn') ?: null,
            'weight' => $product->getWeight() > 0 ? $product->getWeight() : null,
            'group_id' => $product->getParentId() ?: null,
        ];
    }

    public function getFieldNames(): array
    {
        return [
            'id', 'title', 'description', 'url', 'brand', 'price',
            'currency', 'availability', 'image_url', 'gtin', 'mpn',
            'weight', 'group_id',
        ];
    }

    private function mapAvailability(mixed $product): string
    {
        $stock = (int) $product->getFieldData('oxstock');
        $stockFlag = (int) $product->getFieldData('oxstockflag');

        if ($stock > 0) {
            return 'in_stock';
        }

        return match ($stockFlag) {
            1 => 'out_of_stock',
            4 => 'backorder',
            default => 'out_of_stock',
        };
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    private function resolveImageUrl(mixed $product, string $shopUrl): string
    {
        $pic = $product->getFieldData('oxpic1');
        if (empty($pic)) {
            return '';
        }

        if (str_starts_with($pic, 'http')) {
            return $pic;
        }

        return rtrim($shopUrl, '/') . '/out/pictures/master/product/1/' . $pic;
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
```

### B4. CsvFeedGenerator

**File:** `stripe/src/Stripe/Mcp/ProductFeed/CsvFeedGenerator.php`

Generates Stripe-format CSV with header row and proper escaping.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\ProductFeed;

use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;

class CsvFeedGenerator implements ProductFeedGeneratorInterface
{
    private const STRIPE_FIELD_MAP = [
        'id' => 'ID',
        'title' => 'Title',
        'description' => 'Description',
        'url' => 'Link',
        'brand' => 'Brand',
        'price' => 'Price',
        'availability' => 'Availability',
        'image_url' => 'image_link',
        'gtin' => 'GTIN',
        'mpn' => 'MPN',
        'group_id' => 'item_group_id',
    ];

    public function generate(array $products): string
    {
        $output = fopen('php://memory', 'r+');

        // Header row with Stripe field names
        fputcsv($output, array_values(self::STRIPE_FIELD_MAP));

        foreach ($products as $product) {
            $row = [];
            foreach (array_keys(self::STRIPE_FIELD_MAP) as $internalField) {
                $row[] = $product[$internalField] ?? '';
            }
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    public function getContentType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    public function getFileExtension(): string
    {
        return 'csv';
    }
}
```

### B5. JsonlFeedGenerator

**File:** `stripe/src/Stripe/Mcp/ProductFeed/JsonlFeedGenerator.php`

Generates OpenAI JSONL format with eligibility flags.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\ProductFeed;

use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;

class JsonlFeedGenerator implements ProductFeedGeneratorInterface
{
    public function __construct(
        private readonly string $storeCountry = 'DE',
        private readonly string $targetCountries = 'DE,AT,CH'
    ) {}

    public function generate(array $products): string
    {
        $lines = [];
        foreach ($products as $product) {
            $entry = [
                'item_id' => $product['id'],
                'title' => $product['title'],
                'description' => $product['description'],
                'url' => $product['url'],
                'brand' => $product['brand'],
                'price' => ($product['price'] ?? '0.00') . ' ' . ($product['currency'] ?? 'EUR'),
                'availability' => $product['availability'],
                'image_url' => $product['image_url'],
                'target_countries' => $this->targetCountries,
                'store_country' => $this->storeCountry,
                'is_eligible_search' => true,
                'is_eligible_checkout' => $product['availability'] === 'in_stock',
            ];

            if (!empty($product['gtin'])) {
                $entry['gtin'] = $product['gtin'];
            }
            if (!empty($product['group_id'])) {
                $entry['group_id'] = $product['group_id'];
            }

            $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines) . "\n";
    }

    public function getContentType(): string
    {
        return 'application/x-jsonlines; charset=utf-8';
    }

    public function getFileExtension(): string
    {
        return 'jsonl';
    }
}
```

### B6. ProductFeedCommand (CLI)

**File:** `stripe/src/Stripe/Mcp/Command/ProductFeedCommand.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Command;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFieldMapperInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProductFeedCommand extends Command
{
    protected static $defaultName = 'stripe:product-feed:generate';

    public function __construct(
        private readonly AcpProductServiceInterface $productService,
        private readonly CsvFeedGeneratorFactory $feedGeneratorFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate product feed for AI agent discovery')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Feed format: csv or jsonl', 'csv')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path', 'product-feed')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max products per batch', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        $generator = $this->feedGeneratorFactory->create($format);
        $limit = (int) $input->getOption('limit');
        $outputPath = $input->getOption('output') . '.' . $generator->getFileExtension();

        $output->writeln("Generating {$format} feed...");

        $result = $this->productService->listProducts(['limit' => $limit, 'offset' => 0]);
        $feedContent = $generator->generate($result['products']);

        file_put_contents($outputPath, $feedContent);

        $output->writeln("Feed written to {$outputPath} ({$result['total']} products)");

        return Command::SUCCESS;
    }
}
```

### B7. ProductFeedController (Event-Only)

**File:** `stripe/src/Stripe/Mcp/Controller/ProductFeedController.php`

Follows the strict event-only pattern. Validates auth, creates EventContext, dispatches event, reads feed content from context.

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;

class ProductFeedController
{
    public function __construct(
        private readonly McpAuthGuardInterface $authGuard,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function handleRequest(): void
    {
        // 1. Validate auth
        $authResult = $this->authGuard->authenticate();
        if (!$authResult->isAuthenticated()) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        // 2. Create context — ONLY DATA
        $context = new EventContext([
            'agentContext' => $authResult->getAgentContext(),
            'limit' => 1000,
            'offset' => 0,
        ]);

        // 3. Dispatch event — HANDLER DOES THE WORK
        $event = new ProductFeedRequestEvent($context);
        $this->eventDispatcher->dispatch($event);

        // 4. Read result from context
        $feedContent = $context->get('feedContent') ?? '';
        $contentType = $context->get('feedContentType') ?? 'text/csv; charset=utf-8';
        $fileExtension = $context->get('feedFileExtension') ?? 'csv';

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: inline; filename="product-feed.' . $fileExtension . '"');
        echo $feedContent;
    }
}
```

#### ProductFeedRequestEvent

**File:** `stripe/src/Stripe/Mcp/Event/ProductFeedRequestEvent.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Event;

use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;

class ProductFeedRequestEvent
{
    public function __construct(private readonly EventContext $context) {}

    public function getContext(): EventContext
    {
        return $this->context;
    }
}
```

#### ProductFeedRequestHandler

**File:** `stripe/src/Stripe/Mcp/Handler/ProductFeedRequestHandler.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\Payments\Stripe\Mcp\Event\ProductFeedRequestEvent;

class ProductFeedRequestHandler implements HandlerInterface
{
    public function __construct(
        private readonly AcpProductServiceInterface $productService,
        private readonly ProductFeedGeneratorInterface $feedGenerator
    ) {}

    public static function getHandledEventClass(): string
    {
        return ProductFeedRequestEvent::class;
    }

    public function handle(object $event): void
    {
        /** @var ProductFeedRequestEvent $event */
        $context = $event->getContext();
        $limit = $context->get('limit') ?? 1000;
        $offset = $context->get('offset') ?? 0;

        $result = $this->productService->listProducts([
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $feedContent = $this->feedGenerator->generate($result['products']);

        $context->set('feedContent', $feedContent);
        $context->set('feedContentType', $this->feedGenerator->getContentType());
        $context->set('feedFileExtension', $this->feedGenerator->getFileExtension());
    }
}
```

### B8. services.yaml Additions

```yaml
# === Product Feed (payment-component interfaces → stripe implementations) ===

OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\OxidProductService

OxidEsales\PaymentComponent\Mcp\Acp\ProductFieldMapperInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\OxidProductFieldMapper

OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface:
    class: OxidEsales\Payments\Stripe\Mcp\ProductFeed\CsvFeedGenerator

stripe.feed.jsonl_generator:
    class: OxidEsales\Payments\Stripe\Mcp\ProductFeed\JsonlFeedGenerator
    arguments:
        $storeCountry: '%stripe.feed.store_country%'
        $targetCountries: '%stripe.feed.target_countries%'

OxidEsales\Payments\Stripe\Mcp\Service\OxidArticleQueryServiceInterface:
    class: OxidEsales\Payments\Stripe\Mcp\Service\OxidArticleQueryService

# === Feed Event Handler (event-only controller pattern) ===

OxidEsales\Payments\Stripe\Mcp\Handler\ProductFeedRequestHandler:
    tags:
        - { name: payment.event_handler, priority: 100 }

# === Feed CLI Command ===

OxidEsales\Payments\Stripe\Mcp\Command\ProductFeedCommand:
    tags: [{ name: console.command }]

# === Parameters ===

parameters:
    stripe.feed.store_country: 'DE'
    stripe.feed.target_countries: 'DE,AT,CH'
```

### B9. metadata.php Addition

```php
'controllers' => [
    // ... existing ...
    'stripeproductfeed' => \OxidEsales\Payments\Stripe\Mcp\Controller\ProductFeedController::class,
],
```

---

## File Summary

| # | Module | File | Purpose | Est. Lines |
|---|--------|------|---------|-----------|
| 1 | payment-component | `src/Mcp/Acp/ProductFeedGeneratorInterface.php` | Feed generation contract | ~25 |
| 2 | payment-component | `src/Mcp/Acp/ProductFieldMapperInterface.php` | Field mapping contract | ~20 |
| 3 | stripe | `src/Stripe/Mcp/Service/OxidProductService.php` | Product query + mapping | ~50 |
| 4 | stripe | `src/Stripe/Mcp/Service/OxidArticleQueryServiceInterface.php` | Article query contract | ~15 |
| 5 | stripe | `src/Stripe/Mcp/Service/OxidArticleQueryService.php` | OXID article queries | ~80 |
| 6 | stripe | `src/Stripe/Mcp/Service/OxidProductFieldMapper.php` | OXID → ACP field mapping | ~100 |
| 7 | stripe | `src/Stripe/Mcp/ProductFeed/CsvFeedGenerator.php` | Stripe CSV format | ~55 |
| 8 | stripe | `src/Stripe/Mcp/ProductFeed/JsonlFeedGenerator.php` | OpenAI JSONL format | ~60 |
| 9 | stripe | `src/Stripe/Mcp/Command/ProductFeedCommand.php` | CLI feed generation | ~55 |
| 10 | stripe | `src/Stripe/Mcp/Controller/ProductFeedController.php` | HTTP feed endpoint (event-only) | ~40 |
| 11 | stripe | `src/Stripe/Mcp/Event/ProductFeedRequestEvent.php` | Feed request event | ~15 |
| 12 | stripe | `src/Stripe/Mcp/Handler/ProductFeedRequestHandler.php` | Feed generation handler | ~40 |
| | | **Total** | | **~555** |

---

## TDD Approach

### Step 1: OxidProductFieldMapper Tests
Test every field mapping: title truncation, availability logic (stock flags), image URL resolution, price formatting, null handling for optional fields.

### Step 2: CsvFeedGenerator Tests
Test header row matches Stripe field names. Test CSV escaping for special characters. Test empty product list.

### Step 3: JsonlFeedGenerator Tests
Test JSONL format (one JSON object per line). Test eligibility flags. Test country injection.

### Step 4: OxidProductService Tests
Mock `OxidArticleQueryServiceInterface` and `ProductFieldMapperInterface`. Test pagination (limit/offset). Test search filtering. Test `getProduct()` not-found case.

### Step 5: ProductFeedCommand Tests
Test format selection (csv vs jsonl). Test output file naming. Test product count output.

### Step 6: ProductFeedController Tests
Test auth rejection (401). Test correct Content-Type header. Test feed content output.

### Step 7: Full Validation
```bash
./bin/pre-commit-check.sh --full
```

---

## Verification Checklist

- [ ] `OxidProductFieldMapper` correctly maps all required ACP fields
- [ ] `OxidProductFieldMapper` handles missing manufacturer (empty brand)
- [ ] `OxidProductFieldMapper` resolves relative image URLs to absolute
- [ ] `OxidProductFieldMapper` truncates title at 150 chars, description at 5000
- [ ] `CsvFeedGenerator` produces valid CSV with Stripe header names
- [ ] `JsonlFeedGenerator` produces valid JSONL with one JSON per line
- [ ] `JsonlFeedGenerator` sets `is_eligible_checkout: false` for out-of-stock
- [ ] `OxidProductService.listProducts()` respects limit (max 100) and offset
- [ ] `OxidProductService.getProduct()` returns null for unknown IDs
- [ ] `ProductFeedCommand` writes feed to specified output path
- [ ] `ProductFeedController` requires Bearer token authentication
- [ ] Sprint 47's `ListProductsTool` works end-to-end with this service
- [ ] All existing 799+ tests continue to pass
- [ ] PHPCS, PHPStan (level max), PHPMD pass with zero new violations

---

## Acceptance Criteria

1. `list_products` MCP tool returns paginated OXID articles in ACP format
2. `bin/oe-console stripe:product-feed:generate --format=csv` produces valid Stripe CSV
3. `bin/oe-console stripe:product-feed:generate --format=jsonl` produces valid OpenAI JSONL
4. `GET /index.php?cl=stripeproductfeed` returns CSV feed with Bearer auth
5. Out-of-stock products have `availability: out_of_stock` and `is_eligible_checkout: false`
6. Feed generation handles 10,000+ articles without memory issues (streaming)
7. An Unzer module could implement `ProductFieldMapperInterface` for its own field mapping
