# Sprint 2: TDD Implementation Plan - Database Table Consolidation

**Approach:** Test-Driven Development with Migration Safety
**Estimated Tests:** 15+ unit tests, 10+ integration tests

---

## TDD Workflow for Database Changes

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    DATABASE REFACTORING TDD CYCLE                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. Write integration test that uses OLD table/code                         │
│  2. Verify test passes (baseline)                                           │
│  3. Write test for NEW behavior (fails)                                     │
│  4. Update code to use NEW table                                            │
│  5. Verify BOTH old and new tests pass                                      │
│  6. Create migration to remove old table                                    │
│  7. Run full test suite                                                     │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Phase 1: Webhook Table Consolidation

### Current State Analysis

**Tables:**
- `osc_payment_webhooklogs` (Migration) - used by `DoctrineWebhookLogRepository`
- `osc_payment_webhook_log` (Events.php) - used by `WebhookController`, `WebhookProcessingService`

### Step 1.1: Verify Existing Behavior (Baseline Tests)

**Test File:** `tests/Integration/Component/Repository/DoctrineWebhookLogRepositoryTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Component\Repository;

use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;
use OxidSolutionCatalysts\Payments\Component\Repository\DoctrineWebhookLogRepository;

/**
 * @covers \OxidSolutionCatalysts\Payments\Component\Repository\DoctrineWebhookLogRepository
 */
class DoctrineWebhookLogRepositoryBaselineTest extends IntegrationTestCase
{
    private DoctrineWebhookLogRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DoctrineWebhookLogRepository($this->connection);
    }

    public function testCanSaveWebhookLog(): void
    {
        // Arrange
        $eventId = 'evt_baseline_' . uniqid();

        // Act
        $this->repository->save([
            'eventId' => $eventId,
            'eventType' => 'payment_intent.succeeded',
            'status' => 'received',
            'contractId' => null,
        ]);

        // Assert
        $found = $this->repository->findByEventId($eventId);
        $this->assertNotNull($found);
        $this->assertEquals('payment_intent.succeeded', $found['eventType']);
    }

    public function testCanFindByEventId(): void
    {
        // Arrange
        $eventId = 'evt_find_' . uniqid();
        $this->repository->save([
            'eventId' => $eventId,
            'eventType' => 'charge.refunded',
            'status' => 'processed',
        ]);

        // Act
        $found = $this->repository->findByEventId($eventId);

        // Assert
        $this->assertNotNull($found);
        $this->assertEquals($eventId, $found['eventId']);
    }

    public function testReturnsNullForNonExistentEventId(): void
    {
        $found = $this->repository->findByEventId('evt_does_not_exist');
        $this->assertNull($found);
    }

    public function testCanUpdateStatus(): void
    {
        // Arrange
        $eventId = 'evt_update_' . uniqid();
        $this->repository->save([
            'eventId' => $eventId,
            'eventType' => 'payment_intent.created',
            'status' => 'received',
        ]);

        // Act
        $this->repository->updateStatus($eventId, 'processed');

        // Assert
        $found = $this->repository->findByEventId($eventId);
        $this->assertEquals('processed', $found['status']);
    }

    public function testCanSaveWithContractId(): void
    {
        // Arrange
        $eventId = 'evt_contract_' . uniqid();
        $contractId = 'contract_test_123';

        // Act
        $this->repository->save([
            'eventId' => $eventId,
            'eventType' => 'payment_intent.succeeded',
            'status' => 'received',
            'contractId' => $contractId,
        ]);

        // Assert
        $found = $this->repository->findByEventId($eventId);
        $this->assertEquals($contractId, $found['contractId']);
    }
}
```

### Step 1.2: Test WebhookController Uses Repository

**Test File:** `tests/Unit/Stripe/Controller/Webhook/WebhookControllerRepositoryTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Controller\Webhook;

use OxidSolutionCatalysts\Payments\Stripe\Controller\Webhook\WebhookController;
use OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests that WebhookController uses WebhookLogRepository instead of raw SQL
 */
class WebhookControllerRepositoryTest extends TestCase
{
    public function testWebhookControllerUsesRepositoryForLogging(): void
    {
        // Arrange
        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($data) {
                return isset($data['eventId'])
                    && isset($data['eventType'])
                    && isset($data['status']);
            }));

        $controller = new WebhookController($repository, /* other deps */);

        // Act
        $controller->logWebhookReceived('evt_test', 'payment_intent.succeeded', '{}');

        // No assertion needed - mock expectation verifies
    }

    public function testWebhookControllerUsesRepositoryForStatusUpdate(): void
    {
        // Arrange
        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('updateStatus')
            ->with('evt_test', 'processed');

        $controller = new WebhookController($repository, /* other deps */);

        // Act
        $controller->markWebhookProcessed('evt_test');
    }

    public function testWebhookControllerUsesRepositoryForErrorLogging(): void
    {
        // Arrange
        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('updateStatusWithError')
            ->with('evt_test', 'failed', 'Error message');

        $controller = new WebhookController($repository, /* other deps */);

        // Act
        $controller->markWebhookFailed('evt_test', 'Error message');
    }
}
```

