<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use Doctrine\DBAL\Connection;
use OxidEsales\Payments\Stripe\Service\OxidStockRestorationService;
use OxidEsales\PaymentComponent\Service\StockRestorationServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for OxidStockRestorationService.
 *
 * Sprint 24: TDD - tests written first.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\OxidStockRestorationService
 */
class StockRestorationServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createService(bool $useStock = true, bool $allowNegativeStock = false): OxidStockRestorationService
    {
        return new OxidStockRestorationService(
            $this->connection,
            $this->logger,
            $useStock,
            $allowNegativeStock
        );
    }

    public function testImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(StockRestorationServiceInterface::class, $service);
    }

    /**
     * Note: Testing the actual restoreStockForOrder() method requires OXID bootstrap
     * because it uses oxNew(Order::class). These scenarios are covered by integration tests.
     *
     * Unit tests here verify:
     * - Interface implementation
     * - Constructor parameter handling
     */
    public function testRestoreStockForOrderMethodExists(): void
    {
        $service = $this->createService();

        $this->assertTrue(
            method_exists($service, 'restoreStockForOrder'),
            'Service should have restoreStockForOrder method'
        );
    }

    public function testConstructorAcceptsAllParameters(): void
    {
        // Test that constructor accepts all parameters without error
        $service = new OxidStockRestorationService(
            $this->connection,
            $this->logger,
            true,   // useStock
            true    // allowNegativeStock
        );

        $this->assertInstanceOf(StockRestorationServiceInterface::class, $service);
    }

    public function testConstructorWithNullLogger(): void
    {
        // Test that null logger is accepted (uses NullLogger internally)
        $service = new OxidStockRestorationService(
            $this->connection,
            null,
            true,
            false
        );

        $this->assertInstanceOf(StockRestorationServiceInterface::class, $service);
    }
}
