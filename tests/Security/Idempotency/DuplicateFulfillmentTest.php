<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Idempotency;

use DomainException;
use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F5: Domain-level guards against double fulfillment.
 *
 * The contract state machine prevents fulfill() from being called twice,
 * but there is no DB-level lock to prevent concurrent webhooks from
 * both reading the contract as COMMITTED and both calling fulfill().
 *
 * @covers \OxidEsales\PaymentComponent\Contract\PaymentContract
 * @group security
 * @group pci-dss
 * @group finding-f5
 * @group sprint-58
 */
final class DuplicateFulfillmentTest extends TestCase
{
    /**
     * @test
     *
     * Finding F5: Domain guard prevents sequential double-fulfill.
     * But concurrent fulfill() calls could bypass this check.
     */
    public function testFulfillCommittedContractTwiceThrows(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $contract->fulfill();

        $this->assertTrue($contract->getState()->isFulfilled());

        $this->expectException(DomainException::class);
        $contract->fulfill();
    }

    /**
     * @test
     */
    public function testCancelThenFulfillThrows(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $contract->cancel('test cancel');

        $this->assertTrue($contract->getState()->isCancelled());

        $this->expectException(DomainException::class);
        $contract->fulfill();
    }

    /**
     * @test
     */
    public function testFulfillThenCancelThrows(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $contract->fulfill();

        $this->assertTrue($contract->getState()->isFulfilled());

        $this->expectException(DomainException::class);
        $contract->cancel('should not work');
    }

    /**
     * @test
     */
    public function testFulfillThenExpireThrows(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');
        $contract->fulfill();

        $this->expectException(DomainException::class);
        $contract->expire();
    }

    /**
     * @test
     */
    public function testFulfillSetsTimestamp(): void
    {
        $contract = SecurityTestHelper::createContractInState('committed');

        $this->assertNull($contract->getFulfilledAt());

        $contract->fulfill();

        $this->assertNotNull($contract->getFulfilledAt());
    }
}
