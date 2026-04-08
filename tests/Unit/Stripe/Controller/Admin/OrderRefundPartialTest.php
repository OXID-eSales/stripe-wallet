<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller\Admin;

use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 87: Tests for partial capture/refund amount extraction in OrderRefund controller.
 *
 * Tests the getRefundAmount() and getCaptureAmount() methods that parse
 * and validate amount from HTTP request parameters.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefund
 * @group sprint-87
 */
final class OrderRefundPartialTest extends TestCase
{
    /**
     * Sprint 87: getRefundAmount() returns float for valid amount string.
     */
    public function testGetRefundAmountReturnsFloatForValidInput(): void
    {
        $controller = new TestableOrderRefundForPartialAmount(['refund_amount' => '50.00']);

        $this->assertSame(50.00, $controller->getRefundAmount());
    }

    /**
     * Sprint 87: getRefundAmount() returns null for empty input (= full refund).
     */
    public function testGetRefundAmountReturnsNullWhenEmpty(): void
    {
        $controller = new TestableOrderRefundForPartialAmount([]);

        $this->assertNull($controller->getRefundAmount());
    }

    /**
     * Sprint 87: getRefundAmount() returns null for zero (invalid).
     */
    public function testGetRefundAmountReturnsNullForZero(): void
    {
        $controller = new TestableOrderRefundForPartialAmount(['refund_amount' => '0']);

        $this->assertNull($controller->getRefundAmount());
    }

    /**
     * Sprint 87: getRefundAmount() returns null for negative (invalid).
     */
    public function testGetRefundAmountReturnsNullForNegative(): void
    {
        $controller = new TestableOrderRefundForPartialAmount(['refund_amount' => '-10.00']);

        $this->assertNull($controller->getRefundAmount());
    }

    /**
     * Sprint 87: getCaptureAmount() returns float for valid amount.
     */
    public function testGetCaptureAmountReturnsFloatForValidInput(): void
    {
        $controller = new TestableOrderRefundForPartialAmount(['capture_amount' => '75.50']);

        $this->assertSame(75.50, $controller->getCaptureAmount());
    }

    /**
     * Sprint 87: getCaptureAmount() returns null for empty (= full capture).
     */
    public function testGetCaptureAmountReturnsNullWhenEmpty(): void
    {
        $controller = new TestableOrderRefundForPartialAmount([]);

        $this->assertNull($controller->getCaptureAmount());
    }
}

/**
 * Testable subclass for amount parsing tests.
 */
class TestableOrderRefundForPartialAmount extends OrderRefund
{
    /** @var array<string, string> */
    private array $testRequestParams;

    /** @param array<string, string> $requestParams */
    public function __construct(array $requestParams = [])
    {
        $this->testRequestParams = $requestParams;
    }

    protected function getRequestParam(string $name): ?string
    {
        $value = $this->testRequestParams[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}
