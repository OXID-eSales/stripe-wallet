# Sprint 24: Stock Restoration Service for Refunds

**Date:** 2026-01-28
**Priority:** HIGH
**Status:** TODO

---

## Objective

Create a `StockRestorationService` that restores stock for all order articles when a full refund is processed. The service implements the same logic as `storno()` in OXID's `OrderArticle.php` but applied to all articles in an order.

---

## Reference Implementation

**From `OrderArticle::storno()` (lines 217-251):**
```php
public function storno()
{
    $myConfig = Registry::getConfig();
    $sOrderArtId = Registry::getRequest()->getRequestEscapedParameter('sArtID');
    $oArticle = oxNew(OrderArticle::class);
    $oArticle->load($sOrderArtId);

    if ($oArticle->oxorderarticles__oxstorno->value == 1) {
        $oArticle->oxorderarticles__oxstorno->setValue(0);
        $sStockSign = -1;  // Reduce stock (un-storno)
    } else {
        $oArticle->oxorderarticles__oxstorno->setValue(1);
        $sStockSign = 1;   // Restore stock (storno)
    }

    // stock information
    if ($myConfig->getConfigParam('blUseStock')) {
        $oArticle->updateArticleStock(
            $oArticle->oxorderarticles__oxamount->value * $sStockSign,
            $myConfig->getConfigParam('blAllowNegativeStock')
        );
    }

    $oDb = DatabaseProvider::getDb();
    $sQ = "update oxorderarticles set oxstorno = :oxstorno where oxid = :oxid";
    $oDb->execute($sQ, [':oxstorno' => $oArticle->oxorderarticles__oxstorno->value, ':oxid' => $sOrderArtId]);

    // Recalculate order
    $oOrder = oxNew(Order::class);
    if ($oOrder->load($orderId)) {
        $oOrder->recalculateOrder();
    }
}
```

---

## TDD Approach

**Order of Operations:**
1. **Phase 1:** Write failing integration test for webhook refund scenario
2. **Phase 2:** Create interface `StockRestorationServiceInterface`
3. **Phase 3:** Create implementation `OxidStockRestorationService`
4. **Phase 4:** Register in `services.yaml`
5. **Phase 5:** Integrate into `RefundService.php`
6. **Phase 6:** Run `./bin/pre-commit-check.sh --full`

---

## Phase 1: Write Failing Test First

### Test 1.1: Integration Test - Webhook Full Refund Restores Stock

**File:** `tests/Integration/Stripe/Webhook/RefundWebhookStockRestorationTest.php`

**Test Scenario:**
1. Create order with 2 articles (Article A: qty 3, Article B: qty 2)
2. Initial stock: Article A = 100, Article B = 50
3. After order: Article A = 97, Article B = 48
4. Simulate webhook `charge.refunded` with full refund
5. Verify:
   - Article A stock = 100 (restored)
   - Article B stock = 50 (restored)
   - Order articles marked as storno (oxstorno = 1)
   - Order recalculated

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Webhook;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\OrderArticle;
use OxidEsales\Payments\Stripe\Service\StockRestorationServiceInterface;
use PHPUnit\Framework\TestCase;

class RefundWebhookStockRestorationTest extends TestCase
{
    private const TEST_ORDER_ID = 'e2e_refund_stock_order';
    private const TEST_ARTICLE_A_ID = 'e2e_refund_article_a';
    private const TEST_ARTICLE_B_ID = 'e2e_refund_article_b';

    public function testFullRefundRestoresStockForAllOrderArticles(): void
    {
        // Arrange
        $this->createTestArticle(self::TEST_ARTICLE_A_ID, 100);
        $this->createTestArticle(self::TEST_ARTICLE_B_ID, 50);
        $this->createTestOrderWithArticles(self::TEST_ORDER_ID, [
            self::TEST_ARTICLE_A_ID => 3,
            self::TEST_ARTICLE_B_ID => 2,
        ]);

        // Verify stock reduced after order
        $this->assertArticleStock(self::TEST_ARTICLE_A_ID, 97);
        $this->assertArticleStock(self::TEST_ARTICLE_B_ID, 48);

        // Act - Simulate refund via service
        $stockService = $this->getService();
        $stockService->restoreStockForOrder(self::TEST_ORDER_ID);

        // Assert - Stock restored
        $this->assertArticleStock(self::TEST_ARTICLE_A_ID, 100);
        $this->assertArticleStock(self::TEST_ARTICLE_B_ID, 50);

        // Assert - Order articles marked as storno
        $this->assertOrderArticlesStorno(self::TEST_ORDER_ID, true);
    }

