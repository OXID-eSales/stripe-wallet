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
 * Tests capture idempotency — ensures that repeated capture attempts
 * on the same contract are handled correctly at the domain level.
 *
 * @covers \OxidEsales\PaymentComponent\Contract\PaymentContract
 * @group security
 * @group pci-dss
 * @group sprint-58
 */
final class CaptureIdempotencyTest extends TestCase
{
    /**
     * @test
     *
     * Once captured (authorized → ready_to_commit), a second capture throws.
     */
    public function testDoubleCaptureThrows(): void
    {
        $contract = SecurityTestHelper::createContractInState('authorized');

        $contract->captureAuthorization();
        $this->assertTrue($contract->getState()->isReadyToCommit());

        $this->expectException(DomainException::class);
        $contract->captureAuthorization();
    }

    /**
     * @test
     *
     * After commit, capture is no longer possible.
     */
    public function testCaptureAfterCommitThrows(): void
    {
        // Create a committed contract (goes through ready_to_commit with fulfilled conditions)
        $contract = SecurityTestHelper::createContractInState('committed');

        $this->expectException(DomainException::class);
        $contract->captureAuthorization();
    }

    /**
     * @test
     */
    public function testCaptureFromPendingThrows(): void
    {
        $contract = SecurityTestHelper::createContractInState('pending');

        $this->expectException(DomainException::class);
        $contract->captureAuthorization();
    }

    /**
     * @test
     */
    public function testCaptureFromDraftThrows(): void
    {
        $contract = SecurityTestHelper::createContractInState('draft');

        $this->expectException(DomainException::class);
        $contract->captureAuthorization();
    }

    /**
     * @test
     *
     * Verify that capturedAmount tracking is independent of state transitions.
     */
    public function testCapturedAmountIsTrackedSeparately(): void
    {
        $contract = SecurityTestHelper::createContractInState('authorized');

        $this->assertNull($contract->getCapturedAmount());

        $contract->setCapturedAmount(99.99);
        $this->assertSame(99.99, $contract->getCapturedAmount());

        $contract->captureAuthorization();
        // Capture amount persists after state transition
        $this->assertSame(99.99, $contract->getCapturedAmount());
    }
}
