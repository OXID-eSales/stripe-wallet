# Sprint 2: OXORDER Field Persistence Tests

**Priority:** HIGH
**Estimated Scope:** Integration Tests
**Status:** PLANNED

---

## Objective

Create tests to verify all necessary fields in the `oxorder` table are correctly populated during the checkout flow. Tests should verify end-to-end field population including timestamps and folder assignments.

---

## TDD Approach

```
┌─────────────────────────────────────────────────────────────────┐
│  TDD CYCLE                                                      │
│                                                                 │
│  1. RED   → Write test expecting specific field value           │
│  2. GREEN → Modify handler/service to set the field             │
│  3. REFACTOR → Ensure reusability, no duplication               │
│                                                                 │
│  Focus on critical paths first!                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Phase 1: Field Analysis

### 1.1 Critical OXORDER Fields

| Field | Type | Description | When Set | By |
|-------|------|-------------|----------|-----|
| `OXTRANSID` | VARCHAR(64) | Stripe PaymentIntent ID | Order commit | StripeOrderCreationHandler |
| `OXTRANSSTATUS` | VARCHAR(30) | Transaction status | Order create + Webhook | Multiple handlers |
| `OXPAID` | DATETIME | Payment completion timestamp | Webhook capture | WebhookProcessingService |
| `OXFOLDER` | VARCHAR(32) | Order folder for admin | State change | State handlers |
| `OXORDERNR` | INT | Order number | Order finalize | OXID core |

### 1.2 Stripe-Specific Fields (from Events.php)

| Field | Type | Description | When Set |
|-------|------|-------------|----------|
| `STRIPEEXTERNALTRANSID` | VARCHAR(64) | External Stripe ID | Order commit |
| `STRIPEMODE` | VARCHAR(10) | sandbox/live | Order commit |
| `STRIPEDELCOSTREFUNDED` | DECIMAL | Delivery cost refunded | Refund webhook |

### 1.3 State Transition Matrix

| Scenario | OXTRANSID | OXTRANSSTATUS | OXPAID | OXFOLDER |
|----------|-----------|---------------|--------|----------|
| Order created (contract committed) | `pi_xxx` | `NOT_FINISHED` | `0000-00-00 00:00:00` | `ORDERFOLDER_NEW` |
| Payment authorized | `pi_xxx` | `OK` | `0000-00-00 00:00:00` | `ORDERFOLDER_NEW` |
| Payment captured | `pi_xxx` | `OK` | `{timestamp}` | `ORDERFOLDER_NEW` |
| Payment failed | `pi_xxx` | `ERROR` | `0000-00-00 00:00:00` | `ORDERFOLDER_PROBLEMS` |
| Partially refunded | `pi_xxx` | `OK` | `{timestamp}` | `ORDERFOLDER_NEW` |
| Fully refunded | `pi_xxx` | `OK` | `{timestamp}` | `ORDERFOLDER_NEW` |

---

## Phase 2: Test Implementation

### 2.1 Test File Structure

```
tests/Integration/Stripe/Order/
└── OxorderFieldPersistenceTest.php       # NEW - All OXORDER field tests
```

### 2.2 OxorderFieldPersistenceTest.php

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Stripe\Order;

use Doctrine\DBAL\Connection;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Contract\ContractCondition;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContract;
use OxidSolutionCatalysts\Payments\Component\Repository\DoctrineContractRepository;

/**
 * Tests that OXORDER fields are correctly populated during checkout.
 *
 * @group integration
 * @group order-fields
 * @group oxorder
 */
final class OxorderFieldPersistenceTest extends IntegrationTestCase
{
    private const TEST_PREFIX = 'ox_test_';
    private const SHOP_ID = 1;

    private Connection $connection;
    private DoctrineContractRepository $contractRepository;
    private string $testRunId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRunId = date('His') . '_' . substr(uniqid(), -4);

        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();

        $this->contractRepository = new DoctrineContractRepository($this->connection);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    // =========================================================================
    // OXTRANSID Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransidIsSetToPaymentIntentIdOnOrderCreation(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_transid_' . $this->testRunId;
        $contractId = $this->createContractId('transid');

        // Create contract with provider info
        $contract = $this->createContract($contractId, $userId);
        $contract->setProvider('stripe', $paymentIntentId, null);
        $contract->addCondition(ContractCondition::paymentAuthorized());
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED, []);
        $this->contractRepository->save($contract);

        // Act: Create order via OXID mechanism
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR', $paymentIntentId);
        $contract->commitToOrder($orderId);
        $this->contractRepository->save($contract);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            $paymentIntentId,
            $dbOrder['OXTRANSID'],
            'OXTRANSID should contain the PaymentIntent ID'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransidIsNotOverwrittenOnSubsequentUpdates(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_nooverwrite_' . $this->testRunId;
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR', $paymentIntentId);

        // Act: Try to update order (simulating webhook)
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'OK',
        ], ['OXID' => $orderId]);

        // Assert: OXTRANSID should remain unchanged
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            $paymentIntentId,
            $dbOrder['OXTRANSID'],
            'OXTRANSID should not be overwritten'
        );
    }

    // =========================================================================
    // OXTRANSSTATUS Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransstatusIsNotFinishedOnOrderCreation(): void
    {
        // Arrange & Act
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            'NOT_FINISHED',
            $dbOrder['OXTRANSSTATUS'],
            'OXTRANSSTATUS should be NOT_FINISHED on order creation'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransstatusIsOkAfterPaymentSucceeds(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate payment_intent.succeeded webhook
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'OK',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals('OK', $dbOrder['OXTRANSSTATUS']);
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxtransstatusIsErrorAfterPaymentFails(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate payment_intent.payment_failed webhook
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'ERROR',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXTRANSSTATUS FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals('ERROR', $dbOrder['OXTRANSSTATUS']);
    }

    // =========================================================================
    // OXPAID Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     */
    public function oxpaidIsZeroOnOrderCreation(): void
    {
        // Arrange & Act
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXPAID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            '0000-00-00 00:00:00',
            $dbOrder['OXPAID'],
            'OXPAID should be zero datetime on order creation'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxpaidIsSetOnPaymentCapture(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');
        $beforeCapture = new \DateTimeImmutable();

        // Act: Simulate charge.captured webhook
        $captureTime = date('Y-m-d H:i:s');
        $this->connection->update('oxorder', [
            'OXPAID' => $captureTime,
            'OXTRANSSTATUS' => 'OK',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXPAID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $paidDate = new \DateTimeImmutable($dbOrder['OXPAID']);

        $this->assertGreaterThanOrEqual(
            $beforeCapture,
            $paidDate,
            'OXPAID should be set to capture timestamp'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxpaidRemainsZeroOnPaymentFailure(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate payment_intent.payment_failed webhook
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'ERROR',
            'OXFOLDER' => 'ORDERFOLDER_PROBLEMS',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXPAID FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            '0000-00-00 00:00:00',
            $dbOrder['OXPAID'],
            'OXPAID should remain zero on payment failure'
        );
    }

    // =========================================================================
    // OXFOLDER Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     */
    public function oxfolderIsNewOnOrderCreation(): void
    {
        // Arrange & Act
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXFOLDER FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            'ORDERFOLDER_NEW',
            $dbOrder['OXFOLDER'],
            'OXFOLDER should be ORDERFOLDER_NEW on order creation'
        );
    }

    /**
     * @test
     * @group tdd-red
     */
    public function oxfolderIsProblemsOnPaymentFailure(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $orderId = $this->createTestOrder($userId, 100.00, 'EUR');

        // Act: Simulate payment failure
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'ERROR',
            'OXFOLDER' => 'ORDERFOLDER_PROBLEMS',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT OXFOLDER FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals(
            'ORDERFOLDER_PROBLEMS',
            $dbOrder['OXFOLDER'],
            'OXFOLDER should be ORDERFOLDER_PROBLEMS on payment failure'
        );
    }

    // =========================================================================
    // Combined Flow Tests
    // =========================================================================

    /**
     * @test
     * @group tdd-red
     * @group complete-flow
     */
    public function completePaymentFlowSetsAllFieldsCorrectly(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_complete_' . $this->testRunId;
        $orderId = $this->createTestOrder($userId, 299.99, 'EUR', $paymentIntentId);

        // Initial state assertions
        $initialOrder = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );
        $this->assertEquals('NOT_FINISHED', $initialOrder['OXTRANSSTATUS']);
        $this->assertEquals('0000-00-00 00:00:00', $initialOrder['OXPAID']);
        $this->assertEquals('ORDERFOLDER_NEW', $initialOrder['OXFOLDER']);

        // Act: Simulate successful payment + capture (webhook flow)
        $captureTime = date('Y-m-d H:i:s');
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'OK',
            'OXPAID' => $captureTime,
        ], ['OXID' => $orderId]);

        // Final state assertions
        $finalOrder = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals($paymentIntentId, $finalOrder['OXTRANSID'], 'OXTRANSID mismatch');
        $this->assertEquals('OK', $finalOrder['OXTRANSSTATUS'], 'OXTRANSSTATUS mismatch');
        $this->assertNotEquals('0000-00-00 00:00:00', $finalOrder['OXPAID'], 'OXPAID should be set');
        $this->assertEquals('ORDERFOLDER_NEW', $finalOrder['OXFOLDER'], 'OXFOLDER mismatch');
    }

    /**
     * @test
     * @group tdd-red
     * @group complete-flow
     */
    public function failedPaymentFlowSetsFieldsCorrectly(): void
    {
        // Arrange
        $userId = $this->createTestUser();
        $paymentIntentId = 'pi_failed_' . $this->testRunId;
        $orderId = $this->createTestOrder($userId, 99.99, 'EUR', $paymentIntentId);

        // Act: Simulate failed payment (webhook flow)
        $this->connection->update('oxorder', [
            'OXTRANSSTATUS' => 'ERROR',
            'OXFOLDER' => 'ORDERFOLDER_PROBLEMS',
        ], ['OXID' => $orderId]);

        // Assert
        $dbOrder = $this->connection->fetchAssociative(
            'SELECT * FROM oxorder WHERE OXID = :id',
            ['id' => $orderId]
        );

        $this->assertEquals($paymentIntentId, $dbOrder['OXTRANSID'], 'OXTRANSID should be set');
        $this->assertEquals('ERROR', $dbOrder['OXTRANSSTATUS'], 'OXTRANSSTATUS should be ERROR');
        $this->assertEquals('0000-00-00 00:00:00', $dbOrder['OXPAID'], 'OXPAID should remain zero');
        $this->assertEquals('ORDERFOLDER_PROBLEMS', $dbOrder['OXFOLDER'], 'OXFOLDER should be PROBLEMS');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createContractId(string $suffix): string
    {
        return substr(self::TEST_PREFIX . $this->testRunId . '_' . $suffix, 0, 32);
    }

    private function createContract(string $contractId, string $userId): PaymentContract
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.0,
            'totalVat' => 16.0,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        return new PaymentContract(
            shopId: self::SHOP_ID,
            userId: $userId,
            basketSnapshot: $basketSnapshot,
            id: $contractId
        );
    }

    private function createTestUser(): string
    {
        $userId = substr(self::TEST_PREFIX . 'user_' . $this->testRunId, 0, 32);

        $this->connection->insert('oxuser', [
            'OXID' => $userId,
            'OXACTIVE' => 1,
            'OXRIGHTS' => 'user',
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERNAME' => 'ox_test_' . $this->testRunId . '@example.com',
            'OXPASSWORD' => '',
            'OXFNAME' => 'Order',
            'OXLNAME' => 'Test',
            'OXSTREET' => 'Test Street',
            'OXSTREETNR' => '1',
            'OXCITY' => 'Test City',
            'OXCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXZIP' => '12345',
            'OXSAL' => 'MR',
            'OXCREATE' => date('Y-m-d H:i:s'),
            'OXREGISTER' => date('Y-m-d H:i:s'),
        ]);

        return $userId;
    }

    private function createTestOrder(
        string $userId,
        float $total,
        string $currency,
        string $transId = ''
    ): string {
        $orderId = substr(self::TEST_PREFIX . 'ord_' . $this->testRunId, 0, 32);

        $this->connection->insert('oxorder', [
            'OXID' => $orderId,
            'OXSHOPID' => self::SHOP_ID,
            'OXUSERID' => $userId,
            'OXORDERDATE' => date('Y-m-d H:i:s'),
            'OXORDERNR' => random_int(100000, 999999),
            'OXTRANSID' => $transId,
            'OXTRANSSTATUS' => 'NOT_FINISHED',
            'OXBILLEMAIL' => 'ox_test@example.com',
            'OXBILLFNAME' => 'Order',
            'OXBILLLNAME' => 'Test',
            'OXBILLSTREET' => 'Test Street',
            'OXBILLSTREETNR' => '1',
            'OXBILLCITY' => 'Test City',
            'OXBILLCOUNTRYID' => 'a7c40f631fc920687.20179984',
            'OXBILLZIP' => '12345',
            'OXBILLSAL' => 'MR',
            'OXPAYMENTTYPE' => 'stripe_card',
            'OXTOTALNETSUM' => $total / 1.19,
            'OXTOTALBRUTSUM' => $total,
            'OXTOTALORDERSUM' => $total,
            'OXCURRENCY' => $currency,
            'OXCURRATE' => 1,
            'OXFOLDER' => 'ORDERFOLDER_NEW',
            'OXPAID' => '0000-00-00 00:00:00',
        ]);

        return $orderId;
    }

    private function cleanupTestData(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM oe_payments_contract WHERE OXID LIKE ?",
            [self::TEST_PREFIX . '%']
        );
        $this->connection->executeStatement(
            "DELETE FROM oxorder WHERE OXID LIKE ?",
            [self::TEST_PREFIX . '%']
        );
        $this->connection->executeStatement(
            "DELETE FROM oxuser WHERE OXID LIKE ?",
            [self::TEST_PREFIX . '%']
        );
    }
}
```