    public function testRestoreStockSkipsAlreadyStornoedArticles(): void
    {
        // Arrange - Article already storno'd
        $this->createTestArticle(self::TEST_ARTICLE_A_ID, 100);
        $this->createTestOrderWithArticles(self::TEST_ORDER_ID, [
            self::TEST_ARTICLE_A_ID => 5,
        ]);
        $this->markOrderArticleAsStorno(self::TEST_ORDER_ID, self::TEST_ARTICLE_A_ID);

        // Stock should already be restored from manual storno
        $this->assertArticleStock(self::TEST_ARTICLE_A_ID, 100);

        // Act - Refund should not double-restore
        $stockService = $this->getService();
        $stockService->restoreStockForOrder(self::TEST_ORDER_ID);

        // Assert - Stock unchanged (not over-restored)
        $this->assertArticleStock(self::TEST_ARTICLE_A_ID, 100);
    }

    public function testRestoreStockRespectsUseStockConfig(): void
    {
        // Arrange - Stock management disabled
        $this->setConfigParam('blUseStock', false);
        $this->createTestArticle(self::TEST_ARTICLE_A_ID, 100);
        $this->createTestOrderWithArticles(self::TEST_ORDER_ID, [
            self::TEST_ARTICLE_A_ID => 5,
        ]);

        // Stock NOT reduced when blUseStock=false
        $this->assertArticleStock(self::TEST_ARTICLE_A_ID, 100);

        // Act
        $stockService = $this->getService();
        $stockService->restoreStockForOrder(self::TEST_ORDER_ID);

        // Assert - Stock unchanged, but storno flag still set
        $this->assertArticleStock(self::TEST_ARTICLE_A_ID, 100);
        $this->assertOrderArticlesStorno(self::TEST_ORDER_ID, true);
    }

    public function testRestoreStockRecalculatesOrder(): void
    {
        // Arrange
        $this->createTestArticle(self::TEST_ARTICLE_A_ID, 100);
        $this->createTestOrderWithArticles(self::TEST_ORDER_ID, [
            self::TEST_ARTICLE_A_ID => 2,
        ]);

        $orderTotalBefore = $this->getOrderTotal(self::TEST_ORDER_ID);

        // Act
        $stockService = $this->getService();
        $stockService->restoreStockForOrder(self::TEST_ORDER_ID);

        // Assert - Order recalculated (total should change due to storno)
        $orderTotalAfter = $this->getOrderTotal(self::TEST_ORDER_ID);
        $this->assertNotEquals($orderTotalBefore, $orderTotalAfter);
    }

    // Helper methods...
}
```

### Test 1.2: Unit Test - StockRestorationService

**File:** `tests/Unit/Stripe/Service/StockRestorationServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\Payments\Stripe\Service\OxidStockRestorationService;
use OxidEsales\Payments\Stripe\Service\StockRestorationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StockRestorationServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testImplementsInterface(): void
    {
        $service = new OxidStockRestorationService(
            $this->connection,
            $this->logger,
            true  // blUseStock
        );

        $this->assertInstanceOf(StockRestorationServiceInterface::class, $service);
    }

    public function testRestoreStockUpdatesArticleStock(): void
    {
        // Test that stock is updated via SQL
    }

    public function testRestoreStockSetsStornoFlag(): void
    {
        // Test that oxstorno is set to 1
    }

    public function testRestoreStockSkipsAlreadyStornoed(): void
    {
        // Test that articles with oxstorno=1 are skipped
    }

    public function testRestoreStockLogsSuccess(): void
    {
        // Test logging
    }
}
```

---

## Phase 2: Create Interface

**File:** `src/Stripe/Service/StockRestorationServiceInterface.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Interface for stock restoration on refund.
 *
 * Sprint 24: Extracted from OXID's OrderArticle::storno() logic.
 *
 * @since 2.0.0
 */
interface StockRestorationServiceInterface
{
    /**
     * Restore stock for all articles in an order.
     *
     * - Marks all order articles as storno (oxstorno = 1)
     * - Restores stock for each article (if blUseStock is enabled)
     * - Recalculates order totals
     *
     * @param string $orderId The order ID to process
     * @return int Number of articles processed
     */
    public function restoreStockForOrder(string $orderId): int;
}
```

---

## Phase 3: Create Implementation

**File:** `src/Stripe/Service/OxidStockRestorationService.php`

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Application\Model\OrderArticle;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OXID implementation of StockRestorationServiceInterface.
 *
 * Sprint 24: Implements same logic as OrderArticle::storno() but for all
 * articles in an order. Used when processing full refunds.
 *
 * @since 2.0.0
 */
final class OxidStockRestorationService implements StockRestorationServiceInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Connection $connection,
        ?LoggerInterface $logger = null,
        private readonly bool $useStock = true,
        private readonly bool $allowNegativeStock = false
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function restoreStockForOrder(string $orderId): int
    {
        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            $this->logger->warning('Order not found for stock restoration', ['orderId' => $orderId]);
            return 0;
        }

        $orderArticles = $order->getOrderArticles();
        $processedCount = 0;

        foreach ($orderArticles as $orderArticle) {
            if ($this->processOrderArticle($orderArticle)) {
                $processedCount++;
            }
        }

        // Recalculate order after all articles processed
        if ($processedCount > 0) {
            $order->recalculateOrder();
            $this->logger->info('Stock restored for order', [
                'orderId' => $orderId,
                'articlesProcessed' => $processedCount,
            ]);
        }

        return $processedCount;
    }

    private function processOrderArticle(OrderArticle $orderArticle): bool
    {
        // Skip if already storno'd
        if ((int) $orderArticle->oxorderarticles__oxstorno->value === 1) {
            return false;
        }

        $amount = (float) $orderArticle->oxorderarticles__oxamount->value;
        $orderArticleId = $orderArticle->getId();

        // Restore stock if stock management is enabled
        if ($this->useStock && $amount > 0) {
            $orderArticle->updateArticleStock($amount, $this->allowNegativeStock);
        }

        // Mark as storno'd
        $this->connection->executeStatement(
            'UPDATE oxorderarticles SET oxstorno = 1 WHERE oxid = :oxid',
            ['oxid' => $orderArticleId]
        );

        return true;
    }
}
```

