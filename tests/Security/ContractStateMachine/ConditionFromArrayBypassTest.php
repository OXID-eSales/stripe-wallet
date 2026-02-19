<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\ContractStateMachine;

use OxidEsales\PaymentComponent\Contract\ContractCondition;
use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F9: ContractCondition::fromArray() sets status directly,
 * bypassing the fulfill() guard logic.
 *
 * This means deserialized conditions can have 'fulfilled' status without
 * going through fulfill(), which skips DomainException checks and does not
 * set fulfilledAt timestamp.
 *
 * @covers \OxidEsales\PaymentComponent\Contract\ContractCondition
 * @group security
 * @group finding-f9
 * @group sprint-58
 */
final class ConditionFromArrayBypassTest extends TestCase
{
    /**
     * @test
     *
     * Finding F9: fromArray() allows direct status setting without fulfill() guard.
     * Documents that a condition can become 'fulfilled' without timestamp or data.
     */
    public function testFromArrayAllowsDirectStatusSetWithoutFulfillGuard(): void
    {
        $condition = ContractCondition::fromArray([
            'type' => ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            'status' => ContractCondition::STATUS_FULFILLED,
            'data' => [],
        ]);

        // Status is set directly — no DomainException about "already fulfilled"
        $this->assertTrue($condition->isFulfilled());
        $this->assertSame(ContractCondition::STATUS_FULFILLED, $condition->getStatus());
    }

    /**
     * @test
     *
     * Finding F9: fromArray() accepts arbitrary status values not in the valid set.
     */
    public function testFromArrayAcceptsArbitraryStatusValues(): void
    {
        // fromArray() does not validate the status string — only the type
        $condition = ContractCondition::fromArray([
            'type' => ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            'status' => 'hacked',
            'data' => [],
        ]);

        $this->assertSame('hacked', $condition->getStatus());
        $this->assertFalse($condition->isFulfilled());
        $this->assertFalse($condition->isPending());
        $this->assertFalse($condition->isFailed());
    }

    /**
     * @test
     *
     * Finding F9: fromArray() with 'fulfilled' status has no fulfilledAt timestamp
     * unless explicitly provided, unlike fulfill() which always sets it.
     */
    public function testFromArrayWithFulfilledStatusHasNoFulfilledAtTimestamp(): void
    {
        $condition = ContractCondition::fromArray([
            'type' => ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            'status' => ContractCondition::STATUS_FULFILLED,
            'data' => [],
            // Note: no fulfilledAt provided
        ]);

        $this->assertTrue($condition->isFulfilled());
        // Unlike fulfill(), fromArray() does NOT auto-set fulfilledAt
        $this->assertNull($condition->getFulfilledAt());
    }

    /**
     * @test
     *
     * Positive case: fromArray() correctly restores fulfilledAt when provided.
     */
    public function testFromArrayRestoresFulfilledAtWhenProvided(): void
    {
        $condition = ContractCondition::fromArray([
            'type' => ContractCondition::TYPE_PAYMENT_AUTHORIZED,
            'status' => ContractCondition::STATUS_FULFILLED,
            'data' => ['pi_id' => 'pi_test_123'],
            'fulfilledAt' => '2026-02-18 10:00:00',
        ]);

        $this->assertTrue($condition->isFulfilled());
        $this->assertNotNull($condition->getFulfilledAt());
    }

    /**
     * @test
     *
     * Roundtrip: fulfill() → toArray() → fromArray() preserves state correctly.
     */
    public function testRoundtripPreservesState(): void
    {
        $original = ContractCondition::paymentAuthorized();
        $original->fulfill(['pi_id' => 'pi_test_123']);

        $serialized = $original->toArray();
        $restored = ContractCondition::fromArray($serialized);

        $this->assertSame($original->getType(), $restored->getType());
        $this->assertSame($original->getStatus(), $restored->getStatus());
        $this->assertTrue($restored->isFulfilled());
        $this->assertNotNull($restored->getFulfilledAt());
    }
}