### Step 1.3: Test WebhookProcessingService Uses Repository

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\WebhookProcessingService;
use OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface;
use PHPUnit\Framework\TestCase;

class WebhookProcessingServiceRepositoryTest extends TestCase
{
    public function testUsesRepositoryNotRawSql(): void
    {
        // Arrange
        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        $repository
            ->expects($this->atLeastOnce())
            ->method('save');

        $service = new WebhookProcessingService($repository, /* other deps */);

        // Act
        $service->processWebhook([
            'id' => 'evt_test',
            'type' => 'payment_intent.succeeded',
        ]);
    }

    public function testStoresProviderInLog(): void
    {
        // Arrange
        $repository = $this->createMock(WebhookLogRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($data) {
                return isset($data['provider']) && $data['provider'] === 'stripe';
            }));

        $service = new WebhookProcessingService($repository, /* other deps */);

        // Act
        $service->processWebhook(['id' => 'evt_test', 'type' => 'charge.succeeded']);
    }
}
```

### Step 1.4: Update WebhookLogRepositoryInterface

**Test File:** `tests/Unit/Component/Repository/WebhookLogRepositoryInterfaceTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\Repository;

use OxidSolutionCatalysts\Payments\Component\Repository\WebhookLogRepositoryInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WebhookLogRepositoryInterfaceTest extends TestCase
{
    public function testInterfaceHasRequiredMethods(): void
    {
        $reflection = new ReflectionClass(WebhookLogRepositoryInterface::class);

        $this->assertTrue($reflection->hasMethod('save'));
        $this->assertTrue($reflection->hasMethod('findByEventId'));
        $this->assertTrue($reflection->hasMethod('updateStatus'));
        $this->assertTrue($reflection->hasMethod('updateStatusWithError'));
    }

    public function testSaveMethodAcceptsProviderAndPayload(): void
    {
        $reflection = new ReflectionClass(WebhookLogRepositoryInterface::class);
        $saveMethod = $reflection->getMethod('save');

        // Check method signature supports new fields
        $params = $saveMethod->getParameters();
        $this->assertCount(1, $params); // Single array parameter

        // The array should support: eventId, eventType, status, provider, payload, contractId
    }
}
```

---

## Phase 2: Customer Table Consolidation

### Current State Analysis

**Tables:**
- `osc_payment_customer` (Migration) - provider-agnostic, unused
- `osc_stripe_customer_mapping` (Events.php) - Stripe-specific, used by `StripeCustomerService`

### Step 2.1: Baseline Test for StripeCustomerService

**Test File:** `tests/Integration/Stripe/Service/StripeCustomerServiceBaselineTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Service;

use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;
use OxidSolutionCatalysts\Payments\Stripe\Service\StripeCustomerService;

class StripeCustomerServiceBaselineTest extends IntegrationTestCase
{
    public function testCanStoreCustomerMapping(): void
    {
        // Arrange
        $service = $this->getStripeCustomerService();
        $userId = 'user_baseline_' . uniqid();
        $stripeCustomerId = 'cus_test_' . uniqid();

        // Act
        $service->storeCustomerMapping($userId, $stripeCustomerId);

        // Assert
        $retrieved = $service->getStripeCustomerId($userId);
        $this->assertEquals($stripeCustomerId, $retrieved);
    }

    public function testReturnsNullForUnmappedUser(): void
    {
        $service = $this->getStripeCustomerService();

        $result = $service->getStripeCustomerId('user_does_not_exist');

        $this->assertNull($result);
    }