---

## Phase 3: Verification with Existing Flow

### 3.1 Existing Services to Reuse

| Service | File | Responsibility |
|---------|------|----------------|
| `StripeOrderCreationHandler` | `src/Stripe/EventSystem/Handler/` | Sets OXTRANSID on order creation |
| `WebhookProcessingService` | `src/Stripe/Service/` | Updates OXTRANSSTATUS, OXPAID on webhooks |
| `OxidShopOrderService` | `src/Stripe/Adapter/` | OXID order operations |

### 3.2 Integration Points

```
┌─────────────────────────────────────────────────────────────────┐
│  CHECKOUT FLOW                                                  │
│                                                                 │
│  ContractReadyToCommit → StripeOrderCreationHandler            │
│                              │                                  │
│                              ▼                                  │
│                         Order::finalizeOrder()                  │
│                              │                                  │
│                              ▼                                  │
│                         OXTRANSID = pi_xxx                      │
│                         OXTRANSSTATUS = NOT_FINISHED            │
│                         OXFOLDER = ORDERFOLDER_NEW              │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  WEBHOOK FLOW                                                   │
│                                                                 │
│  payment_intent.succeeded → WebhookProcessingService           │
│                                  │                              │
│                                  ▼                              │
│                             OXTRANSSTATUS = OK                  │
│                                                                 │
│  charge.captured → WebhookProcessingService                    │
│                         │                                       │
│                         ▼                                       │
│                    OXPAID = {timestamp}                         │
│                                                                 │
│  payment_intent.payment_failed → WebhookProcessingService      │
│                                       │                         │
│                                       ▼                         │
│                                  OXTRANSSTATUS = ERROR          │
│                                  OXFOLDER = ORDERFOLDER_PROBLEMS│
└─────────────────────────────────────────────────────────────────┘
```

---

## Phase 4: Test Execution Commands

```bash
# Run all OXORDER field tests
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --testsuite Integration \
    --group order-fields \
    --bootstrap=/var/www/source/bootstrap.php

# Run specific test
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    /var/www/extensions/stripe/tests/Integration/Stripe/Order/OxorderFieldPersistenceTest.php \
    --bootstrap=/var/www/source/bootstrap.php

# Run complete flow tests only
docker compose exec php vendor/bin/phpunit \
    -c /var/www/extensions/stripe/tests/phpunit.xml \
    --group complete-flow \
    --bootstrap=/var/www/source/bootstrap.php
```

---

## Definition of Done

- [ ] OXTRANSID tests pass (3 tests)
- [ ] OXTRANSSTATUS tests pass (3 tests)
- [ ] OXPAID tests pass (3 tests)
- [ ] OXFOLDER tests pass (2 tests)
- [ ] Combined flow tests pass (2 tests)
- [ ] No code duplication with existing handlers
- [ ] Pre-commit-check.sh passes
- [ ] Move `todo/sprint-2-oxorder-field-tests.md` → `done/sprint-2-oxorder-field-tests.md`
- [ ] Create `done/sprint-2-oxorder-field-tests-REPORT.md`
- [ ] status.md updated
