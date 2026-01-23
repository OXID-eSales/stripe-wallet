<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\DTO;

use OxidEsales\Payments\Stripe\DTO\CancellationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CancellationResult DTO.
 *
 * Sprint 11: Tests for the cancel authorization result DTO.
 *
 * @covers \OxidEsales\Payments\Stripe\DTO\CancellationResult
 */
class CancellationResultTest extends TestCase
{
    public function testSuccessResultHasCorrectValues(): void
    {
        $result = CancellationResult::success('pi_test_123', 'canceled');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('pi_test_123', $result->getPaymentIntentId());
        $this->assertSame('canceled', $result->getStatus());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testFailureResultHasCorrectValues(): void
    {
        $result = CancellationResult::failure('Payment cannot be canceled', 'payment_intent_invalid_status');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getPaymentIntentId());
        $this->assertNull($result->getStatus());
        $this->assertSame('Payment cannot be canceled', $result->getErrorMessage());
        $this->assertSame('payment_intent_invalid_status', $result->getErrorCode());
    }

    public function testFailureResultWithoutErrorCode(): void
    {
        $result = CancellationResult::failure('Something went wrong');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('Something went wrong', $result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testSuccessResultIsImmutable(): void
    {
        $result = CancellationResult::success('pi_immutable', 'canceled');

        $this->assertSame('pi_immutable', $result->getPaymentIntentId());
        $this->assertSame('canceled', $result->getStatus());
    }

    public function testFailureResultIsImmutable(): void
    {
        $result = CancellationResult::failure('Immutable error', 'code_123');

        $this->assertSame('Immutable error', $result->getErrorMessage());
        $this->assertSame('code_123', $result->getErrorCode());
    }
}