    public function testCanUpdateExistingMapping(): void
    {
        // Arrange
        $service = $this->getStripeCustomerService();
        $userId = 'user_update_' . uniqid();

        $service->storeCustomerMapping($userId, 'cus_old');

        // Act
        $service->storeCustomerMapping($userId, 'cus_new');

        // Assert
        $this->assertEquals('cus_new', $service->getStripeCustomerId($userId));
    }
}
```

### Step 2.2: Test Using osc_payment_customer Table

**Test File:** `tests/Unit/Stripe/Service/StripeCustomerServiceProviderAgnosticTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\StripeCustomerService;
use OxidSolutionCatalysts\Payments\Component\Repository\PaymentCustomerRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests that StripeCustomerService uses the provider-agnostic PaymentCustomerRepository
 */
class StripeCustomerServiceProviderAgnosticTest extends TestCase
{
    public function testUsesPaymentCustomerRepository(): void
    {
        // Arrange
        $repository = $this->createMock(PaymentCustomerRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('findByUserId')
            ->with('user_123')
            ->willReturn(['paymentCustomerId' => 'cus_stripe_123']);

        $service = new StripeCustomerService($repository);

        // Act
        $result = $service->getStripeCustomerId('user_123');

        // Assert
        $this->assertEquals('cus_stripe_123', $result);
    }

    public function testStoresWithProviderField(): void
    {
        // Arrange
        $repository = $this->createMock(PaymentCustomerRepositoryInterface::class);

        $repository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($data) {
                // Should NOT have stripe-specific field names
                return isset($data['userId'])
                    && isset($data['paymentCustomerId'])
                    && !isset($data['OXSTRIPECUSTOMERID']); // No Stripe-specific columns
            }));

        $service = new StripeCustomerService($repository);

        // Act
        $service->storeCustomerMapping('user_123', 'cus_new_456');
    }

    public function testHandlesMultipleProviders(): void
    {
        // Future-proofing: same user can have different provider customer IDs
        // This test ensures the architecture supports it

        $repository = $this->createMock(PaymentCustomerRepositoryInterface::class);

        // User already has PayPal customer ID, now adding Stripe
        $repository
            ->method('findByUserId')
            ->willReturn([
                'paymentCustomerId' => 'cus_stripe_123',
                // Future: could have paypalCustomerId, unzerCustomerId, etc.
            ]);

        $service = new StripeCustomerService($repository);

        $this->assertEquals('cus_stripe_123', $service->getStripeCustomerId('user_123'));
    }
}
```

### Step 2.3: PaymentCustomerRepository Tests

**Test File:** `tests/Unit/Component/Repository/PaymentCustomerRepositoryTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Component\Repository;

use OxidSolutionCatalysts\Payments\Component\Repository\DoctrinePaymentCustomerRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class PaymentCustomerRepositoryTest extends TestCase
{
    public function testUsesCorrectTableName(): void
    {
        // Verify repository uses osc_payment_customer, NOT osc_stripe_customer_mapping
        $reflection = new \ReflectionClass(DoctrinePaymentCustomerRepository::class);
        $property = $reflection->getProperty('tableName');
        $property->setAccessible(true);

        $connection = $this->createMock(Connection::class);
        $repository = new DoctrinePaymentCustomerRepository($connection);

        $this->assertEquals('osc_payment_customer', $property->getValue($repository));
    }

    public function testSaveUsesProviderAgnosticColumns(): void
    {
        // Arrange
        $connection = $this->createMock(Connection::class);

        $connection
            ->expects($this->once())
            ->method('insert')
            ->with(
                'osc_payment_customer',
                $this->callback(function ($data) {
                    // Should use OXPAYMENTCUSTOMERID, not OXSTRIPECUSTOMERID
                    return isset($data['OXPAYMENTCUSTOMERID'])
                        && !isset($data['OXSTRIPECUSTOMERID']);
                })
            );

        $repository = new DoctrinePaymentCustomerRepository($connection);

        // Act
        $repository->save([
            'userId' => 'user_123',
            'paymentCustomerId' => 'cus_123',
        ]);
    }
}
```

---

## Phase 3: Remove Payment Details Table

### Step 3.1: Verify Table is Unused

**Test File:** `tests/Integration/Stripe/Repository/PaymentDetailsTableUsageTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Repository;

use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;

