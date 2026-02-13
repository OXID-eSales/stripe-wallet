<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFieldMapperInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\OxidArticleQueryServiceInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\OxidProductService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for OxidProductService.
 *
 * Tests product listing and retrieval via the AcpProductServiceInterface,
 * including filter handling, pagination, and field mapping delegation.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\OxidProductService
 */
class OxidProductServiceTest extends TestCase
{
    private ProductFieldMapperInterface&MockObject $fieldMapper;
    private OxidArticleQueryServiceInterface&MockObject $articleQuery;

    protected function setUp(): void
    {
        $this->fieldMapper = $this->createMock(ProductFieldMapperInterface::class);
        $this->articleQuery = $this->createMock(OxidArticleQueryServiceInterface::class);
    }

    private function createService(): OxidProductService
    {
        return new OxidProductService($this->fieldMapper, $this->articleQuery);
    }

    private function createArticleStub(string $id): object
    {
        $article = $this->getMockBuilder(stdClass::class)
            ->addMethods(['getId'])
            ->getMock();

        $article->method('getId')->willReturn($id);

        return $article;
    }

    // ==========================================
    // Interface compliance
    // ==========================================

    public function testImplementsAcpProductServiceInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(AcpProductServiceInterface::class, $service);
    }

    // ==========================================
    // listProducts - basic operation
    // ==========================================

    public function testListProductsReturnsProductsArray(): void
    {
        $article1 = $this->createArticleStub('art_1');
        $article2 = $this->createArticleStub('art_2');

        $this->articleQuery
            ->method('findArticles')
            ->with(null, null, 20, 0)
            ->willReturn([$article1, $article2]);

        $this->articleQuery
            ->method('countArticles')
            ->with(null, null)
            ->willReturn(2);

        $mappedProduct1 = ['id' => 'art_1', 'title' => 'Article 1'];
        $mappedProduct2 = ['id' => 'art_2', 'title' => 'Article 2'];

        $this->fieldMapper
            ->method('mapProduct')
            ->willReturnMap([
                [$article1, $mappedProduct1],
                [$article2, $mappedProduct2],
            ]);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertSame([$mappedProduct1, $mappedProduct2], $result['products']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }

    public function testListProductsReturnsEmptyArrayWhenNoArticles(): void
    {
        $this->articleQuery->method('findArticles')->willReturn([]);
        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertSame([], $result['products']);
        $this->assertSame(0, $result['total']);
    }

    // ==========================================
    // listProducts - limit and offset
    // ==========================================

    public function testListProductsDefaultLimitIs20(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 20, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertSame(20, $result['limit']);
    }

    public function testListProductsCustomLimit(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 50, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $result = $service->listProducts(['limit' => 50]);

        $this->assertSame(50, $result['limit']);
    }

    public function testListProductsCapsLimitAt100(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 100, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $result = $service->listProducts(['limit' => 500]);

        $this->assertSame(100, $result['limit']);
    }

    public function testListProductsDefaultOffsetIsZero(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 20, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertSame(0, $result['offset']);
    }

    public function testListProductsCustomOffset(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 20, 40)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $result = $service->listProducts(['offset' => 40]);

        $this->assertSame(40, $result['offset']);
    }

    public function testListProductsNegativeOffsetClampedToZero(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 20, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $result = $service->listProducts(['offset' => -10]);

        $this->assertSame(0, $result['offset']);
    }

    // ==========================================
    // listProducts - search filter
    // ==========================================

    public function testListProductsPassesSearchFilter(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with('blue shoes', null, 20, 0)
            ->willReturn([]);

        $this->articleQuery
            ->expects($this->once())
            ->method('countArticles')
            ->with('blue shoes', null)
            ->willReturn(0);

        $service = $this->createService();
        $service->listProducts(['search' => 'blue shoes']);
    }

    public function testListProductsSearchNullWhenNotProvided(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 20, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $service->listProducts();
    }

    public function testListProductsSearchNullWhenNotString(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 20, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $service->listProducts(['search' => 123]);
    }

    // ==========================================
    // listProducts - category filter
    // ==========================================

    public function testListProductsPassesCategoryIdFilter(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, 'cat_shoes', 20, 0)
            ->willReturn([]);

        $this->articleQuery
            ->expects($this->once())
            ->method('countArticles')
            ->with(null, 'cat_shoes')
            ->willReturn(0);

        $service = $this->createService();
        $service->listProducts(['category_id' => 'cat_shoes']);
    }

    public function testListProductsCategoryIdNullWhenNotProvided(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 20, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $service->listProducts();
    }

    public function testListProductsCategoryIdNullWhenNotString(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with(null, null, 20, 0)
            ->willReturn([]);

        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $service->listProducts(['category_id' => 42]);
    }

    // ==========================================
    // listProducts - combined filters
    // ==========================================

    public function testListProductsCombinesAllFilters(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticles')
            ->with('sneakers', 'cat_footwear', 30, 10)
            ->willReturn([]);

        $this->articleQuery
            ->expects($this->once())
            ->method('countArticles')
            ->with('sneakers', 'cat_footwear')
            ->willReturn(0);

        $service = $this->createService();
        $service->listProducts([
            'search' => 'sneakers',
            'category_id' => 'cat_footwear',
            'limit' => 30,
            'offset' => 10,
        ]);
    }

    // ==========================================
    // listProducts - field mapping
    // ==========================================

    public function testListProductsDelegatesFieldMappingToMapper(): void
    {
        $article = $this->createArticleStub('art_map');

        $this->articleQuery->method('findArticles')->willReturn([$article]);
        $this->articleQuery->method('countArticles')->willReturn(1);

        $this->fieldMapper
            ->expects($this->once())
            ->method('mapProduct')
            ->with($article)
            ->willReturn(['id' => 'art_map', 'title' => 'Mapped']);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertSame([['id' => 'art_map', 'title' => 'Mapped']], $result['products']);
    }

    // ==========================================
    // getProduct - found
    // ==========================================

    public function testGetProductReturnsMapppedProductWhenFound(): void
    {
        $article = $this->createArticleStub('art_get');
        $mappedProduct = ['id' => 'art_get', 'title' => 'Found Product'];

        $this->articleQuery
            ->expects($this->once())
            ->method('findArticleById')
            ->with('art_get')
            ->willReturn($article);

        $this->fieldMapper
            ->expects($this->once())
            ->method('mapProduct')
            ->with($article)
            ->willReturn($mappedProduct);

        $service = $this->createService();
        $result = $service->getProduct('art_get');

        $this->assertSame($mappedProduct, $result);
    }

    // ==========================================
    // getProduct - not found
    // ==========================================

    public function testGetProductReturnsNullWhenNotFound(): void
    {
        $this->articleQuery
            ->expects($this->once())
            ->method('findArticleById')
            ->with('nonexistent')
            ->willReturn(null);

        $this->fieldMapper
            ->expects($this->never())
            ->method('mapProduct');

        $service = $this->createService();
        $result = $service->getProduct('nonexistent');

        $this->assertNull($result);
    }

    // ==========================================
    // Response structure
    // ==========================================

    public function testListProductsResponseContainsRequiredKeys(): void
    {
        $this->articleQuery->method('findArticles')->willReturn([]);
        $this->articleQuery->method('countArticles')->willReturn(0);

        $service = $this->createService();
        $result = $service->listProducts();

        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
    }

    public function testListProductsTotalReflectsCountNotFetched(): void
    {
        $article = $this->createArticleStub('art_count');

        // findArticles returns 1 article, but countArticles returns 50 (total available)
        $this->articleQuery->method('findArticles')->willReturn([$article]);
        $this->articleQuery->method('countArticles')->willReturn(50);

        $this->fieldMapper->method('mapProduct')->willReturn(['id' => 'art_count']);

        $service = $this->createService();
        $result = $service->listProducts(['limit' => 1]);

        $this->assertCount(1, $result['products']);
        $this->assertSame(50, $result['total']);
    }
}
