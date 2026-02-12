<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SptPaymentResult value object.
 *
 * SptPaymentResult is a readonly value object with named constructors
 * (success/failed). It carries the result of an SPT payment confirmation.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentResult
 */
class SptPaymentResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = SptPaymentResult::success('pi_abc123', 'succeeded');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('pi_abc123', $result->getPaymentIntentId());
        $this->assertSame('succeeded', $result->getStatus());
        $this->assertNull($result->getErrorMessage());
    }

    public function testFailedFactory(): void
    {
        $result = SptPaymentResult::failed('Card declined');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getPaymentIntentId());
        $this->assertNull($result->getStatus());
        $this->assertSame('Card declined', $result->getErrorMessage());
    }

    public function testFailedWithPaymentIntentId(): void
    {
        $result = SptPaymentResult::failed(
            'Unexpected payment status: requires_action',
            'pi_xyz789'
        );

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('pi_xyz789', $result->getPaymentIntentId());
        $this->assertNull($result->getStatus());
        $this->assertSame('Unexpected payment status: requires_action', $result->getErrorMessage());
    }

    public function testSuccessWithRequiresCaptureStatus(): void
    {
        $result = SptPaymentResult::success('pi_manual_capture', 'requires_capture');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('pi_manual_capture', $result->getPaymentIntentId());
        $this->assertSame('requires_capture', $result->getStatus());
        $this->assertNull($result->getErrorMessage());
    }
}