class PaymentDetailsTableUsageTest extends IntegrationTestCase
{
    public function testPaymentDetailsTableIsEmpty(): void
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM osc_stripe_payment_details'
        );

        $this->assertEquals(0, $count, 'Payment details table should be empty');
    }

    public function testNoCodeReferencesPaymentDetailsTable(): void
    {
        // This is more of a static analysis test
        // Verifies no production code writes to this table

        $srcDir = __DIR__ . '/../../../../src';
        $files = $this->findPhpFiles($srcDir);

        $references = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (
                strpos($content, 'osc_stripe_payment_details') !== false
                && strpos($file, 'Events.php') === false // Exclude Events.php (creates table)
            ) {
                $references[] = $file;
            }
        }

        // Only StripePaymentDetailsRepository should reference it
        // If it's truly unused, this can be empty
        $this->assertCount(
            1,
            $references,
            'Only StripePaymentDetailsRepository should reference the table'
        );
    }

    public function testStripePaymentDetailsRepositoryCanBeRemoved(): void
    {
        // Verify no other code depends on StripePaymentDetailsRepository
        $srcDir = __DIR__ . '/../../../../src';
        $files = $this->findPhpFiles($srcDir);

        $usages = [];
        foreach ($files as $file) {
            if (strpos($file, 'StripePaymentDetailsRepository') !== false) {
                continue; // Skip the repository itself
            }

            $content = file_get_contents($file);
            if (strpos($content, 'StripePaymentDetailsRepository') !== false) {
                $usages[] = $file;
            }
        }

        $this->assertEmpty(
            $usages,
            'No code should depend on StripePaymentDetailsRepository: ' . implode(', ', $usages)
        );
    }

    private function findPhpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
```

---

## Phase 4: Migration Tests

### Step 4.1: Test Migration Creates Correct Schema

**Test File:** `tests/Integration/Database/TableConsolidationMigrationTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Database;

use OxidSolutionCatalysts\Payments\Tests\Integration\IntegrationTestCase;

class TableConsolidationMigrationTest extends IntegrationTestCase
{
    public function testOnlySevenTablesExistAfterMigration(): void
    {
        $tables = $this->connection->fetchFirstColumn(
            "SHOW TABLES LIKE 'osc_%'"
        );

        $expectedTables = [
            'osc_payment_contract',
            'osc_payment_transaction',
            'osc_payment_order_state',
            'osc_payment_customer',
            'osc_payment_idempotency',
            'osc_payment_sessions',
            'osc_payment_webhooklogs',
        ];

        $unexpectedTables = [
            'osc_payment_webhook_log',      // Should be removed
            'osc_stripe_customer_mapping',  // Should be removed
            'osc_stripe_payment_details',   // Should be removed
        ];

        foreach ($expectedTables as $table) {
            $this->assertContains($table, $tables, "Expected table $table to exist");
        }

        foreach ($unexpectedTables as $table) {
            $this->assertNotContains($table, $tables, "Table $table should have been removed");
        }
    }

    public function testWebhookLogsTableHasAllRequiredColumns(): void
    {
        $columns = $this->connection->fetchFirstColumn(
            "SHOW COLUMNS FROM osc_payment_webhooklogs"
        );

        $requiredColumns = [
            'OXID',
            'OXEVENTID',
            'OXEVENTTYPE',
            'OXCONTRACTID',
            'OXSTATUS',
            'OXRECEIVEDAT',
            'OXERROR',
            // New columns after consolidation:
            'OXPROVIDER',     // Added from webhook_log
            'OXPAYLOAD',      // Added from webhook_log
            'OXPROCESSEDAT',  // Added for tracking
        ];

        foreach ($requiredColumns as $column) {
            $this->assertContains($column, $columns, "Column $column should exist");
        }
    }

    public function testPaymentCustomerTableHasProviderAgnosticSchema(): void
    {
        $columns = $this->connection->fetchFirstColumn(
            "SHOW COLUMNS FROM osc_payment_customer"
        );

        // Should have generic column
        $this->assertContains('OXPAYMENTCUSTOMERID', $columns);

        // Should NOT have Stripe-specific columns
        $this->assertNotContains('OXSTRIPECUSTOMERID', $columns);
    }
}
```

---

## Phase 5: Events.php Cleanup Tests

### Step 5.1: Test Events.php Only Adds Columns

**Test File:** `tests/Unit/Stripe/Core/EventsCleanupTest.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Core;