---

## Phase 4: Register in services.yaml

**File:** `services.yaml`

```yaml
  # ==========================================
  # Stock Restoration Service (Sprint 24)
  # ==========================================
  # Restores stock for all order articles on full refund.
  # Implements same logic as OXID's OrderArticle::storno().

  OxidEsales\Payments\Stripe\Service\StockRestorationServiceInterface:
    class: OxidEsales\Payments\Stripe\Service\OxidStockRestorationService
    arguments:
      $connection: '@doctrine.dbal.connection'
      $logger: '@oxid_esales.monolog.logger'
      $useStock: '%oxid.use_stock%'
      $allowNegativeStock: '%oxid.allow_negative_stock%'
    public: false

parameters:
  oxid.use_stock: true
  oxid.allow_negative_stock: false
```

---

## Phase 5: Integrate into RefundService

**File:** `src/Stripe/Service/RefundService.php`

**Changes:**
1. Add `StockRestorationServiceInterface` to constructor
2. Call `restoreStockForOrder()` after successful refund

```php
public function __construct(
    private readonly StripeAdapterFactoryInterface $adapterFactory,
    private readonly StockRestorationServiceInterface $stockRestorationService,
    ?LoggerInterface $logger = null
) {
    $this->logger = $logger ?? new NullLogger();
}

private function handleRefundResponse(Refund $refund, string $chargeId, string $orderId): RefundResult
{
    $status = $refund->status ?? 'unknown';

    if (!in_array($status, ['succeeded', 'pending'], true)) {
        return RefundResult::failure("Refund failed with status: {$status}");
    }

    // Restore stock for all order articles
    $this->stockRestorationService->restoreStockForOrder($orderId);

    $this->logger->info('Refund processed successfully', [
        'refund_id' => $refund->id,
        'amount' => ($refund->amount ?? 0) / 100,
        'charge_id' => $chargeId,
        'status' => $status,
    ]);

    return RefundResult::success(
        $refund->id ?? 'unknown',
        (int) ($refund->amount ?? 0),
        $refund->currency ?? 'eur',
        $status
    );
}
```

---

## Phase 6: Run Pre-Commit Check

```bash
./bin/pre-commit-check.sh --full
```

**Expected:** All tests pass, no PHPStan/PHPCS/PHPMD errors

---

## Files Summary

### CREATE (4 files)

| File | Type |
|------|------|
| `tests/Integration/Stripe/Webhook/RefundWebhookStockRestorationTest.php` | Integration Test |
| `tests/Unit/Stripe/Service/StockRestorationServiceTest.php` | Unit Test |
| `src/Stripe/Service/StockRestorationServiceInterface.php` | Interface |
| `src/Stripe/Service/OxidStockRestorationService.php` | Service |

### MODIFY (2 files)

| File | Change |
|------|--------|
| `services.yaml` | Add StockRestorationService registration |
| `src/Stripe/Service/RefundService.php` | Inject and use StockRestorationService |

---

## Acceptance Criteria

- [ ] Integration test verifies webhook refund restores stock
- [ ] Unit tests cover all service methods
- [ ] Service implements `StockRestorationServiceInterface`
- [ ] Stock restored for all non-storno'd order articles
- [ ] Already storno'd articles are skipped (no double-restore)
- [ ] `blUseStock` config is respected
- [ ] Order is recalculated after stock restoration
- [ ] All tests pass (Unit + Integration)
- [ ] `./bin/pre-commit-check.sh --full` passes

---

## Definition of Done

1. Tests written and passing
2. Interface created
3. Service implemented
4. Registered in services.yaml
5. Integrated into RefundService
6. Pre-commit check passes
7. Move this file to `done/SPRINT-24-stock-restoration-service.md`
8. Update `status.md`
