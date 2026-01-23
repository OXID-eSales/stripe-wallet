<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\OrderRefundUpdateService;
use OxidEsales\Payments\Stripe\Service\OrderRefundUpdateServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for OrderRefundUpdateService.
 *
 * Sprint 10: Extracted from StripeRefundRequestHandler.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\OrderRefundUpdateService
 */
class OrderRefundUpdateServiceTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $service = new OrderRefundUpdateService(new NullLogger());

        $this->assertInstanceOf(OrderRefundUpdateServiceInterface::class, $service);
    }

    public function testConstructorAcceptsNullLogger(): void
    {
        $service = new OrderRefundUpdateService(null);

        $this->assertInstanceOf(OrderRefundUpdateServiceInterface::class, $service);
    }

    public function testConstructorDefaultsToNullLogger(): void
    {
        $service = new OrderRefundUpdateService();

        $this->assertInstanceOf(OrderRefundUpdateServiceInterface::class, $service);
    }
}