use OxidSolutionCatalysts\Payments\Stripe\Core\Events;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class EventsCleanupTest extends TestCase
{
    public function testEventsDoesNotCreateWebhookTable(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'osc_payment_webhook_log',
            $source,
            'Events.php should not create osc_payment_webhook_log table'
        );
    }

    public function testEventsDoesNotCreateCustomerMappingTable(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'osc_stripe_customer_mapping',
            $source,
            'Events.php should not create osc_stripe_customer_mapping table'
        );
    }

    public function testEventsDoesNotCreatePaymentDetailsTable(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'osc_stripe_payment_details',
            $source,
            'Events.php should not create osc_stripe_payment_details table'
        );
    }

    public function testEventsOnlyAddsColumnsToOxidTables(): void
    {
        $reflection = new ReflectionClass(Events::class);
        $source = file_get_contents($reflection->getFileName());

        // Should have ALTER TABLE for OXID tables
        $this->assertStringContainsString('ALTER TABLE `oxorder`', $source);
        $this->assertStringContainsString('ALTER TABLE `oxorderarticles`', $source);
        $this->assertStringContainsString('ALTER TABLE `oxuser`', $source);

        // Should NOT have CREATE TABLE for osc_ tables
        // (osc_ tables should come from migrations only)
        preg_match_all('/CREATE TABLE `osc_/', $source, $matches);
        $this->assertEmpty(
            $matches[0],
            'Events.php should not CREATE any osc_ tables'
        );
    }
}
```

---

## Implementation Order

### Round 1: Create Interface & Repository Updates

| Order | Task | Tests First |
|-------|------|-------------|
| 1.1 | Add `provider`, `payload` to `WebhookLogRepositoryInterface` | Interface test |
| 1.2 | Update `DoctrineWebhookLogRepository` | Unit + Integration |
| 1.3 | Create `PaymentCustomerRepositoryInterface` | Interface test |
| 1.4 | Create `DoctrinePaymentCustomerRepository` | Unit + Integration |

### Round 2: Update Service Layer

| Order | Task | Tests First |
|-------|------|-------------|
| 2.1 | Update `WebhookController` to use repository | Unit test mocks |
| 2.2 | Update `WebhookProcessingService` to use repository | Unit test mocks |
| 2.3 | Update `StripeCustomerService` to use repository | Unit test mocks |

### Round 3: Create Migration

| Order | Task | Tests First |
|-------|------|-------------|
| 3.1 | Create `Version20251202_ConsolidateTables.php` | Migration test |
| 3.2 | Add columns to `osc_payment_webhooklogs` | Schema test |
| 3.3 | Drop redundant tables | Table existence test |

### Round 4: Cleanup

| Order | Task | Tests First |
|-------|------|-------------|
| 4.1 | Remove table creation from `Events.php` | Events cleanup test |
| 4.2 | Remove `StripePaymentDetailsRepository` | Usage test |
| 4.3 | Run full test suite | CI/CD |

---

## Migration File Template

**File:** `migration/data/Version20251202_ConsolidateTables.php`

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251202_ConsolidateTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolidate duplicate tables: webhooks, customer mapping, remove payment details';
    }

    public function up(Schema $schema): void
    {
        // 1. Add missing columns to osc_payment_webhooklogs
        $this->addSql("
            ALTER TABLE osc_payment_webhooklogs
            ADD COLUMN IF NOT EXISTS OXPROVIDER VARCHAR(50) NOT NULL DEFAULT 'stripe' AFTER OXCONTRACTID,
            ADD COLUMN IF NOT EXISTS OXPAYLOAD MEDIUMTEXT NULL AFTER OXPROVIDER,
            ADD COLUMN IF NOT EXISTS OXPROCESSEDAT DATETIME NULL AFTER OXRECEIVEDAT
        ");

        // 2. Migrate data from osc_payment_webhook_log to osc_payment_webhooklogs (if any)
        $this->addSql("
            INSERT IGNORE INTO osc_payment_webhooklogs
                (OXID, OXEVENTID, OXEVENTTYPE, OXPROVIDER, OXPAYLOAD, OXSTATUS, OXRECEIVEDAT, OXERROR)
            SELECT
                OXID, OXEVENTID, OXEVENTTYPE, OXPROVIDER, OXPAYLOAD, OXSTATUS, OXCREATED, OXERRORMESSAGE
            FROM osc_payment_webhook_log
            WHERE OXEVENTID NOT IN (SELECT OXEVENTID FROM osc_payment_webhooklogs)
        ");

        // 3. Migrate data from osc_stripe_customer_mapping to osc_payment_customer (if any)
        $this->addSql("
            INSERT IGNORE INTO osc_payment_customer
                (OXID, OXUSERID, OXPAYMENTCUSTOMERID, OXCREATED, OXUPDATED)
            SELECT
                OXID, OXUSERID, OXSTRIPECUSTOMERID, OXCREATED, IFNULL(OXUPDATED, OXCREATED)
            FROM osc_stripe_customer_mapping
            WHERE OXUSERID NOT IN (SELECT OXUSERID FROM osc_payment_customer)
        ");

        // 4. Drop redundant tables
        $this->addSql("DROP TABLE IF EXISTS osc_payment_webhook_log");
        $this->addSql("DROP TABLE IF EXISTS osc_stripe_customer_mapping");
        $this->addSql("DROP TABLE IF EXISTS osc_stripe_payment_details");
    }

    public function down(Schema $schema): void
    {
        // Recreate tables if rollback needed
        // ... (reverse operations)
    }
}
```

