<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\HostedCommerceServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientInterface;
use OxidEsales\PaymentComponent\Mcp\Http\HttpClientResponse;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\StripeProductCatalogSyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeProductCatalogSyncService.
 *
 * Tests catalog sync operations via HTTP client, including success/failure
 * response parsing and the composed syncAllProducts flow.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\StripeProductCatalogSyncService
 */
class StripeProductCatalogSyncServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private AcpProductServiceInterface&MockObject $productService;
    private ProductFeedGeneratorInterface&MockObject $feedGenerator;
    private FileLoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->productService = $this->createMock(AcpProductServiceInterface::class);
        $this->feedGenerator = $this->createMock(ProductFeedGeneratorInterface::class);
        $this->logger = $this->createMock(FileLoggerInterface::class);
    }

    private function createService(string $apiKey = 'sk_test_abc123'): StripeProductCatalogSyncService
    {
        return new StripeProductCatalogSyncService(
            $this->httpClient,
            $this->productService,
            $this->feedGenerator,
            $apiKey,
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
        $responseBody = json_encode([
            'products_processed' => 50,
            'products_created' => 30,
            'products_updated' => 20,
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                'https://api.stripe.com/v1/products/import',
                'csv-feed-content',
                $this->callback(function (array $headers) {
                    return ($headers['Authorization'] ?? '') === 'Bearer sk_test_abc123'
                        && ($headers['Content-Type'] ?? '') === 'text/csv';
                }),
                30
            )
            ->willReturn(new HttpClientResponse(200, $responseBody));

        $service = $this->createService();
        $result = $service->syncCatalog('csv-feed-content', 'csv');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(50, $result->getProductsProcessed());
        $this->assertSame(30, $result->getProductsCreated());
        $this->assertSame(20, $result->getProductsUpdated());
    }

    public function testSyncCatalogWithJsonlFormatSetsCorrectContentType(): void
    {
        $responseBody = json_encode([
            'products_processed' => 10,
            'products_created' => 10,
            'products_updated' => 0,
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $headers) {
                    return ($headers['Content-Type'] ?? '') === 'application/x-jsonlines';
                }),
                $this->anything()
            )
            ->willReturn(new HttpClientResponse(200, $responseBody));

        $service = $this->createService();
        $result = $service->syncCatalog('{"id":"p1"}\n', 'jsonl');

        $this->assertTrue($result->isSuccessful());
    }

    // ==========================================
    // syncCatalog() - failed response
    // ==========================================

    public function testSyncCatalogFailedHttpResponseReturnsFailure(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(new HttpClientResponse(500, '', 'Internal Server Error'));

        $service = $this->createService();
        $result = $service->syncCatalog('feed-data', 'csv');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(['Internal Server Error'], $result->getErrorMessages());
    }

    public function testSyncCatalogHttpErrorWithoutMessageFallsBackToStatusCode(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(new HttpClientResponse(403, ''));

        $service = $this->createService();
        $result = $service->syncCatalog('feed-data', 'csv');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(['HTTP 403'], $result->getErrorMessages());
    }

    // ==========================================
    // syncCatalog() - empty API key
    // ==========================================

    public function testSyncCatalogWithEmptyApiKeyReturnsFailure(): void
    {
        $this->httpClient
            ->expects($this->never())
            ->method('post');

        $service = $this->createService('');
        $result = $service->syncCatalog('feed-data', 'csv');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame(['Stripe API key not configured'], $result->getErrorMessages());
    }

    // ==========================================
    // syncAllProducts() - composed flow
    // ==========================================

    public function testSyncAllProductsComposesProductServiceFeedGeneratorAndUpload(): void
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

        $responseBody = json_encode([
            'products_processed' => 2,
            'products_created' => 2,
            'products_updated' => 0,
        ]);

        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->with(
                'https://api.stripe.com/v1/products/import',
                'generated-feed-content',
                $this->anything(),
                30
            )
            ->willReturn(new HttpClientResponse(200, $responseBody));

        $service = $this->createService();
        $result = $service->syncAllProducts();

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(2, $result->getProductsProcessed());
        $this->assertSame(2, $result->getProductsCreated());
    }

    public function testSyncAllProductsWithEmptyApiKeyFailsBeforeHttpCall(): void
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

        $this->httpClient
            ->expects($this->never())
            ->method('post');

        $service = $this->createService('');
        $result = $service->syncAllProducts();

        $this->assertFalse($result->isSuccessful());
    }

    // ==========================================
    // syncCatalog() - malformed JSON response
    // ==========================================

    public function testSyncCatalogWithNonJsonResponseDefaultsToZeroCounts(): void
    {
        $this->httpClient
            ->expects($this->once())
            ->method('post')
            ->willReturn(new HttpClientResponse(200, 'not-valid-json'));

        $service = $this->createService();
        $result = $service->syncCatalog('feed-data', 'csv');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(0, $result->getProductsProcessed());
        $this->assertSame(0, $result->getProductsCreated());
        $this->assertSame(0, $result->getProductsUpdated());
    }
}
