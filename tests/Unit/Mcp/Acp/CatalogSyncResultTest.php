<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Acp;

use OxidEsales\PaymentComponent\Mcp\Acp\CatalogSyncResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CatalogSyncResult value object.
 *
 * Tests the three factory methods: success(), failed(), and partial().
 *
 * @covers \OxidEsales\PaymentComponent\Mcp\Acp\CatalogSyncResult
 */
class CatalogSyncResultTest extends TestCase
{
    // ==========================================
    // success() factory
    // ==========================================

    public function testSuccessFactoryReturnsSuccessfulResult(): void
    {
        $result = CatalogSyncResult::success(100, 80, 20);

        $this->assertTrue($result->isSuccessful());
    }

    public function testSuccessFactoryPreservesProcessedCount(): void
    {
        $result = CatalogSyncResult::success(150, 100, 50);

        $this->assertSame(150, $result->getProductsProcessed());
    }

    public function testSuccessFactoryPreservesCreatedCount(): void
    {
        $result = CatalogSyncResult::success(150, 100, 50);

        $this->assertSame(100, $result->getProductsCreated());
    }

    public function testSuccessFactoryPreservesUpdatedCount(): void
    {
        $result = CatalogSyncResult::success(150, 100, 50);

        $this->assertSame(50, $result->getProductsUpdated());
    }

    public function testSuccessFactoryHasZeroErrors(): void
    {
        $result = CatalogSyncResult::success(10, 5, 5);

        $this->assertSame(0, $result->getErrors());
    }

    public function testSuccessFactoryHasEmptyErrorMessages(): void
    {
        $result = CatalogSyncResult::success(10, 5, 5);

        $this->assertSame([], $result->getErrorMessages());
    }

    // ==========================================
    // failed() factory
    // ==========================================

    public function testFailedFactoryReturnsUnsuccessfulResult(): void
    {
        $result = CatalogSyncResult::failed('Connection timed out');

        $this->assertFalse($result->isSuccessful());
    }

    public function testFailedFactoryPreservesErrorMessage(): void
    {
        $result = CatalogSyncResult::failed('API key invalid');

        $this->assertSame(['API key invalid'], $result->getErrorMessages());
    }

    public function testFailedFactoryHasOneError(): void
    {
        $result = CatalogSyncResult::failed('Server error');

        $this->assertSame(1, $result->getErrors());
    }

    public function testFailedFactoryHasZeroProcessedProducts(): void
    {
        $result = CatalogSyncResult::failed('Network error');

        $this->assertSame(0, $result->getProductsProcessed());
    }

    public function testFailedFactoryHasZeroCreatedProducts(): void
    {
        $result = CatalogSyncResult::failed('Network error');

        $this->assertSame(0, $result->getProductsCreated());
    }

    public function testFailedFactoryHasZeroUpdatedProducts(): void
    {
        $result = CatalogSyncResult::failed('Network error');

        $this->assertSame(0, $result->getProductsUpdated());
    }

    // ==========================================
    // partial() factory
    // ==========================================

    public function testPartialWithZeroErrorsIsSuccessful(): void
    {
        $result = CatalogSyncResult::partial(50, 30, 20, 0, []);

        $this->assertTrue($result->isSuccessful());
    }

    public function testPartialWithErrorsIsNotSuccessful(): void
    {
        $result = CatalogSyncResult::partial(50, 28, 20, 2, ['Row 3: invalid SKU', 'Row 7: missing price']);

        $this->assertFalse($result->isSuccessful());
    }

    public function testPartialPreservesAllCounts(): void
    {
        $result = CatalogSyncResult::partial(100, 60, 35, 5, ['err1', 'err2', 'err3', 'err4', 'err5']);

        $this->assertSame(100, $result->getProductsProcessed());
        $this->assertSame(60, $result->getProductsCreated());
        $this->assertSame(35, $result->getProductsUpdated());
        $this->assertSame(5, $result->getErrors());
    }

    public function testPartialPreservesErrorMessages(): void
    {
        $errors = ['Missing title', 'Invalid currency'];
        $result = CatalogSyncResult::partial(10, 8, 0, 2, $errors);

        $this->assertSame($errors, $result->getErrorMessages());
    }

    public function testPartialWithZeroErrorsAndEmptyMessages(): void
    {
        $result = CatalogSyncResult::partial(25, 10, 15, 0, []);

        $this->assertSame(0, $result->getErrors());
        $this->assertSame([], $result->getErrorMessages());
    }
}
