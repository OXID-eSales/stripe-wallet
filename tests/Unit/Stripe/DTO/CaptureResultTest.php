<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\DTO;

use OxidEsales\Payments\Stripe\DTO\CaptureResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaptureResult DTO.
 *
 * Sprint 9: Immutable result object for capture operations.
 *
 * @covers \OxidEsales\Payments\Stripe\DTO\CaptureResult
 */
class CaptureResultTest extends TestCase
{
    public function testSuccessfulResultHasAllProperties(): void
    {
        $capturedAt = new \DateTimeImmutable('2026-01-23 12:00:00');
        $result = CaptureResult::success(
            captureId: 'ch_123',
            amountCaptured: 10.00,
            currency: 'eur',
            capturedAt: $capturedAt
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('ch_123', $result->getCaptureId());
        $this->assertSame(10.00, $result->getAmountCaptured());
        $this->assertSame('eur', $result->getCurrency());
        $this->assertSame($capturedAt, $result->getCapturedAt());
        $this->assertNull($result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testFailedResultWithCodeHasCorrectProperties(): void
    {
        $result = CaptureResult::failure('Card declined', 'card_declined');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getCaptureId());
        $this->assertNull($result->getAmountCaptured());
        $this->assertNull($result->getCurrency());
        $this->assertNull($result->getCapturedAt());
        $this->assertSame('Card declined', $result->getErrorMessage());
        $this->assertSame('card_declined', $result->getErrorCode());
    }

    public function testFailedResultWithoutCodeHasNullCode(): void
    {
        $result = CaptureResult::failure('Unknown error');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('Unknown error', $result->getErrorMessage());
        $this->assertNull($result->getErrorCode());
    }

    public function testSuccessfulResultIsImmutable(): void
    {
        $capturedAt = new \DateTimeImmutable();
        $result1 = CaptureResult::success('ch_1', 10.00, 'eur', $capturedAt);
        $result2 = CaptureResult::success('ch_2', 20.00, 'usd', $capturedAt);

        // Different instances should have different values
        $this->assertNotSame($result1->getCaptureId(), $result2->getCaptureId());
        $this->assertNotSame($result1->getAmountCaptured(), $result2->getAmountCaptured());
    }

    public function testFailedResultIsImmutable(): void
    {
        $result1 = CaptureResult::failure('Error 1', 'code_1');
        $result2 = CaptureResult::failure('Error 2', 'code_2');

        $this->assertNotSame($result1->getErrorMessage(), $result2->getErrorMessage());
        $this->assertNotSame($result1->getErrorCode(), $result2->getErrorCode());
    }
}
