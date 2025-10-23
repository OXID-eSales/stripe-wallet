# Database Implementation Guide - Sprint 1 (Part 2: Smart Contract Models)
## TDD Approach for Domain Models (Smart Contract Pattern)

**Version:** 4.0.0
**Date:** 2025-10-22
**Target:** OXID eShop 7.4+
**Philosophy:** Test-First Development with Clean Architecture + Smart Contracts

**Related Docs:**
- [Part 1: Migrations](IMPLEMENTATION-DB-SPRINT-1-PART-1-MIGRATIONS.md) - Migration strategy and PHP migrations
- [Part 3: Repositories](IMPLEMENTATION-DB-SPRINT-1-PART-3-REPOSITORIES.md) - Repository pattern and testing
- [02-02-database-and-models-smart-contracts.md](02-02-database-and-models-smart-contracts.md) - Complete smart contract schema

---

## Table of Contents

1. [Overview](#overview)
2. [TDD Workflow for Models](#tdd-workflow-for-models)
3. [Smart Contract Domain Models](#smart-contract-domain-models)
4. [Value Objects](#value-objects)
5. [Domain Events](#domain-events)

---

## Overview

This part focuses on implementing the **Smart Contract Pattern** domain models using TDD. These models implement the contract-first architecture where payment contracts are created BEFORE orders.

### Models to Implement

**Smart Contract Models (NEW):**
1. **PaymentContract** - Aggregate root managing payment lifecycle
2. **ContractCondition** - Entity representing fulfillment conditions
3. **BasketSnapshot** - Value object for immutable basket data
4. **ContractState** - Value object for type-safe state management

**Supporting Models (from Part 1):**
- PaymentTransaction - Already covered in original implementation guide
- PaymentOrderState - Already covered in original implementation guide
- AuthorizationDetails, RefundDetails, etc. - Detail tables

---

## TDD Workflow for Models

**Red-Green-Refactor Cycle:**
```
1. ✅ RED: Write test FIRST (test fails)
2. ✅ GREEN: Implement minimal code to pass
3. ✅ REFACTOR: Clean up without breaking tests
4. ✅ REPEAT: Next feature
```

---

## Smart Contract Domain Models

### Model 1: PaymentContract (Aggregate Root)

#### Step 1: Write Tests FIRST

```php
<?php
// tests/Component/Unit/Model/PaymentContractTest.php

declare(strict_types=1);

namespace Tests\Component\Unit\Model;

use PHPUnit\Framework\TestCase;
use Osc\Payment\Component\Model\PaymentContract;
use Osc\Payment\Component\Entity\ContractCondition;
use Osc\Payment\Component\ValueObject\BasketSnapshot;

/**
 * Unit tests for PaymentContract (Aggregate Root)
 *
 * @group unit
 * @group model
 * @group smart-contract
 */
class PaymentContractTest extends TestCase
{
    /** @test */
    public function it_creates_contract_in_draft_state(): void
    {
        // Arrange
        $basketSnapshot = $this->createBasketSnapshot();

        // Act
        $contract = new PaymentContract(
            shopId: '1',
            userId: 'user-123',
            basketSnapshot: $basketSnapshot,
            state: PaymentContract::STATE_DRAFT
        );

        // Assert
        $this->assertEquals('1', $contract->getShopId());
        $this->assertEquals('user-123', $contract->getUserId());
        $this->assertEquals(PaymentContract::STATE_DRAFT, $contract->getState());
        $this->assertNull($contract->getOrderId()); // NULL until committed!
        $this->assertInstanceOf(BasketSnapshot::class, $contract->getBasketSnapshot());
        $this->assertNotNull($contract->getExpiresAt());
    }

    /** @test */
    public function it_adds_condition_to_draft_contract(): void
    {
        $contract = $this->createDraftContract();
        $condition = new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING
        );

        $contract->addCondition($condition);

        $this->assertCount(1, $contract->getConditions());
        $this->assertEquals(
            ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            $contract->getConditions()[0]->getType()
        );
    }

    /** @test */
    public function it_prevents_adding_conditions_to_non_draft_contract(): void
    {
        $contract = $this->createDraftContract();
        $contract->transitionToPending();

        $condition = new ContractCondition(
            type: ContractCondition::TYPE_FRAUD_CHECK,
            status: ContractCondition::STATUS_PENDING
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot add conditions to contract in state: pending');

        $contract->addCondition($condition);
    }

    /** @test */
    public function it_transitions_from_draft_to_pending(): void
    {
        $contract = $this->createDraftContract();
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING
        ));

        $contract->transitionToPending();

        $this->assertEquals(PaymentContract::STATE_PENDING, $contract->getState());
    }

    /** @test */
    public function it_prevents_transition_to_pending_without_conditions(): void
    {
        $contract = $this->createDraftContract();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot transition to PENDING without conditions');

        $contract->transitionToPending();
    }

    /** @test */
    public function it_fulfills_individual_condition(): void
    {
        $contract = $this->createContractWithPendingConditions();

        $contract->fulfillCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            data: ['authorizationId' => 'auth_123', 'amount' => 99.99]
        );

        $conditions = $contract->getConditions();
        $paymentCondition = array_filter(
            $conditions,
            fn($c) => $c->getType() === ContractCondition::TYPE_PAYMENT_AUTHORIZED
        );

        $this->assertTrue(reset($paymentCondition)->isFulfilled());
    }

    /** @test */
    public function it_checks_if_all_conditions_are_fulfilled(): void
    {
        $contract = $this->createContractWithPendingConditions();

        $this->assertFalse($contract->areAllConditionsFulfilled());

        // Fulfill all conditions
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK);

        $this->assertTrue($contract->areAllConditionsFulfilled());
    }

    /** @test */
    public function it_automatically_transitions_to_ready_when_all_conditions_met(): void
    {
        $contract = $this->createContractWithPendingConditions();

        // State is PENDING
        $this->assertEquals(PaymentContract::STATE_PENDING, $contract->getState());

        // Fulfill all conditions
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK);

        // Auto-transition to READY_TO_COMMIT
        $this->assertEquals(PaymentContract::STATE_READY_TO_COMMIT, $contract->getState());
    }

    /** @test */
    public function it_commits_contract_to_order(): void
    {
        $contract = $this->createReadyToCommitContract();

        $contract->commitToOrder('order-456');

        $this->assertEquals(PaymentContract::STATE_COMMITTED, $contract->getState());
        $this->assertEquals('order-456', $contract->getOrderId());
        $this->assertNotNull($contract->getCommittedAt());
    }

    /** @test */
    public function it_prevents_commit_if_not_ready(): void
    {
        $contract = $this->createContractWithPendingConditions();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot commit contract in state: pending');

        $contract->commitToOrder('order-456');
    }

    /** @test */
    public function it_prevents_commit_with_unfulfilled_conditions(): void
    {
        $contract = $this->createContractWithPendingConditions();
        // Force state to READY_TO_COMMIT without fulfilling conditions (edge case test)
        $reflection = new \ReflectionClass($contract);
        $property = $reflection->getProperty('state');
        $property->setAccessible(true);
        $property->setValue($contract, PaymentContract::STATE_READY_TO_COMMIT);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot commit contract with unfulfilled conditions');

        $contract->commitToOrder('order-456');
    }

    /** @test */
    public function it_fulfills_contract_after_payment_captured(): void
    {
        $contract = $this->createCommittedContract();

        $contract->fulfill();

        $this->assertEquals(PaymentContract::STATE_FULFILLED, $contract->getState());
        $this->assertNotNull($contract->getFulfilledAt());
    }

    /** @test */
    public function it_prevents_fulfillment_without_order(): void
    {
        $contract = $this->createReadyToCommitContract();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot fulfill contract without order ID');

        $contract->fulfill();
    }

    /** @test */
    public function it_cancels_contract_with_reason(): void
    {
        $contract = $this->createContractWithPendingConditions();

        $contract->cancel('User cancelled checkout');

        $this->assertEquals(PaymentContract::STATE_CANCELLED, $contract->getState());
        $this->assertEquals('User cancelled checkout', $contract->getStateReason());
    }

    /** @test */
    public function it_prevents_cancellation_of_fulfilled_contract(): void
    {
        $contract = $this->createFulfilledContract();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot cancel fulfilled contract');

        $contract->cancel('Too late');
    }

    /** @test */
    public function it_expires_contract(): void
    {
        $contract = $this->createContractWithPendingConditions();

        $contract->expire();

        $this->assertEquals(PaymentContract::STATE_EXPIRED, $contract->getState());
        $this->assertStringContainsString('expired', $contract->getStateReason());
    }

    /** @test */
    public function it_checks_if_contract_is_expired_by_time(): void
    {
        $contract = $this->createDraftContract();

        // Not expired initially
        $this->assertFalse($contract->isExpired());

        // Set expiration in past
        $reflection = new \ReflectionClass($contract);
        $property = $reflection->getProperty('expiresAt');
        $property->setAccessible(true);
        $property->setValue($contract, new \DateTime('-1 hour'));

        $this->assertTrue($contract->isExpired());
    }

    /** @test */
    public function it_sets_provider_information(): void
    {
        $contract = $this->createDraftContract();

        $contract->setProvider('stripe', 'pi_stripe_123');

        $this->assertEquals('stripe', $contract->getProvider());
        $this->assertEquals('pi_stripe_123', $contract->getProviderOrderId());
    }

    /** @test */
    public function it_records_domain_events(): void
    {
        $contract = $this->createDraftContract();
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING
        ));

        $contract->transitionToPending();

        $events = $contract->getRecordedEvents();
        $this->assertNotEmpty($events);
        $this->assertInstanceOf(
            \Osc\Payment\Component\Event\ContractTransitionedToPendingEvent::class,
            $events[0]
        );
    }

    /** @test */
    public function it_clears_recorded_events_after_dispatch(): void
    {
        $contract = $this->createDraftContract();
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING
        ));
        $contract->transitionToPending();

        $this->assertNotEmpty($contract->getRecordedEvents());

        $contract->clearRecordedEvents();

        $this->assertEmpty($contract->getRecordedEvents());
    }

    // Helper methods
    private function createBasketSnapshot(): BasketSnapshot
    {
        return new BasketSnapshot(
            items: [
                ['id' => 'prod-1', 'title' => 'Product 1', 'price' => 50.00, 'qty' => 2]
            ],
            discounts: [],
            totalGross: 100.00,
            totalNet: 84.03,
            totalVat: 15.97,
            currency: 'EUR'
        );
    }

    private function createDraftContract(): PaymentContract
    {
        return new PaymentContract(
            shopId: '1',
            userId: 'user-123',
            basketSnapshot: $this->createBasketSnapshot(),
            state: PaymentContract::STATE_DRAFT
        );
    }

    private function createContractWithPendingConditions(): PaymentContract
    {
        $contract = $this->createDraftContract();
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING
        ));
        $contract->addCondition(new ContractCondition(
            type: ContractCondition::TYPE_FRAUD_CHECK,
            status: ContractCondition::STATUS_PENDING
        ));
        $contract->transitionToPending();

        return $contract;
    }

    private function createReadyToCommitContract(): PaymentContract
    {
        $contract = $this->createContractWithPendingConditions();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->fulfillCondition(ContractCondition::TYPE_FRAUD_CHECK);

        return $contract;
    }

    private function createCommittedContract(): PaymentContract
    {
        $contract = $this->createReadyToCommitContract();
        $contract->commitToOrder('order-789');

        return $contract;
    }

    private function createFulfilledContract(): PaymentContract
    {
        $contract = $this->createCommittedContract();
        $contract->fulfill();

        return $contract;
    }
}
```

#### Step 2: Implement PaymentContract Model

```php
<?php
// src/Component/Model/PaymentContract.php

declare(strict_types=1);

namespace Osc\Payment\Component\Model;

use Osc\Payment\Component\ValueObject\BasketSnapshot;
use Osc\Payment\Component\Entity\ContractCondition;
use Osc\Payment\Component\Event;

/**
 * Payment Contract - Aggregate Root
 *
 * Manages the payment lifecycle from purchase intent to order creation.
 *
 * State transitions:
 * DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
 *      ↓           ↓                 ↓
 *  CANCELLED   EXPIRED            FAILED
 */
final class PaymentContract
{
    // States (match OXSTATE values)
    public const STATE_DRAFT = 'draft';
    public const STATE_PENDING = 'pending';
    public const STATE_READY_TO_COMMIT = 'ready_to_commit';
    public const STATE_COMMITTED = 'committed';
    public const STATE_FULFILLED = 'fulfilled';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_EXPIRED = 'expired';
    public const STATE_FAILED = 'failed';

    // Properties (map to database columns)
    private ?string $id = null;                    // OXID
    private string $shopId;                         // OXSHOPID
    private string $userId;                         // OXUSERID
    private ?string $orderId = null;                // OXORDERID (NULL until committed)
    private string $state;                          // OXSTATE
    private ?string $stateReason = null;            // OXSTATEREASON
    private BasketSnapshot $basketSnapshot;         // OXBASKETDATA (Value Object)
    private array $conditions = [];                 // OXCONDITIONS (ContractCondition[])
    private ?string $provider = null;               // OXPROVIDER
    private ?string $providerOrderId = null;        // OXPROVIDERORDERID
    private \DateTime $createdAt;                   // OXCREATED
    private \DateTime $updatedAt;                   // OXUPDATED
    private ?\DateTime $committedAt = null;         // OXCOMMITTEDAT
    private ?\DateTime $fulfilledAt = null;         // OXFULFILLEDAT
    private ?\DateTime $expiresAt = null;           // OXEXPIRESAT

    // Domain events (not persisted)
    private array $recordedEvents = [];

    public function __construct(
        string $shopId,
        string $userId,
        BasketSnapshot $basketSnapshot,
        string $state = self::STATE_DRAFT
    ) {
        $this->shopId = $shopId;
        $this->userId = $userId;
        $this->basketSnapshot = $basketSnapshot;
        $this->state = $state;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->expiresAt = new \DateTime('+24 hours');
    }

    /**
     * Add a condition that must be fulfilled before contract can be committed
     */
    public function addCondition(ContractCondition $condition): void
    {
        if (!in_array($this->state, [self::STATE_DRAFT, self::STATE_PENDING])) {
            throw new \LogicException(
                "Cannot add conditions to contract in state: {$this->state}"
            );
        }

        $this->conditions[] = $condition;
        $this->updatedAt = new \DateTime();
    }

    /**
     * Transition from DRAFT to PENDING (conditions being resolved)
     */
    public function transitionToPending(): void
    {
        if ($this->state !== self::STATE_DRAFT) {
            throw new \LogicException("Cannot transition from {$this->state} to PENDING");
        }

        if (empty($this->conditions)) {
            throw new \LogicException("Cannot transition to PENDING without conditions");
        }

        $this->state = self::STATE_PENDING;
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractTransitionedToPendingEvent($this));
    }

    /**
     * Fulfill a specific condition
     */
    public function fulfillCondition(string $type, array $data = []): void
    {
        $conditionFulfilled = false;

        foreach ($this->conditions as $condition) {
            if ($condition->getType() === $type && !$condition->isFulfilled()) {
                $condition->fulfill($data);
                $conditionFulfilled = true;
                break;
            }
        }

        if (!$conditionFulfilled) {
            throw new \InvalidArgumentException("Condition not found or already fulfilled: {$type}");
        }

        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractConditionFulfilledEvent($this, $type, $data));

        // Check if all conditions fulfilled → transition to READY_TO_COMMIT
        if ($this->areAllConditionsFulfilled() && $this->state === self::STATE_PENDING) {
            $this->transitionToReadyToCommit();
        }
    }

    /**
     * Check if all conditions are fulfilled
     */
    public function areAllConditionsFulfilled(): bool
    {
        if (empty($this->conditions)) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition->isFulfilled()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transition from PENDING to READY_TO_COMMIT (all conditions met)
     */
    private function transitionToReadyToCommit(): void
    {
        if ($this->state !== self::STATE_PENDING) {
            throw new \LogicException("Cannot transition from {$this->state} to READY_TO_COMMIT");
        }

        $this->state = self::STATE_READY_TO_COMMIT;
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractReadyToCommitEvent($this));
    }

    /**
     * Commit contract to order (order created, contract linked)
     */
    public function commitToOrder(string $orderId): void
    {
        if ($this->state !== self::STATE_READY_TO_COMMIT) {
            throw new \LogicException(
                "Cannot commit contract in state: {$this->state}. Must be READY_TO_COMMIT."
            );
        }

        if (!$this->areAllConditionsFulfilled()) {
            throw new \LogicException("Cannot commit contract with unfulfilled conditions");
        }

        $this->orderId = $orderId;
        $this->state = self::STATE_COMMITTED;
        $this->committedAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractCommittedEvent($this, $orderId));
    }

    /**
     * Fulfill contract (payment captured, contract complete)
     */
    public function fulfill(): void
    {
        if ($this->state !== self::STATE_COMMITTED) {
            throw new \LogicException("Cannot fulfill contract in state: {$this->state}");
        }

        if (!$this->orderId) {
            throw new \LogicException("Cannot fulfill contract without order ID");
        }

        $this->state = self::STATE_FULFILLED;
        $this->fulfilledAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractFulfilledEvent($this));
    }

    /**
     * Cancel contract (user or system cancellation)
     */
    public function cancel(string $reason): void
    {
        if ($this->state === self::STATE_FULFILLED) {
            throw new \LogicException("Cannot cancel fulfilled contract");
        }

        $this->state = self::STATE_CANCELLED;
        $this->stateReason = $reason;
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractCancelledEvent($this, $reason));
    }

    /**
     * Mark contract as expired (no action taken within time limit)
     */
    public function expire(): void
    {
        if ($this->state === self::STATE_FULFILLED || $this->state === self::STATE_CANCELLED) {
            throw new \LogicException("Cannot expire contract in terminal state: {$this->state}");
        }

        $this->state = self::STATE_EXPIRED;
        $this->stateReason = 'Contract expired after 24 hours';
        $this->updatedAt = new \DateTime();
        $this->recordEvent(new Event\ContractExpiredEvent($this));
    }

    /**
     * Check if contract is expired
     */
    public function isExpired(): bool
    {
        return $this->expiresAt && new \DateTime() > $this->expiresAt;
    }

    /**
     * Set provider information (Stripe, PayPal, etc.)
     */
    public function setProvider(string $provider, string $providerOrderId): void
    {
        $this->provider = $provider;
        $this->providerOrderId = $providerOrderId;
        $this->updatedAt = new \DateTime();
    }

    // Getters
    public function getId(): ?string { return $this->id; }
    public function getShopId(): string { return $this->shopId; }
    public function getUserId(): string { return $this->userId; }
    public function getOrderId(): ?string { return $this->orderId; }
    public function getState(): string { return $this->state; }
    public function getStateReason(): ?string { return $this->stateReason; }
    public function getBasketSnapshot(): BasketSnapshot { return $this->basketSnapshot; }
    public function getConditions(): array { return $this->conditions; }
    public function getProvider(): ?string { return $this->provider; }
    public function getProviderOrderId(): ?string { return $this->providerOrderId; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getUpdatedAt(): \DateTime { return $this->updatedAt; }
    public function getCommittedAt(): ?\DateTime { return $this->committedAt; }
    public function getFulfilledAt(): ?\DateTime { return $this->fulfilledAt; }
    public function getExpiresAt(): ?\DateTime { return $this->expiresAt; }

    /**
     * Get recorded domain events
     */
    public function getRecordedEvents(): array
    {
        return $this->recordedEvents;
    }

    /**
     * Clear recorded domain events (after dispatching)
     */
    public function clearRecordedEvents(): void
    {
        $this->recordedEvents = [];
    }

    /**
     * Record a domain event (internal)
     */
    private function recordEvent(object $event): void
    {
        $this->recordedEvents[] = $event;
    }
}
```

#### Step 3: Run Tests → See GREEN

```bash
vendor/bin/phpunit tests/Component/Unit/Model/PaymentContractTest.php
```

---

### Model 2: ContractCondition (Entity)

#### Step 1: Write Tests FIRST

```php
<?php
// tests/Component/Unit/Entity/ContractConditionTest.php

declare(strict_types=1);

namespace Tests\Component\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Osc\Payment\Component\Entity\ContractCondition;

/**
 * @group unit
 * @group entity
 * @group smart-contract
 */
class ContractConditionTest extends TestCase
{
    /** @test */
    public function it_creates_condition_with_pending_status(): void
    {
        $condition = new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING
        );

        $this->assertEquals(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $condition->getType());
        $this->assertEquals(ContractCondition::STATUS_PENDING, $condition->getStatus());
        $this->assertFalse($condition->isFulfilled());
        $this->assertTrue($condition->isPending());
    }

    /** @test */
    public function it_fulfills_condition_with_data(): void
    {
        $condition = new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING
        );

        $condition->fulfill(['authorizationId' => 'auth_123', 'amount' => 99.99]);

        $this->assertEquals(ContractCondition::STATUS_FULFILLED, $condition->getStatus());
        $this->assertTrue($condition->isFulfilled());
        $this->assertNotNull($condition->getFulfilledAt());
        $this->assertEquals('auth_123', $condition->getData()['authorizationId']);
    }

    /** @test */
    public function it_prevents_fulfilling_already_fulfilled_condition(): void
    {
        $condition = new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING
        );

        $condition->fulfill();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Condition already fulfilled');

        $condition->fulfill();
    }

    /** @test */
    public function it_fails_condition_with_reason(): void
    {
        $condition = new ContractCondition(
            type: ContractCondition::TYPE_FRAUD_CHECK,
            status: ContractCondition::STATUS_PENDING
        );

        $condition->fail('Risk score too high: 85');

        $this->assertEquals(ContractCondition::STATUS_FAILED, $condition->getStatus());
        $this->assertTrue($condition->isFailed());
        $this->assertEquals('Risk score too high: 85', $condition->getFailureReason());
    }

    /** @test */
    public function it_converts_to_array_for_json_storage(): void
    {
        $condition = new ContractCondition(
            type: ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            status: ContractCondition::STATUS_PENDING,
            data: ['test' => 'value']
        );

        $array = $condition->toArray();

        $this->assertIsArray($array);
        $this->assertEquals(ContractCondition::TYPE_PAYMENT_AUTHORIZED, $array['type']);
        $this->assertEquals(ContractCondition::STATUS_PENDING, $array['status']);
        $this->assertEquals(['test' => 'value'], $array['data']);
        $this->assertArrayHasKey('createdAt', $array);
    }

    /** @test */
    public function it_creates_from_array_for_hydration(): void
    {
        $data = [
            'type' => ContractCondition::TYPE_FRAUD_CHECK,
            'status' => ContractCondition::STATUS_FULFILLED,
            'data' => ['score' => 98],
            'createdAt' => '2025-10-22T10:00:00+00:00',
            'fulfilledAt' => '2025-10-22T10:01:00+00:00',
            'failureReason' => null
        ];

        $condition = ContractCondition::fromArray($data);

        $this->assertEquals(ContractCondition::TYPE_FRAUD_CHECK, $condition->getType());
        $this->assertEquals(ContractCondition::STATUS_FULFILLED, $condition->getStatus());
        $this->assertEquals(['score' => 98], $condition->getData());
        $this->assertInstanceOf(\DateTime::class, $condition->getCreatedAt());
    }
}
```

#### Step 2: Implement ContractCondition Entity

```php
<?php
// src/Component/Entity/ContractCondition.php

declare(strict_types=1);

namespace Osc\Payment\Component\Entity;

/**
 * Contract Condition
 *
 * Represents a precondition that must be fulfilled before contract can be committed.
 */
final class ContractCondition
{
    // Condition types
    public const TYPE_PAYMENT_AUTHORIZED = 'payment_authorized';
    public const TYPE_FRAUD_CHECK = 'fraud_check';
    public const TYPE_STOCK_RESERVED = 'stock_reserved';
    public const TYPE_COMPLIANCE_CHECK = 'compliance_check';
    public const TYPE_ADDRESS_VALIDATED = 'address_validated';
    public const TYPE_AGE_VERIFICATION = 'age_verification';
    public const TYPE_CUSTOM = 'custom';

    // Statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_FAILED = 'failed';

    private string $type;
    private string $status;
    private array $data;
    private \DateTime $createdAt;
    private ?\DateTime $fulfilledAt = null;
    private ?string $failureReason = null;

    public function __construct(
        string $type,
        string $status = self::STATUS_PENDING,
        array $data = []
    ) {
        $this->type = $type;
        $this->status = $status;
        $this->data = $data;
        $this->createdAt = new \DateTime();
    }

    public function fulfill(array $data = []): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \LogicException("Condition already fulfilled: {$this->type}");
        }

        $this->status = self::STATUS_FULFILLED;
        $this->data = array_merge($this->data, $data);
        $this->fulfilledAt = new \DateTime();
    }

    public function fail(string $reason): void
    {
        if ($this->status === self::STATUS_FULFILLED) {
            throw new \LogicException("Cannot fail already fulfilled condition: {$this->type}");
        }

        $this->status = self::STATUS_FAILED;
        $this->failureReason = $reason;
    }

    public function isFulfilled(): bool
    {
        return $this->status === self::STATUS_FULFILLED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Getters
    public function getType(): string { return $this->type; }
    public function getStatus(): string { return $this->status; }
    public function getData(): array { return $this->data; }
    public function getCreatedAt(): \DateTime { return $this->createdAt; }
    public function getFulfilledAt(): ?\DateTime { return $this->fulfilledAt; }
    public function getFailureReason(): ?string { return $this->failureReason; }

    /**
     * Convert to array (for JSON storage in OXCONDITIONS column)
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'data' => $this->data,
            'createdAt' => $this->createdAt->format(\DateTime::ATOM),
            'fulfilledAt' => $this->fulfilledAt?->format(\DateTime::ATOM),
            'failureReason' => $this->failureReason,
        ];
    }

    /**
     * Create from array (from JSON storage)
     */
    public static function fromArray(array $data): self
    {
        $condition = new self(
            type: $data['type'],
            status: $data['status'],
            data: $data['data'] ?? []
        );

        $condition->createdAt = new \DateTime($data['createdAt']);

        if (!empty($data['fulfilledAt'])) {
            $condition->fulfilledAt = new \DateTime($data['fulfilledAt']);
        }

        if (!empty($data['failureReason'])) {
            $condition->failureReason = $data['failureReason'];
        }

        return $condition;
    }
}
```

---

## Value Objects

### BasketSnapshot (Value Object)

```php
<?php
// src/Component/ValueObject/BasketSnapshot.php

declare(strict_types=1);

namespace Osc\Payment\Component\ValueObject;

/**
 * Basket Snapshot (Value Object)
 *
 * Immutable snapshot of basket data captured when contract is created.
 */
final class BasketSnapshot
{
    private array $items;
    private array $discounts;
    private float $totalGross;
    private float $totalNet;
    private float $totalVat;
    private string $currency;
    private \DateTime $capturedAt;

    public function __construct(
        array $items,
        array $discounts,
        float $totalGross,
        float $totalNet,
        float $totalVat,
        string $currency
    ) {
        // Immutable - no setters allowed
        $this->items = $items;
        $this->discounts = $discounts;
        $this->totalGross = $totalGross;
        $this->totalNet = $totalNet;
        $this->totalVat = $totalVat;
        $this->currency = $currency;
        $this->capturedAt = new \DateTime();
    }

    // Only getters
    public function getItems(): array { return $this->items; }
    public function getDiscounts(): array { return $this->discounts; }
    public function getTotalGross(): float { return $this->totalGross; }
    public function getTotalNet(): float { return $this->totalNet; }
    public function getTotalVat(): float { return $this->totalVat; }
    public function getCurrency(): string { return $this->currency; }
    public function getCapturedAt(): \DateTime { return $this->capturedAt; }

    /**
     * Convert to array (for JSON storage in OXBASKETDATA column)
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'discounts' => $this->discounts,
            'totals' => [
                'gross' => $this->totalGross,
                'net' => $this->totalNet,
                'vat' => $this->totalVat,
                'currency' => $this->currency,
            ],
            'capturedAt' => $this->capturedAt->format(\DateTime::ATOM),
        ];
    }

    /**
     * Create from array (from JSON storage)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            items: $data['items'] ?? [],
            discounts: $data['discounts'] ?? [],
            totalGross: (float)($data['totals']['gross'] ?? 0.0),
            totalNet: (float)($data['totals']['net'] ?? 0.0),
            totalVat: (float)($data['totals']['vat'] ?? 0.0),
            currency: $data['totals']['currency'] ?? 'EUR'
        );
    }

    /**
     * Create from OXID basket
     */
    public static function fromOxidBasket(\OxidEsales\Eshop\Application\Model\Basket $basket): self
    {
        $items = [];
        foreach ($basket->getContents() as $basketItem) {
            $items[] = [
                'articleId' => $basketItem->getProductId(),
                'title' => $basketItem->getTitle(),
                'amount' => $basketItem->getAmount(),
                'price' => $basketItem->getPrice()->getBruttoPrice(),
                'vat' => $basketItem->getPrice()->getVat(),
            ];
        }

        $discounts = [];
        foreach ($basket->getDiscounts() as $discount) {
            $discounts[] = [
                'type' => 'voucher',
                'code' => $discount->getId(),
                'amount' => -$discount->getBruttoPrice(),
            ];
        }

        return new self(
            items: $items,
            discounts: $discounts,
            totalGross: $basket->getBruttoSum(),
            totalNet: $basket->getNettoSum(),
            totalVat: $basket->getBruttoSum() - $basket->getNettoSum(),
            currency: $basket->getBasketCurrency()->name
        );
    }
}
```

---

## Domain Events

### Event Classes

```php
<?php
// src/Component/Event/ContractTransitionedToPendingEvent.php

declare(strict_types=1);

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Model\PaymentContract;

final class ContractTransitionedToPendingEvent
{
    public function __construct(
        private readonly PaymentContract $contract
    ) {}

    public function getContract(): PaymentContract
    {
        return $this->contract;
    }
}
```

```php
<?php
// src/Component/Event/ContractConditionFulfilledEvent.php

declare(strict_types=1);

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Model\PaymentContract;

final class ContractConditionFulfilledEvent
{
    public function __construct(
        private readonly PaymentContract $contract,
        private readonly string $conditionType,
        private readonly array $data
    ) {}

    public function getContract(): PaymentContract { return $this->contract; }
    public function getConditionType(): string { return $this->conditionType; }
    public function getData(): array { return $this->data; }
}
```

```php
<?php
// src/Component/Event/ContractReadyToCommitEvent.php

declare(strict_types=1);

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Model\PaymentContract;

final class ContractReadyToCommitEvent
{
    public function __construct(
        private readonly PaymentContract $contract
    ) {}

    public function getContract(): PaymentContract
    {
        return $this->contract;
    }
}
```

```php
<?php
// src/Component/Event/ContractCommittedEvent.php

declare(strict_types=1);

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Model\PaymentContract;

final class ContractCommittedEvent
{
    public function __construct(
        private readonly PaymentContract $contract,
        private readonly string $orderId
    ) {}

    public function getContract(): PaymentContract { return $this->contract; }
    public function getOrderId(): string { return $this->orderId; }
}
```

```php
<?php
// src/Component/Event/ContractFulfilledEvent.php

declare(strict_types=1);

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Model\PaymentContract;

final class ContractFulfilledEvent
{
    public function __construct(
        private readonly PaymentContract $contract
    ) {}

    public function getContract(): PaymentContract
    {
        return $this->contract;
    }
}
```

```php
<?php
// src/Component/Event/ContractCancelledEvent.php

declare(strict_types=1);

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Model\PaymentContract;

final class ContractCancelledEvent
{
    public function __construct(
        private readonly PaymentContract $contract,
        private readonly string $reason
    ) {}

    public function getContract(): PaymentContract { return $this->contract; }
    public function getReason(): string { return $this->reason; }
}
```

```php
<?php
// src/Component/Event/ContractExpiredEvent.php

declare(strict_types=1);

namespace Osc\Payment\Component\Event;

use Osc\Payment\Component\Model\PaymentContract;

final class ContractExpiredEvent
{
    public function __construct(
        private readonly PaymentContract $contract
    ) {}

    public function getContract(): PaymentContract
    {
        return $this->contract;
    }
}
```

---

**Continue to [Part 3: Repository Pattern & Testing](IMPLEMENTATION-DB-SPRINT-1-PART-3-REPOSITORIES.md)**
