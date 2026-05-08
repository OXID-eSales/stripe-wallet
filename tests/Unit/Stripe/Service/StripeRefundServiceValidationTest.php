<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Service\Exception\RefundFailedException;
use OxidEsales\Payments\Stripe\Service\StripeRefundService;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 87: Tests for StripeRefundService::validateRefundAmount() partial support.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\StripeRefundService
 * @group sprint-87
 */
final class StripeRefundServiceValidationTest extends TestCase
{
    /**
     * Sprint 87: Partial refund within limits should be accepted.
     */
    public function testValidateRefundAmountAcceptsPartialAmount(): void
    {
        $service = $this->createTestableService();

        // Should NOT throw — 50 <= 100
        $service->callValidateRefundAmount('contract_1', 50.00, 100.00);

        $this->assertTrue(true, 'Partial refund amount within limits was accepted');
    }

    /**
     * Sprint 87: Refund exceeding available amount should be rejected.
     */
    public function testValidateRefundAmountRejectsOverRefund(): void
    {
        $service = $this->createTestableService();

        $this->expectException(RefundFailedException::class);

        $service->callValidateRefundAmount('contract_1', 150.00, 100.00);
    }

    /**
     * Sprint 87: Full refund (amount == available) should still work.
     */
    public function testValidateRefundAmountAcceptsFullRefund(): void
    {
        $service = $this->createTestableService();

        $service->callValidateRefundAmount('contract_1', 100.00, 100.00);

        $this->assertTrue(true, 'Full refund amount was accepted');
    }

    private function createTestableService(): TestableStripeRefundServiceForValidation
    {
        return new TestableStripeRefundServiceForValidation();
    }
}

/**
 * Exposes protected validateRefundAmount() for testing.
 */
class TestableStripeRefundServiceForValidation extends StripeRefundService
{
    public function __construct()
    {
        // Skip parent constructor — we only test validation logic
    }

    public function callValidateRefundAmount(string $contractId, float $amount, float $available): void
    {
        $this->validateRefundAmount($contractId, $amount, $available);
    }
}