---

## Run Commands

```bash
# ═══════════════════════════════════════════════════════════════════════════════
# UNIT TESTS (no database required)
# ═══════════════════════════════════════════════════════════════════════════════

# Run baseline unit tests (should all pass)
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "Baseline"

# Run consolidation unit tests (will fail until implemented)
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "Consolidation|ProviderAgnostic|Cleanup"

# Run specific unit test class
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit \
    --filter "WebhookControllerRepositoryTest"

# ═══════════════════════════════════════════════════════════════════════════════
# INTEGRATION TESTS (requires database + OXID bootstrap)
# ═══════════════════════════════════════════════════════════════════════════════

# Run baseline integration tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --filter "Baseline"

# Run consolidation integration tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --filter "Consolidation|TableConsolidation"

# Run specific integration test class
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --filter "DoctrineWebhookLogRepositoryTest"

# Run migration integration tests
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php \
    --filter "MigrationTest"

# ═══════════════════════════════════════════════════════════════════════════════
# MIGRATIONS
# ═══════════════════════════════════════════════════════════════════════════════

# Run migration
docker compose exec -T php vendor/bin/oe-console oe:module:migrations:run osc-stripe

# Check migration status
docker compose exec -T php vendor/bin/oe-console oe:module:migrations:status osc-stripe

# ═══════════════════════════════════════════════════════════════════════════════
# DATABASE VERIFICATION
# ═══════════════════════════════════════════════════════════════════════════════

# List all payment tables
docker compose exec mysql mysql -uroot -proot example -e "SHOW TABLES LIKE 'osc_%';"

# Check specific table structure
docker compose exec mysql mysql -uroot -proot example -e "DESCRIBE osc_payment_webhooklogs;"

# Count records in tables (verify data migration)
docker compose exec mysql mysql -uroot -proot example -e "
    SELECT 'osc_payment_webhooklogs' as tbl, COUNT(*) as cnt FROM osc_payment_webhooklogs
    UNION ALL
    SELECT 'osc_payment_webhook_log', COUNT(*) FROM osc_payment_webhook_log
    UNION ALL
    SELECT 'osc_payment_customer', COUNT(*) FROM osc_payment_customer
    UNION ALL
    SELECT 'osc_stripe_customer_mapping', COUNT(*) FROM osc_stripe_customer_mapping;
"

# ═══════════════════════════════════════════════════════════════════════════════
# FULL TEST SUITE (CI/CD)
# ═══════════════════════════════════════════════════════════════════════════════

# Run pre-commit checks (includes all tests)
./source/extensions/stripe/bin/pre-commit-check.sh

# Or run unit and integration separately
docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Unit

docker compose exec -T php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --bootstrap=/var/www/source/bootstrap.php
```

---

## Acceptance Criteria Tests

```php
public function testAcceptanceCriteria(): void
{
    // 1. Only ONE webhook log table exists
    $tables = $this->connection->fetchFirstColumn("SHOW TABLES LIKE 'osc_payment_webhook%'");
    $this->assertCount(1, $tables);
    $this->assertEquals('osc_payment_webhooklogs', $tables[0]);

    // 2. Only ONE customer table exists
    $tables = $this->connection->fetchFirstColumn("SHOW TABLES LIKE 'osc_%customer%'");
    $this->assertCount(1, $tables);
    $this->assertEquals('osc_payment_customer', $tables[0]);

    // 3. Payment details table removed
    $tables = $this->connection->fetchFirstColumn("SHOW TABLES LIKE 'osc_stripe_payment_details'");
    $this->assertEmpty($tables);

    // 4. All webhook processing works
    // (covered by WebhookController tests)

    // 5. All customer mapping works
    // (covered by StripeCustomerService tests)

    // 6. All tests pass
    // (this test itself proves it!)
}
```

---

**Created:** 2025-12-02
**Last Updated:** 2025-12-02
