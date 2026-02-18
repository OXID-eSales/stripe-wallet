<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\HostedCommerceServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\StripeProductCatalogSyncService;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeProductCatalogSyncService.
 *
 * Sprint 57: Refactored to mock adapter layer instead of raw HTTP client.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\StripeProductCatalogSyncService
 */
class StripeProductCatalogSyncServiceTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $adapter;
    private AcpProductServiceInterface&MockObject $productService;
    private ProductFeedGeneratorInterface&MockObject $feedGenerator;
    private FileLoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);
        $this->productService = $this->createMock(AcpProductServiceInterface::class);
        $this->feedGenerator = $this->createMock(ProductFeedGeneratorInterface::class);
        $this->logger = $this->createMock(FileLoggerInterface::class);
    }

    private function createService(): StripeProductCatalogSyncService
    {
        return new StripeProductCatalogSyncService(
            $this->adapterFactory,
            $this->productService,
            $this->feedGenerator,
            $this->logger
        );
    }

    public function testImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(HostedCommerceServiceInterface::class, $service);
    }

    // ==========================================
    // syncCatalog() - success response
    // ==========================================

    public function testSyncCatalogSuccessReturnsSuccessfulResult(): void
    {
        $this->adapter
            ->expects($this->once())
            ->method('syncProductCatalog')
            ->with('csv-feed-content', 'csv')
            ->willReturn([
                'successful' => true,
                'products_processed' => 50,
                'products_created' => 30,
                'products_updated' => 20,
            ]);

        $service = $this->createService();
        $result = $service->syncCatalog('csv-feed-content', 'csv');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(50, $result->getProductsProcessed());
        $this->assertSame(30, $result->getProductsCreated());
        $this->assertSame(20, $result->getProductsUpdated());
    }

    public function testSyncCatalogWithJsonlFormatPassesCorrectFormat(): void
    {
        $this->adapter
            ->expects($this->once())
            ->method('syncProductCatalog')
            ->with('{"id":"p1"}\n', 'jsonl')
            ->willReturn([
                'successful' => true,
                'products_processed' => 10,
                'products_created' => 10,
                'products_updated' => 0,
            ]);

        $service = $this->createService();
        $result = $service->syncCatalog('{"id":"p1"}\n', 'jsonl');

        $this->assertTrue($result->isSuccessful());
    }

    // ==========================================
    // syncCatalog() - failed response
    // ==========================================

    public function testSyncCatalogFailedAdapterResponseReturnsFailure(): void
    {
        $this->adapter
            ->expects($this->once())
            ->method('syncProductCatalog')
            ->willReturn([
                'successful' => false,
                'error' => 'Internal Server Error',
            ]);

        $service = $this->createService();
        $result = $service->syncCatalog('feed-data', 'csv');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(['Internal Server Error'], $result->getErrorMessages());
    }

    // ==========================================
    // syncCatalog() - missing API key
    // ==========================================

    public function testSyncCatalogWithMissingApiKeyReturnsFailure(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')
            ->willThrowException(new \RuntimeException('Stripe API key is not configured'));

        $service = new StripeProductCatalogSyncService(
            $this->adapterFactory,
            $this->productService,
            $this->feedGenerator,
            $this->logger
        );
        $result = $service->syncCatalog('feed-data', 'csv');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(['Stripe API key not configured'], $result->getErrorMessages());
    }

    // ==========================================
    // syncAllProducts() - composed flow
    // ==========================================

    public function testSyncAllProductsComposesProductServiceFeedGeneratorAndAdapter(): void
    {
        $products = [
            ['id' => 'prod_1', 'name' => 'Widget', 'price' => 9.99],
            ['id' => 'prod_2', 'name' => 'Gadget', 'price' => 19.99],
        ];

        $this->productService
            ->expects($this->once())
            ->method('listProducts')
            ->with(['limit' => 10000])
            ->willReturn(['products' => $products]);

        $this->feedGenerator
            ->expects($this->once())
            ->method('generate')
            ->with($products)
            ->willReturn('generated-feed-content');

        $this->feedGenerator
            ->expects($this->once())
            ->method('getFileExtension')
            ->willReturn('csv');

        $this->adapter
            ->expects($this->once())
            ->method('syncProductCatalog')
            ->with('generated-feed-content', 'csv')
            ->willReturn([
                'successful' => true,
                'products_processed' => 2,
                'products_created' => 2,
                'products_updated' => 0,
            ]);

        $service = $this->createService();
        $result = $service->syncAllProducts();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getProductsProcessed());
        $this->assertSame(2, $result->getProductsCreated());
    }

    public function testSyncAllProductsWithMissingApiKeyFailsBeforeAdapterCall(): void
    {
        $this->productService
            ->method('listProducts')
            ->willReturn(['products' => []]);

        $this->feedGenerator
            ->method('generate')
            ->willReturn('');

        $this->feedGenerator
            ->method('getFileExtension')
            ->willReturn('csv');

        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')
            ->willThrowException(new \RuntimeException('Stripe API key is not configured'));

        $service = new StripeProductCatalogSyncService(
            $this->adapterFactory,
            $this->productService,
            $this->feedGenerator,
            $this->logger
        );
        $result = $service->syncAllProducts();

        $this->assertFalse($result->isSuccessful());
    }

    // ==========================================
    // updateFulfillmentStatus()
    // ==========================================

    public function testUpdateFulfillmentStatusDelegatesToAdapter(): void
    {
        $this->adapter
            ->expects($this->once())
            ->method('updateFulfillmentStatus')
            ->with('order_123', 'shipped', ['tracking' => 'ABC'])
            ->willReturn(true);

        $service = $this->createService();
        $result = $service->updateFulfillmentStatus('order_123', 'shipped', ['tracking' => 'ABC']);

        $this->assertTrue($result);
    }

    public function testUpdateFulfillmentStatusReturnsFalseOnAdapterFailure(): void
    {
        $this->adapter
            ->expects($this->once())
            ->method('updateFulfillmentStatus')
            ->willReturn(false);

        $service = $this->createService();
        $result = $service->updateFulfillmentStatus('order_456', 'shipped');

        $this->assertFalse($result);
    }

    public function testUpdateFulfillmentStatusReturnsFalseWhenApiKeyMissing(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')
            ->willThrowException(new \RuntimeException('Stripe API key is not configured'));

        $service = new StripeProductCatalogSyncService(
            $this->adapterFactory,
            $this->productService,
            $this->feedGenerator,
            $this->logger
        );
        $result = $service->updateFulfillmentStatus('order_789', 'shipped');

        $this->assertFalse($result);
    }
}
