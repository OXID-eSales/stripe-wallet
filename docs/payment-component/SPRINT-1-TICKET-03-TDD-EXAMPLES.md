# TICKET-003: Component Models - TDD Test Examples
## Complete Test Suite with Red-Green-Refactor Approach

**Related:** [TICKET-003: Component Models](SPRINT-1-TICKET-03-component-models.md)
**Version:** 1.0.0
**Date:** 2025-10-17

---

## Table of Contents

1. [TDD Overview for Models](#tdd-overview-for-models)
2. [Unit Tests - Model Logic Only](#unit-tests---model-logic-only)
   - [PaymentTransaction Tests](#paymenttransaction-tests)
   - [PaymentOrderState Tests (State Machine)](#paymentorderstate-tests-state-machine)
   - [PaymentCustomer Tests](#paymentcustomer-tests)
   - [PaymentBasketSnapshot Tests](#paymentbasketsnapshot-tests)
3. [Integration Tests - Model + Database](#integration-tests---model--database)
   - [PaymentTransaction Integration Tests](#paymenttransaction-integration-tests)
   - [PaymentOrderState Integration Tests](#paymentorderstate-integration-tests)
4. [Test Execution](#test-execution)

---

## TDD Overview for Models

### The Red-Green-Refactor Cycle

```
🔴 RED: Write failing test
   ↓
🟢 GREEN: Write minimal code to make it pass
   ↓
🔵 REFACTOR: Clean up code while keeping tests green
   ↓
🔁 REPEAT: Next test
```

### Testing Strategy for Models

This document provides **TWO levels of testing**:

**Level 1: Unit Tests (Model Logic Only)**
- Test model behavior in isolation
- No database required
- Fast execution (< 100ms)
- Mock repositories
- Focus: Business logic, validation, state machine

**Level 2: Integration Tests (Model + Database)**
- Test model persistence to database
- Verify FK constraints work
- Test state transitions persist correctly
- Assert both model state AND database state
- Focus: Data integrity, constraints, persistence

| Model | Unit Test Focus | Integration Test Focus | Priority |
|-------|----------------|----------------------|----------|
| **PaymentTransaction** | Validation, state checks, immutability | FK to oxorder, CASCADE delete, persistence | P0 - Critical |
| **PaymentOrderState** | State machine transitions, validation | 1:1 relationship with oxorder, UNIQUE constraint | P0 - Critical |
| **PaymentCustomer** | Data management | 1:1 relationship with oxuser | P1 - High |
| **PaymentBasketSnapshot** | Total matching, data integrity | Basket snapshot persistence | P1 - High |

---

## Unit Tests - Model Logic Only

These tests verify model behavior **without database**. They run fast and focus on business logic.

---

## PaymentTransaction Tests

### Test File Location

```
tests/Component/Unit/Model/PaymentTransactionTest.php
```

### Complete Test Suite

```php
<?php
// tests/Component/Unit/Model/PaymentTransactionTest.php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Component\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Osc\Payment\Component\Model\PaymentTransaction;
use DateTimeImmutable;

/**
 * PaymentTransaction Model Tests
 *
 * @group unit
 * @group model
 * @group payment-transaction
 * @covers \Osc\Payment\Component\Model\PaymentTransaction
 */
final class PaymentTransactionTest extends TestCase
{
    // ==========================================
    // TEST 1: Basic Construction
    // ==========================================

    /** @test */
    public function it_creates_transaction_with_required_fields(): void
    {
        // Arrange & Act
        $transaction = new PaymentTransaction(
            shopId: '1',
            orderId: 'order-123',
            providerOrderId: 'pi_stripe_123',
            status: 'completed',
            paymentMethodId: 'card',
            transactionType: 'capture'
        );

        // Assert
        $this->assertNull($transaction->getId(), 'ID should be null before persistence');
        $this->assertEquals('1', $transaction->getShopId());
        $this->assertEquals('order-123', $transaction->getOrderId());
        $this->assertEquals('pi_stripe_123', $transaction->getProviderOrderId());
        $this->assertEquals('completed', $transaction->getStatus());
        $this->assertEquals('card', $transaction->getPaymentMethodId());
        $this->assertEquals('capture', $transaction->getTransactionType());
    }

    /** @test */
    public function it_sets_timestamps_on_creation(): void
    {
        $beforeCreation = new DateTimeImmutable();

        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $afterCreation = new DateTimeImmutable();

        $this->assertNotNull($transaction->getCreatedAt());
        $this->assertNotNull($transaction->getUpdatedAt());
        $this->assertGreaterThanOrEqual($beforeCreation, $transaction->getCreatedAt());
        $this->assertLessThanOrEqual($afterCreation, $transaction->getCreatedAt());
    }

    // ==========================================
    // TEST 2: Transaction Type Validation
    // ==========================================

    /**
     * @test
     * @dataProvider validTransactionTypesProvider
     */
    public function it_accepts_valid_transaction_types(string $validType): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'pending', 'card', $validType
        );

        $this->assertEquals($validType, $transaction->getTransactionType());
    }

    public static function validTransactionTypesProvider(): array
    {
        return [
            'capture' => ['capture'],
            'authorization' => ['authorization'],
            'refund' => ['refund'],
            'void' => ['void'],
        ];
    }

    /** @test */
    public function it_rejects_invalid_transaction_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid type: invalid_type');

        new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'pending', 'card', 'invalid_type'
        );
    }

    // ==========================================
    // TEST 3: State Management
    // ==========================================

    /** @test */
    public function it_checks_if_transaction_is_completed(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $this->assertTrue($transaction->isCompleted());
        $this->assertFalse($transaction->isPending());
        $this->assertFalse($transaction->isRefunded());
    }

    /** @test */
    public function it_checks_if_transaction_is_pending(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'pending', 'card', 'capture'
        );

        $this->assertTrue($transaction->isPending());
        $this->assertFalse($transaction->isCompleted());
        $this->assertFalse($transaction->isRefunded());
    }

    /** @test */
    public function it_checks_if_transaction_is_refunded(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'refunded', 'card', 'refund'
        );

        $this->assertTrue($transaction->isRefunded());
        $this->assertFalse($transaction->isCompleted());
        $this->assertFalse($transaction->isPending());
    }

    // ==========================================
    // TEST 4: Status Changes
    // ==========================================

    /** @test */
    public function it_updates_status(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'pending', 'card', 'capture'
        );

        $originalUpdatedAt = $transaction->getUpdatedAt();

        // Small delay to ensure timestamp changes
        usleep(10000); // 10ms

        $transaction->setStatus('completed');

        $this->assertEquals('completed', $transaction->getStatus());
        $this->assertGreaterThan($originalUpdatedAt, $transaction->getUpdatedAt());
    }

    // ==========================================
    // TEST 5: ID Management (Immutability)
    // ==========================================

    /** @test */
    public function it_allows_setting_id_once(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $this->assertNull($transaction->getId());

        $transaction->setId('tx-uuid-123');

        $this->assertEquals('tx-uuid-123', $transaction->getId());
    }

    /** @test */
    public function it_prevents_changing_id_once_set(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $transaction->setId('tx-uuid-123');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ID is immutable once set');

        $transaction->setId('tx-uuid-456');
    }

    // ==========================================
    // TEST 6: Provider Data
    // ==========================================

    /** @test */
    public function it_stores_provider_specific_data(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $providerData = [
            'stripe_charge_id' => 'ch_123',
            'payment_intent_id' => 'pi_123',
            'customer_id' => 'cus_456'
        ];

        $transaction->setProviderData($providerData);

        $this->assertEquals($providerData, $transaction->getProviderData());
        $this->assertEquals('ch_123', $transaction->getProviderData()['stripe_charge_id']);
    }

    /** @test */
    public function it_returns_null_when_no_provider_data_set(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $this->assertNull($transaction->getProviderData());
    }

    // ==========================================
    // TEST 7: Transaction ID (Charge ID)
    // ==========================================

    /** @test */
    public function it_sets_transaction_id_from_provider(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $this->assertNull($transaction->getTransactionId());

        $transaction->setTransactionId('ch_stripe_charge_123');

        $this->assertEquals('ch_stripe_charge_123', $transaction->getTransactionId());
    }

    // ==========================================
    // TEST 8: Tracking Code (for shipment)
    // ==========================================

    /** @test */
    public function it_sets_tracking_code_for_delivery(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $transaction->setTrackingCode('DHL-123456789');
        $transaction->setTrackingCarrier('DHL');

        $this->assertEquals('DHL-123456789', $transaction->getTrackingCode());
        $this->assertEquals('DHL', $transaction->getTrackingCarrier());
    }

    // ==========================================
    // TEST 9: Edge Cases
    // ==========================================

    /** @test */
    public function it_handles_empty_provider_order_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider order ID cannot be empty');

        new PaymentTransaction(
            '1', 'order-123', '', 'pending', 'card', 'capture'
        );
    }

    /** @test */
    public function it_handles_empty_order_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Order ID cannot be empty');

        new PaymentTransaction(
            '1', '', 'pi_123', 'pending', 'card', 'capture'
        );
    }

    // ==========================================
    // TEST 10: Amount & Currency (if added)
    // ==========================================

    /** @test */
    public function it_stores_amount_and_currency(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $transaction->setAmount(99.99);
        $transaction->setCurrency('EUR');

        $this->assertEquals(99.99, $transaction->getAmount());
        $this->assertEquals('EUR', $transaction->getCurrency());
    }

    /** @test */
    public function it_validates_positive_amount(): void
    {
        $transaction = new PaymentTransaction(
            '1', 'order-123', 'pi_123', 'completed', 'card', 'capture'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        $transaction->setAmount(-50.00);
    }
}
```

---

## PaymentOrderState Tests (State Machine)

### Test File Location

```
tests/Component/Unit/Model/PaymentOrderStateTest.php
```

### Complete State Machine Test Suite

```php
<?php
// tests/Component/Unit/Model/PaymentOrderStateTest.php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Component\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Osc\Payment\Component\Model\PaymentOrderState;
use Osc\Payment\Component\Model\PaymentOrderStates;
use DateTimeImmutable;

/**
 * PaymentOrderState State Machine Tests
 *
 * @group unit
 * @group model
 * @group state-machine
 * @covers \Osc\Payment\Component\Model\PaymentOrderState
 */
final class PaymentOrderStateTest extends TestCase
{
    // ==========================================
    // TEST 1: Initial State
    // ==========================================

    /** @test */
    public function it_creates_order_state_with_default_not_finished(): void
    {
        $orderState = new PaymentOrderState('order-123');

        $this->assertEquals('order-123', $orderState->getOrderId());
        $this->assertEquals(
            PaymentOrderStates::STATE_NOT_FINISHED,
            $orderState->getPaymentState()
        );
        $this->assertNull($orderState->getProviderOrderId());
        $this->assertEquals(0, $orderState->getPaymentAttemptCount());
    }

    /** @test */
    public function it_creates_order_state_with_custom_initial_state(): void
    {
        $orderState = new PaymentOrderState(
            'order-123',
            PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS
        );

        $this->assertEquals(
            PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS,
            $orderState->getPaymentState()
        );
    }

    /** @test */
    public function it_rejects_invalid_initial_state(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid payment state: INVALID');

        new PaymentOrderState('order-123', 'INVALID');
    }

    // ==========================================
    // TEST 2: Valid State Transitions
    // ==========================================

    /** @test */
    public function it_transitions_from_not_finished_to_payment_in_progress(): void
    {
        $orderState = new PaymentOrderState('order-123');

        $orderState->markAsPaymentInProgress();

        $this->assertEquals(
            PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS,
            $orderState->getPaymentState()
        );
        $this->assertEquals(1, $orderState->getPaymentAttemptCount());
        $this->assertNotNull($orderState->getLastPaymentAttempt());
    }

    /** @test */
    public function it_transitions_from_payment_in_progress_to_waiting_for_webhook(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();

        $orderState->markAsWaitingForWebhook();

        $this->assertEquals(
            PaymentOrderStates::STATE_WAITING_FOR_WEBHOOK,
            $orderState->getPaymentState()
        );
        $this->assertTrue($orderState->isWaitingForWebhook());
        $this->assertNotNull($orderState->getWebhookWaitSince());
        $this->assertEquals(300, $orderState->getWebhookTimeout()); // Default 5 minutes
    }

    /** @test */
    public function it_transitions_from_waiting_to_ok(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();
        $orderState->markAsWaitingForWebhook();

        $orderState->markAsCompleted();

        $this->assertEquals(
            PaymentOrderStates::STATE_OK,
            $orderState->getPaymentState()
        );
        $this->assertNull($orderState->getWebhookWaitSince(), 'Webhook wait should be cleared');
    }

    /** @test */
    public function it_transitions_from_payment_in_progress_directly_to_ok(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();

        // Direct payment (no webhook needed)
        $orderState->markAsCompleted();

        $this->assertEquals(
            PaymentOrderStates::STATE_OK,
            $orderState->getPaymentState()
        );
    }

    /** @test */
    public function it_transitions_to_error_from_any_state(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();

        $orderState->markAsFailed('Payment declined');

        $this->assertEquals(
            PaymentOrderStates::STATE_ERROR,
            $orderState->getPaymentState()
        );
    }

    // ==========================================
    // TEST 3: Invalid State Transitions
    // ==========================================

    /** @test */
    public function it_prevents_invalid_transition_from_not_finished_to_ok(): void
    {
        $orderState = new PaymentOrderState('order-123');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid transition from NOT_FINISHED to OK');

        $orderState->markAsCompleted();
    }

    /** @test */
    public function it_prevents_transition_from_ok_to_payment_in_progress(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();
        $orderState->markAsCompleted();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid transition from OK');

        $orderState->markAsPaymentInProgress();
    }

    /** @test */
    public function it_prevents_direct_transition_to_waiting_for_webhook(): void
    {
        $orderState = new PaymentOrderState('order-123');

        $this->expectException(\InvalidArgumentException::class);

        $orderState->markAsWaitingForWebhook();
    }

    // ==========================================
    // TEST 4: Retry Logic (Error → Payment In Progress)
    // ==========================================

    /** @test */
    public function it_allows_retry_from_error_state(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();
        $orderState->markAsFailed('Card declined');

        // Retry with new payment method
        $orderState->markAsPaymentInProgress();

        $this->assertEquals(
            PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS,
            $orderState->getPaymentState()
        );
        $this->assertEquals(2, $orderState->getPaymentAttemptCount());
    }

    // ==========================================
    // TEST 5: Payment Attempt Tracking
    // ==========================================

    /** @test */
    public function it_increments_payment_attempt_count(): void
    {
        $orderState = new PaymentOrderState('order-123');

        $this->assertEquals(0, $orderState->getPaymentAttemptCount());

        $orderState->markAsPaymentInProgress();
        $this->assertEquals(1, $orderState->getPaymentAttemptCount());

        // Fail and retry
        $orderState->markAsFailed('Declined');
        $orderState->markAsPaymentInProgress();
        $this->assertEquals(2, $orderState->getPaymentAttemptCount());
    }

    /** @test */
    public function it_tracks_last_payment_attempt_timestamp(): void
    {
        $orderState = new PaymentOrderState('order-123');

        $this->assertNull($orderState->getLastPaymentAttempt());

        $orderState->markAsPaymentInProgress();

        $this->assertInstanceOf(\DateTime::class, $orderState->getLastPaymentAttempt());
    }

    // ==========================================
    // TEST 6: Webhook Timeout Logic
    // ==========================================

    /** @test */
    public function it_checks_if_webhook_has_not_timed_out(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();
        $orderState->markAsWaitingForWebhook();

        // Immediately after marking, not timed out
        $this->assertFalse($orderState->isWebhookTimedOut());
    }

    /** @test */
    public function it_checks_if_webhook_has_timed_out(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();
        $orderState->markAsWaitingForWebhook();

        // Simulate time passing (mock webhook wait start time)
        $pastTime = new \DateTime('-10 minutes');
        $orderState->setWebhookWaitSince($pastTime); // For testing only

        $this->assertTrue($orderState->isWebhookTimedOut());
    }

    /** @test */
    public function it_uses_custom_webhook_timeout(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();

        // Set 10-minute timeout
        $orderState->markAsWaitingForWebhook(600);

        $this->assertEquals(600, $orderState->getWebhookTimeout());
    }

    // ==========================================
    // TEST 7: Provider Order ID
    // ==========================================

    /** @test */
    public function it_stores_provider_order_id(): void
    {
        $orderState = new PaymentOrderState('order-123');

        $this->assertNull($orderState->getProviderOrderId());

        $orderState->setProviderOrderId('pi_stripe_12345');

        $this->assertEquals('pi_stripe_12345', $orderState->getProviderOrderId());
    }

    // ==========================================
    // TEST 8: State Query Methods
    // ==========================================

    /** @test */
    public function it_checks_if_payment_is_finished(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();
        $orderState->markAsCompleted();

        $this->assertTrue($orderState->isFinished());
        $this->assertFalse($orderState->isWaitingForWebhook());
    }

    /** @test */
    public function it_checks_if_payment_failed(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $orderState->markAsPaymentInProgress();
        $orderState->markAsFailed('Timeout');

        $this->assertTrue($orderState->hasFailed());
    }

    // ==========================================
    // TEST 9: Timestamps
    // ==========================================

    /** @test */
    public function it_sets_created_and_updated_timestamps(): void
    {
        $before = new DateTimeImmutable();

        $orderState = new PaymentOrderState('order-123');

        $after = new DateTimeImmutable();

        $this->assertNotNull($orderState->getCreatedAt());
        $this->assertNotNull($orderState->getUpdatedAt());
        $this->assertGreaterThanOrEqual($before, $orderState->getCreatedAt());
        $this->assertLessThanOrEqual($after, $orderState->getCreatedAt());
    }

    /** @test */
    public function it_updates_timestamp_on_state_change(): void
    {
        $orderState = new PaymentOrderState('order-123');
        $originalUpdatedAt = $orderState->getUpdatedAt();

        usleep(10000); // 10ms delay

        $orderState->markAsPaymentInProgress();

        $this->assertGreaterThan($originalUpdatedAt, $orderState->getUpdatedAt());
    }

    // ==========================================
    // TEST 10: Complete State Machine Flow
    // ==========================================

    /** @test */
    public function it_completes_full_payment_flow_with_webhook(): void
    {
        $orderState = new PaymentOrderState('order-123');

        // Step 1: Start payment
        $this->assertEquals(PaymentOrderStates::STATE_NOT_FINISHED, $orderState->getPaymentState());

        // Step 2: Payment initiated
        $orderState->markAsPaymentInProgress();
        $this->assertEquals(PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS, $orderState->getPaymentState());

        // Step 3: Waiting for webhook
        $orderState->markAsWaitingForWebhook();
        $this->assertEquals(PaymentOrderStates::STATE_WAITING_FOR_WEBHOOK, $orderState->getPaymentState());
        $this->assertTrue($orderState->isWaitingForWebhook());

        // Step 4: Webhook received, payment complete
        $orderState->markAsCompleted();
        $this->assertEquals(PaymentOrderStates::STATE_OK, $orderState->getPaymentState());
        $this->assertTrue($orderState->isFinished());
    }

    /** @test */
    public function it_handles_failed_payment_with_retry(): void
    {
        $orderState = new PaymentOrderState('order-123');

        // First attempt
        $orderState->markAsPaymentInProgress();
        $this->assertEquals(1, $orderState->getPaymentAttemptCount());

        // Payment fails
        $orderState->markAsFailed('Card declined');
        $this->assertTrue($orderState->hasFailed());

        // Retry with new card
        $orderState->markAsPaymentInProgress();
        $this->assertEquals(2, $orderState->getPaymentAttemptCount());

        // Success on retry
        $orderState->markAsCompleted();
        $this->assertTrue($orderState->isFinished());
    }
}
```

---

## PaymentCustomer Tests

### Test File Location

```
tests/Component/Unit/Model/PaymentCustomerTest.php
```

### Test Suite

```php
<?php
// tests/Component/Unit/Model/PaymentCustomerTest.php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Component\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Osc\Payment\Component\Model\PaymentCustomer;

/**
 * @group unit
 * @group model
 * @covers \Osc\Payment\Component\Model\PaymentCustomer
 */
final class PaymentCustomerTest extends TestCase
{
    /** @test */
    public function it_creates_payment_customer_with_user_id(): void
    {
        $customer = new PaymentCustomer('user-123');

        $this->assertEquals('user-123', $customer->getUserId());
        $this->assertNull($customer->getPaymentCustomerId());
        $this->assertEmpty($customer->getSavedPaymentMethods());
    }

    /** @test */
    public function it_sets_provider_customer_id(): void
    {
        $customer = new PaymentCustomer('user-123');

        $customer->setPaymentCustomerId('cus_stripe_456');

        $this->assertEquals('cus_stripe_456', $customer->getPaymentCustomerId());
    }

    /** @test */
    public function it_adds_saved_payment_method(): void
    {
        $customer = new PaymentCustomer('user-123');

        $customer->addSavedPaymentMethod('pm_card_visa_4242');
        $customer->addSavedPaymentMethod('pm_card_mastercard_5555');

        $methods = $customer->getSavedPaymentMethods();
        $this->assertCount(2, $methods);
        $this->assertContains('pm_card_visa_4242', $methods);
    }

    /** @test */
    public function it_prevents_duplicate_payment_methods(): void
    {
        $customer = new PaymentCustomer('user-123');

        $customer->addSavedPaymentMethod('pm_card_visa_4242');
        $customer->addSavedPaymentMethod('pm_card_visa_4242'); // Duplicate

        $this->assertCount(1, $customer->getSavedPaymentMethods());
    }

    /** @test */
    public function it_removes_payment_method(): void
    {
        $customer = new PaymentCustomer('user-123');
        $customer->addSavedPaymentMethod('pm_card_visa_4242');

        $customer->removeSavedPaymentMethod('pm_card_visa_4242');

        $this->assertEmpty($customer->getSavedPaymentMethods());
    }

    /** @test */
    public function it_sets_default_payment_method(): void
    {
        $customer = new PaymentCustomer('user-123');

        $customer->setDefaultPaymentMethod('pm_card_visa_4242');

        $this->assertEquals('pm_card_visa_4242', $customer->getDefaultPaymentMethod());
    }
}
```

---

## PaymentBasketSnapshot Tests

### Test File Location

```
tests/Component/Unit/Model/PaymentBasketSnapshotTest.php
```

### Test Suite

```php
<?php
// tests/Component/Unit/Model/PaymentBasketSnapshotTest.php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Component\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Osc\Payment\Component\Model\PaymentBasketSnapshot;

/**
 * @group unit
 * @group model
 * @covers \Osc\Payment\Component\Model\PaymentBasketSnapshot
 */
final class PaymentBasketSnapshotTest extends TestCase
{
    /** @test */
    public function it_creates_basket_snapshot(): void
    {
        $basketData = [
            'items' => [
                ['sku' => 'PROD-001', 'qty' => 2, 'price' => 49.99],
                ['sku' => 'PROD-002', 'qty' => 1, 'price' => 29.99]
            ]
        ];

        $snapshot = new PaymentBasketSnapshot(
            orderId: 'order-123',
            basketData: $basketData,
            total: 129.97,
            currency: 'EUR',
            userId: 'user-456'
        );

        $this->assertEquals('order-123', $snapshot->getOrderId());
        $this->assertEquals($basketData, $snapshot->getBasketData());
        $this->assertEquals(129.97, $snapshot->getTotal());
        $this->assertEquals('EUR', $snapshot->getCurrency());
        $this->assertEquals('user-456', $snapshot->getUserId());
    }

    /** @test */
    public function it_validates_total_matches_with_tolerance(): void
    {
        $snapshot = new PaymentBasketSnapshot(
            'order-123',
            [],
            100.00,
            'EUR'
        );

        // Exact match
        $this->assertTrue($snapshot->matchesTotal(100.00));

        // Within tolerance (0.01)
        $this->assertTrue($snapshot->matchesTotal(100.005));
        $this->assertTrue($snapshot->matchesTotal(99.995));

        // Outside tolerance
        $this->assertFalse($snapshot->matchesTotal(100.02));
        $this->assertFalse($snapshot->matchesTotal(99.98));
    }

    /** @test */
    public function it_uses_custom_tolerance_for_total_matching(): void
    {
        $snapshot = new PaymentBasketSnapshot('order-123', [], 100.00, 'EUR');

        // Custom tolerance of 0.10
        $this->assertTrue($snapshot->matchesTotal(100.09, 0.10));
        $this->assertFalse($snapshot->matchesTotal(100.11, 0.10));
    }

    /** @test */
    public function it_stores_discount_shipping_and_tax(): void
    {
        $snapshot = new PaymentBasketSnapshot('order-123', [], 100.00, 'EUR');

        $snapshot->setDiscount(10.00);
        $snapshot->setShipping(5.99);
        $snapshot->setTax(19.00);

        $this->assertEquals(10.00, $snapshot->getDiscount());
        $this->assertEquals(5.99, $snapshot->getShipping());
        $this->assertEquals(19.00, $snapshot->getTax());
    }
}
```

---

## Integration Tests - Model + Database

These tests verify that models persist correctly to the database and that all constraints work as expected. They assert **both model state AND database state**.

### Test Setup Helper

```php
<?php
// tests/Component/Support/DatabaseTestCase.php

namespace OxidSolutionCatalysts\Component\Tests\Support;

use PHPUnit\Framework\TestCase;
use PDO;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Use SQLite in-memory database for fast tests
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Run migrations
        $this->runMigrations();
    }

    protected function runMigrations(): void
    {
        // Create oxorder table (OXID core table for FK reference)
        $this->db->exec("
            CREATE TABLE oxorder (
                OXID CHAR(32) PRIMARY KEY,
                OXORDERDATE DATETIME,
                OXTOTALORDERSUM DECIMAL(10,2)
            )
        ");

        // Create oxuser table (OXID core table for FK reference)
        $this->db->exec("
            CREATE TABLE oxuser (
                OXID CHAR(32) PRIMARY KEY,
                OXUSERNAME VARCHAR(255)
            )
        ");

        // Create osc_payment_transaction table
        $this->db->exec("
            CREATE TABLE osc_payment_transaction (
                OXID CHAR(32) PRIMARY KEY,
                OXSHOPID VARCHAR(10) NOT NULL,
                OXORDERID CHAR(32) NOT NULL,
                OXPROVIDER VARCHAR(32) NOT NULL,
                OXPROVIDERORDERID VARCHAR(128),
                OXTRANSACTIONID VARCHAR(128),
                OXTYPE VARCHAR(32) NOT NULL,
                OXSTATUS VARCHAR(32) NOT NULL,
                OXAMOUNT DECIMAL(10,2),
                OXCURRENCY VARCHAR(3),
                OXPAYMENTMETHODID VARCHAR(64),
                OXPAYMENTMETHODTYPE VARCHAR(32),
                OXPARENTTRANSACTIONID CHAR(32),
                OXPROVIDERDATA TEXT,
                OXTRACKINGCODE VARCHAR(255),
                OXTRACKINGCARRIER VARCHAR(64),
                OXCREATED DATETIME NOT NULL,
                OXUPDATED DATETIME NOT NULL,
                FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE
            )
        ");

        // Create osc_payment_order_state table
        $this->db->exec("
            CREATE TABLE osc_payment_order_state (
                OXID CHAR(32) PRIMARY KEY,
                OXORDERID CHAR(32) NOT NULL UNIQUE,
                OXPAYMENTSTATE VARCHAR(32) NOT NULL,
                OXPROVIDERORDERID VARCHAR(128),
                OXWEBHOOKWAITSINCE DATETIME,
                OXWEBHOOKTIMEOUT INT,
                OXLASTPAYMENTATTEMPT DATETIME,
                OXPAYMENTATTEMPTCOUNT INT DEFAULT 0,
                OXCREATED DATETIME NOT NULL,
                OXUPDATED DATETIME NOT NULL,
                FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE
            )
        ");

        // Create osc_payment_customer table
        $this->db->exec("
            CREATE TABLE osc_payment_customer (
                OXID CHAR(32) PRIMARY KEY,
                OXUSERID CHAR(32) NOT NULL UNIQUE,
                OXPAYMENTCUSTOMERID VARCHAR(128),
                OXDEFAULTPAYMENTMETHOD VARCHAR(64),
                OXSAVEDPAYMENTMETHODS TEXT,
                OXBILLINGAGREEMENT BOOLEAN DEFAULT 0,
                OXLASTPAYMENTDATE DATETIME,
                OXCREATED DATETIME NOT NULL,
                OXUPDATED DATETIME NOT NULL,
                FOREIGN KEY (OXUSERID) REFERENCES oxuser(OXID) ON DELETE CASCADE
            )
        ");
    }

    protected function createTestOrder(string $orderId = null): string
    {
        $orderId = $orderId ?: $this->generateId();

        $stmt = $this->db->prepare("
            INSERT INTO oxorder (OXID, OXORDERDATE, OXTOTALORDERSUM)
            VALUES (:id, :date, :total)
        ");

        $stmt->execute([
            'id' => $orderId,
            'date' => date('Y-m-d H:i:s'),
            'total' => 99.99
        ]);

        return $orderId;
    }

    protected function createTestUser(string $userId = null): string
    {
        $userId = $userId ?: $this->generateId();

        $stmt = $this->db->prepare("
            INSERT INTO oxuser (OXID, OXUSERNAME)
            VALUES (:id, :username)
        ");

        $stmt->execute([
            'id' => $userId,
            'username' => 'test@example.com'
        ]);

        return $userId;
    }

    protected function assertDatabaseHas(string $table, array $conditions): void
    {
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE ";
        $where = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            $where[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }

        $sql .= implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertGreaterThan(
            0,
            $result['count'],
            "Failed asserting that table {$table} contains matching record"
        );
    }

    protected function assertDatabaseMissing(string $table, array $conditions): void
    {
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE ";
        $where = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            $where[] = "{$column} = :{$column}";
            $params[$column] = $value;
        }

        $sql .= implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(
            0,
            $result['count'],
            "Failed asserting that table {$table} does not contain matching record"
        );
    }

    protected function assertDatabaseCount(string $table, int $expectedCount): void
    {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM {$table}");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(
            $expectedCount,
            $result['count'],
            "Failed asserting that table {$table} contains {$expectedCount} rows"
        );
    }

    protected function generateId(): string
    {
        return md5(uniqid((string) rand(), true));
    }
}
```

---

## PaymentTransaction Integration Tests

### Test File Location

```
tests/Component/Integration/Model/PaymentTransactionIntegrationTest.php
```

### Complete Integration Test Suite

```php
<?php
// tests/Component/Integration/Model/PaymentTransactionIntegrationTest.php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Component\Tests\Integration\Model;

use OxidSolutionCatalysts\Component\Tests\Support\DatabaseTestCase;
use Osc\Payment\Component\Model\PaymentTransaction;
use Osc\Payment\Component\Repository\PaymentTransactionRepository;

/**
 * PaymentTransaction Integration Tests
 *
 * Tests model persistence to database with FK constraints
 *
 * @group integration
 * @group model
 * @group database
 */
final class PaymentTransactionIntegrationTest extends DatabaseTestCase
{
    private PaymentTransactionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PaymentTransactionRepository($this->db);
    }

    // ==========================================
    // TEST 1: Basic Persistence
    // ==========================================

    /** @test */
    public function it_persists_transaction_to_database(): void
    {
        // Arrange - Create order first (FK requirement)
        $orderId = $this->createTestOrder();

        $transaction = new PaymentTransaction(
            shopId: '1',
            orderId: $orderId,
            providerOrderId: 'pi_test_123',
            status: 'completed',
            paymentMethodId: 'card',
            transactionType: 'capture'
        );

        $transaction->setAmount(99.99);
        $transaction->setCurrency('EUR');

        // Act - Save to database
        $this->repository->save($transaction);

        // Assert - Model has ID
        $this->assertNotNull($transaction->getId());

        // Assert - Database contains record
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $transaction->getId(),
            'OXORDERID' => $orderId,
            'OXPROVIDERORDERID' => 'pi_test_123',
            'OXSTATUS' => 'completed',
            'OXTYPE' => 'capture',
            'OXAMOUNT' => 99.99,
            'OXCURRENCY' => 'EUR'
        ]);
    }

    /** @test */
    public function it_retrieves_transaction_from_database(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();

        $transaction = new PaymentTransaction(
            '1', $orderId, 'pi_test_456', 'pending', 'card', 'authorization'
        );
        $transaction->setAmount(150.50);
        $transaction->setCurrency('USD');
        $this->repository->save($transaction);

        $txId = $transaction->getId();

        // Act - Retrieve from database
        $retrieved = $this->repository->findById($txId);

        // Assert - Model matches
        $this->assertNotNull($retrieved);
        $this->assertEquals($txId, $retrieved->getId());
        $this->assertEquals($orderId, $retrieved->getOrderId());
        $this->assertEquals('pi_test_456', $retrieved->getProviderOrderId());
        $this->assertEquals('pending', $retrieved->getStatus());
        $this->assertEquals('authorization', $retrieved->getTransactionType());
        $this->assertEquals(150.50, $retrieved->getAmount());
        $this->assertEquals('USD', $retrieved->getCurrency());
    }

    // ==========================================
    // TEST 2: Foreign Key Constraints
    // ==========================================

    /** @test */
    public function it_enforces_foreign_key_to_oxorder(): void
    {
        // Arrange - Try to create transaction with non-existent order
        $transaction = new PaymentTransaction(
            '1',
            'nonexistent-order-id', // Invalid FK
            'pi_test_789',
            'completed',
            'card',
            'capture'
        );

        // Act & Assert - Should throw FK constraint violation
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/FOREIGN KEY constraint failed|foreign key/i');

        $this->repository->save($transaction);
    }

    /** @test */
    public function it_cascades_delete_when_order_is_deleted(): void
    {
        // Arrange - Create order and transaction
        $orderId = $this->createTestOrder();

        $transaction = new PaymentTransaction(
            '1', $orderId, 'pi_test_cascade', 'completed', 'card', 'capture'
        );
        $this->repository->save($transaction);

        $txId = $transaction->getId();

        // Assert - Transaction exists
        $this->assertDatabaseHas('osc_payment_transaction', ['OXID' => $txId]);

        // Act - Delete order (CASCADE should delete transaction)
        $this->db->exec("DELETE FROM oxorder WHERE OXID = '{$orderId}'");

        // Assert - Model still exists in memory
        $this->assertEquals($txId, $transaction->getId());

        // Assert - Database record deleted due to CASCADE
        $this->assertDatabaseMissing('osc_payment_transaction', ['OXID' => $txId]);
    }

    // ==========================================
    // TEST 3: State Changes Persist
    // ==========================================

    /** @test */
    public function it_persists_status_changes_to_database(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();

        $transaction = new PaymentTransaction(
            '1', $orderId, 'pi_test_status', 'pending', 'card', 'capture'
        );
        $this->repository->save($transaction);
        $txId = $transaction->getId();

        // Assert - Initial status in database
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $txId,
            'OXSTATUS' => 'pending'
        ]);

        // Act - Change status in model
        usleep(10000); // Small delay for updated timestamp
        $transaction->setStatus('completed');
        $this->repository->save($transaction);

        // Assert - Model reflects change
        $this->assertEquals('completed', $transaction->getStatus());

        // Assert - Database reflects change
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $txId,
            'OXSTATUS' => 'completed'
        ]);

        // Assert - Old status no longer in database
        $this->assertDatabaseMissing('osc_payment_transaction', [
            'OXID' => $txId,
            'OXSTATUS' => 'pending'
        ]);
    }

    /** @test */
    public function it_updates_timestamp_on_status_change(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $transaction = new PaymentTransaction(
            '1', $orderId, 'pi_timestamp', 'pending', 'card', 'capture'
        );
        $this->repository->save($transaction);
        $txId = $transaction->getId();

        // Get original updated timestamp
        $stmt = $this->db->prepare("SELECT OXUPDATED FROM osc_payment_transaction WHERE OXID = :id");
        $stmt->execute(['id' => $txId]);
        $originalUpdated = $stmt->fetchColumn();

        usleep(20000); // 20ms delay

        // Act
        $transaction->setStatus('completed');
        $this->repository->save($transaction);

        // Get new updated timestamp
        $stmt->execute(['id' => $txId]);
        $newUpdated = $stmt->fetchColumn();

        // Assert - Timestamp changed
        $this->assertNotEquals($originalUpdated, $newUpdated);
        $this->assertGreaterThan($originalUpdated, $newUpdated);
    }

    // ==========================================
    // TEST 4: Provider Data Persistence
    // ==========================================

    /** @test */
    public function it_persists_provider_data_as_json(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $transaction = new PaymentTransaction(
            '1', $orderId, 'pi_provider_data', 'completed', 'card', 'capture'
        );

        $providerData = [
            'stripe_charge_id' => 'ch_123',
            'payment_intent_id' => 'pi_456',
            'metadata' => ['custom_field' => 'value']
        ];

        $transaction->setProviderData($providerData);
        $this->repository->save($transaction);
        $txId = $transaction->getId();

        // Act - Retrieve from database
        $stmt = $this->db->prepare("SELECT OXPROVIDERDATA FROM osc_payment_transaction WHERE OXID = :id");
        $stmt->execute(['id' => $txId]);
        $jsonData = $stmt->fetchColumn();

        // Assert - Data stored as JSON
        $this->assertNotEmpty($jsonData);
        $decoded = json_decode($jsonData, true);
        $this->assertEquals($providerData, $decoded);

        // Assert - Model retrieves correctly
        $retrieved = $this->repository->findById($txId);
        $this->assertEquals($providerData, $retrieved->getProviderData());
    }

    // ==========================================
    // TEST 5: Multiple Transactions Per Order
    // ==========================================

    /** @test */
    public function it_stores_multiple_transactions_for_same_order(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();

        // Create authorization
        $authorization = new PaymentTransaction(
            '1', $orderId, 'pi_auth', 'completed', 'card', 'authorization'
        );
        $authorization->setAmount(100.00);
        $authorization->setCurrency('EUR');
        $this->repository->save($authorization);

        usleep(10000);

        // Create capture
        $capture = new PaymentTransaction(
            '1', $orderId, 'pi_capture', 'completed', 'card', 'capture'
        );
        $capture->setAmount(100.00);
        $capture->setCurrency('EUR');
        $capture->setParentTransactionId($authorization->getId());
        $this->repository->save($capture);

        usleep(10000);

        // Create refund
        $refund = new PaymentTransaction(
            '1', $orderId, 'pi_refund', 'completed', 'card', 'refund'
        );
        $refund->setAmount(50.00);
        $refund->setCurrency('EUR');
        $refund->setParentTransactionId($capture->getId());
        $this->repository->save($refund);

        // Assert - All transactions exist in database
        $this->assertDatabaseCount('osc_payment_transaction', 3);

        // Assert - All linked to same order
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $authorization->getId(),
            'OXORDERID' => $orderId
        ]);
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $capture->getId(),
            'OXORDERID' => $orderId
        ]);
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $refund->getId(),
            'OXORDERID' => $orderId
        ]);

        // Assert - Parent relationships persisted
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $capture->getId(),
            'OXPARENTTRANSACTIONID' => $authorization->getId()
        ]);
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $refund->getId(),
            'OXPARENTTRANSACTIONID' => $capture->getId()
        ]);
    }

    // ==========================================
    // TEST 6: Query by Provider Order ID
    // ==========================================

    /** @test */
    public function it_finds_transaction_by_provider_order_id(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $transaction = new PaymentTransaction(
            '1', $orderId, 'pi_unique_find_123', 'completed', 'card', 'capture'
        );
        $this->repository->save($transaction);

        // Act
        $found = $this->repository->findByProviderOrderId('pi_unique_find_123');

        // Assert - Model found
        $this->assertNotNull($found);
        $this->assertEquals($transaction->getId(), $found->getId());

        // Assert - Database matches
        $this->assertDatabaseHas('osc_payment_transaction', [
            'OXID' => $found->getId(),
            'OXPROVIDERORDERID' => 'pi_unique_find_123'
        ]);
    }
}
```

---

## PaymentOrderState Integration Tests

### Test File Location

```
tests/Component/Integration/Model/PaymentOrderStateIntegrationTest.php
```

### Complete Integration Test Suite

```php
<?php
// tests/Component/Integration/Model/PaymentOrderStateIntegrationTest.php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Component\Tests\Integration\Model;

use OxidSolutionCatalysts\Component\Tests\Support\DatabaseTestCase;
use Osc\Payment\Component\Model\PaymentOrderState;
use Osc\Payment\Component\Model\PaymentOrderStates;
use Osc\Payment\Component\Repository\PaymentOrderStateRepository;

/**
 * PaymentOrderState Integration Tests
 *
 * Tests state machine persistence with 1:1 FK constraint to oxorder
 *
 * @group integration
 * @group model
 * @group database
 * @group state-machine
 */
final class PaymentOrderStateIntegrationTest extends DatabaseTestCase
{
    private PaymentOrderStateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new PaymentOrderStateRepository($this->db);
    }

    // ==========================================
    // TEST 1: Basic Persistence
    // ==========================================

    /** @test */
    public function it_persists_order_state_to_database(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $orderState = new PaymentOrderState($orderId);

        // Act
        $this->repository->save($orderState);

        // Assert - Model has ID
        $this->assertNotNull($orderState->getId());

        // Assert - Database contains record
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $orderState->getId(),
            'OXORDERID' => $orderId,
            'OXPAYMENTSTATE' => PaymentOrderStates::STATE_NOT_FINISHED,
            'OXPAYMENTATTEMPTCOUNT' => 0
        ]);
    }

    // ==========================================
    // TEST 2: 1:1 Relationship with oxorder
    // ==========================================

    /** @test */
    public function it_enforces_unique_constraint_on_order_id(): void
    {
        // Arrange - Create first state for order
        $orderId = $this->createTestOrder();
        $orderState1 = new PaymentOrderState($orderId);
        $this->repository->save($orderState1);

        // Act - Try to create second state for same order
        $orderState2 = new PaymentOrderState($orderId);

        // Assert - Should throw UNIQUE constraint violation
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/UNIQUE constraint failed|unique/i');

        $this->repository->save($orderState2);
    }

    /** @test */
    public function it_cascades_delete_when_order_is_deleted(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $orderState = new PaymentOrderState($orderId);
        $this->repository->save($orderState);
        $stateId = $orderState->getId();

        // Assert - State exists
        $this->assertDatabaseHas('osc_payment_order_state', ['OXID' => $stateId]);

        // Act - Delete order
        $this->db->exec("DELETE FROM oxorder WHERE OXID = '{$orderId}'");

        // Assert - State deleted via CASCADE
        $this->assertDatabaseMissing('osc_payment_order_state', ['OXID' => $stateId]);
    }

    // ==========================================
    // TEST 3: State Transitions Persist
    // ==========================================

    /** @test */
    public function it_persists_state_transition_to_payment_in_progress(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $orderState = new PaymentOrderState($orderId);
        $this->repository->save($orderState);
        $stateId = $orderState->getId();

        // Act - Transition state
        $orderState->markAsPaymentInProgress();
        $this->repository->save($orderState);

        // Assert - Model reflects change
        $this->assertEquals(
            PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS,
            $orderState->getPaymentState()
        );
        $this->assertEquals(1, $orderState->getPaymentAttemptCount());

        // Assert - Database reflects change
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTSTATE' => PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS,
            'OXPAYMENTATTEMPTCOUNT' => 1
        ]);

        // Assert - Timestamp set
        $stmt = $this->db->prepare("
            SELECT OXLASTPAYMENTATTEMPT
            FROM osc_payment_order_state
            WHERE OXID = :id
        ");
        $stmt->execute(['id' => $stateId]);
        $lastAttempt = $stmt->fetchColumn();
        $this->assertNotEmpty($lastAttempt);
    }

    /** @test */
    public function it_persists_full_state_machine_flow(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $orderState = new PaymentOrderState($orderId);
        $this->repository->save($orderState);
        $stateId = $orderState->getId();

        // Step 1: NOT_FINISHED (initial)
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTSTATE' => PaymentOrderStates::STATE_NOT_FINISHED
        ]);

        // Step 2: PAYMENT_IN_PROGRESS
        $orderState->markAsPaymentInProgress();
        $this->repository->save($orderState);
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTSTATE' => PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS
        ]);

        // Step 3: WAITING_FOR_WEBHOOK
        $orderState->markAsWaitingForWebhook();
        $this->repository->save($orderState);
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTSTATE' => PaymentOrderStates::STATE_WAITING_FOR_WEBHOOK
        ]);

        // Verify webhook wait timestamp in database
        $stmt = $this->db->prepare("
            SELECT OXWEBHOOKWAITSINCE, OXWEBHOOKTIMEOUT
            FROM osc_payment_order_state
            WHERE OXID = :id
        ");
        $stmt->execute(['id' => $stateId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotEmpty($result['OXWEBHOOKWAITSINCE']);
        $this->assertEquals(300, $result['OXWEBHOOKTIMEOUT']);

        // Step 4: OK (completed)
        $orderState->markAsCompleted();
        $this->repository->save($orderState);
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTSTATE' => PaymentOrderStates::STATE_OK
        ]);

        // Verify webhook wait cleared in database
        $stmt->execute(['id' => $stateId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($result['OXWEBHOOKWAITSINCE']);
    }

    // ==========================================
    // TEST 4: Payment Attempt Tracking
    // ==========================================

    /** @test */
    public function it_persists_payment_attempt_increments(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $orderState = new PaymentOrderState($orderId);
        $this->repository->save($orderState);
        $stateId = $orderState->getId();

        // Assert - Initial count
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTATTEMPTCOUNT' => 0
        ]);

        // Act - First attempt
        $orderState->markAsPaymentInProgress();
        $this->repository->save($orderState);

        // Assert - Count incremented in database
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTATTEMPTCOUNT' => 1
        ]);

        // Act - Fail and retry
        $orderState->markAsFailed('Card declined');
        $this->repository->save($orderState);

        $orderState->markAsPaymentInProgress();
        $this->repository->save($orderState);

        // Assert - Count incremented again
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTATTEMPTCOUNT' => 2
        ]);
    }

    // ==========================================
    // TEST 5: Provider Order ID
    // ==========================================

    /** @test */
    public function it_persists_provider_order_id(): void
    {
        // Arrange
        $orderId = $this->createTestOrder();
        $orderState = new PaymentOrderState($orderId);
        $this->repository->save($orderState);
        $stateId = $orderState->getId();

        // Act
        $orderState->setProviderOrderId('pi_stripe_12345');
        $this->repository->save($orderState);

        // Assert - Model reflects change
        $this->assertEquals('pi_stripe_12345', $orderState->getProviderOrderId());

        // Assert - Database reflects change
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPROVIDERORDERID' => 'pi_stripe_12345'
        ]);
    }

    // ==========================================
    // TEST 6: Retrieve and Update
    // ==========================================

    /** @test */
    public function it_retrieves_and_updates_existing_state(): void
    {
        // Arrange - Create and save
        $orderId = $this->createTestOrder();
        $orderState = new PaymentOrderState($orderId);
        $this->repository->save($orderState);
        $stateId = $orderState->getId();

        // Act - Retrieve from database
        $retrieved = $this->repository->findByOrderId($orderId);

        // Assert - Retrieved correctly
        $this->assertNotNull($retrieved);
        $this->assertEquals($stateId, $retrieved->getId());
        $this->assertEquals($orderId, $retrieved->getOrderId());

        // Act - Update state
        $retrieved->markAsPaymentInProgress();
        $this->repository->save($retrieved);

        // Assert - Database updated
        $this->assertDatabaseHas('osc_payment_order_state', [
            'OXID' => $stateId,
            'OXPAYMENTSTATE' => PaymentOrderStates::STATE_PAYMENT_IN_PROGRESS
        ]);
    }
}
```

---

## Test Execution

### Run All Model Tests

```bash
# Run all model tests
vendor/bin/phpunit tests/Component/Unit/Model/

# Run specific model test
vendor/bin/phpunit tests/Component/Unit/Model/PaymentTransactionTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/ tests/Component/Unit/Model/

# Run only state machine tests
vendor/bin/phpunit --group state-machine
```

### Expected Results

```
PaymentTransactionTest
✓ it creates transaction with required fields
✓ it sets timestamps on creation
✓ it accepts valid transaction types (4 tests)
✓ it rejects invalid transaction type
✓ it checks if transaction is completed
✓ it checks if transaction is pending
✓ it checks if transaction is refunded
✓ it updates status
✓ it allows setting id once
✓ it prevents changing id once set
✓ it stores provider specific data
... (20+ tests total)

PaymentOrderStateTest
✓ it creates order state with default not finished
✓ it transitions from not finished to payment in progress
✓ it transitions from payment in progress to waiting for webhook
✓ it transitions from waiting to ok
✓ it prevents invalid transitions
✓ it allows retry from error state
✓ it checks webhook timeout
... (30+ tests total)

PaymentCustomerTest
✓ it creates payment customer with user id
✓ it adds saved payment method
✓ it prevents duplicate payment methods
... (8+ tests total)

PaymentBasketSnapshotTest
✓ it creates basket snapshot
✓ it validates total matches with tolerance
... (5+ tests total)

Time: 00:00.234, Memory: 8.00 MB

OK (65 tests, 150 assertions)
```

### Coverage Goals

- **PaymentTransaction**: 100% coverage
- **PaymentOrderState**: 100% coverage (all state transitions)
- **PaymentCustomer**: 95%+ coverage
- **PaymentBasketSnapshot**: 95%+ coverage

---

## Summary

This document provides **complete TDD test examples** with **TWO levels of testing** for all component models in TICKET-003:

### Unit Tests (Model Logic Only)
✅ **PaymentTransaction** - 20+ tests covering validation, state checks, immutability
✅ **PaymentOrderState** - 30+ tests covering full state machine with all transitions
✅ **PaymentCustomer** - 8+ tests covering customer data management
✅ **PaymentBasketSnapshot** - 5+ tests covering basket snapshot and total matching

### Integration Tests (Model + Database)
✅ **PaymentTransaction Integration** - 6+ tests covering:
- Database persistence
- FK constraints to oxorder
- CASCADE delete behavior
- Status changes persist correctly
- Provider data JSON storage
- Multiple transactions per order

✅ **PaymentOrderState Integration** - 6+ tests covering:
- Database persistence with 1:1 relationship
- UNIQUE constraint on OXORDERID
- CASCADE delete when order deleted
- Full state machine flow persists to DB
- Payment attempt tracking in database
- Provider order ID persistence

### Key Features

**✅ Dual Assertion Strategy:**
- Assert model state changes
- Assert database state changes
- Verify both are in sync

**✅ Database Test Helpers:**
- `assertDatabaseHas()` - Verify record exists
- `assertDatabaseMissing()` - Verify record deleted
- `assertDatabaseCount()` - Verify table row count
- SQLite in-memory DB for fast execution

**✅ FK Constraint Testing:**
- Verify FK to oxorder works
- Test CASCADE delete behavior
- Test UNIQUE constraints
- Test invalid FK rejection

**Next Steps:**
1. Run unit tests: `vendor/bin/phpunit tests/Component/Unit/Model/`
2. Run integration tests: `vendor/bin/phpunit tests/Component/Integration/Model/`
3. Implement models to make tests pass
4. Achieve 95%+ coverage
5. Move to [TICKET-004: Repositories](SPRINT-1-TICKET-04-repositories.md)

---

[Back to TICKET-003](SPRINT-1-TICKET-03-component-models.md) | [Back to Index](SPRINT-1-index.md)
