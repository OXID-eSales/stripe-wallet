<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\Payments\Stripe\Mcp\Service\GraphqlResponseMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GraphqlResponseMapper.
 *
 * Tests mapping of OXID GraphQL product responses to ACP product format,
 * including field extraction, truncation, availability, and image handling.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\GraphqlResponseMapper
 */
class GraphqlResponseMapperTest extends TestCase
{
    private function createMapper(): GraphqlResponseMapper
    {
        return new GraphqlResponseMapper();
    }

    private function createFullProduct(): array
    {
        return [
            'id' => 'prod_001',
            'title' => 'Blue Running Shoes',
            'shortDescription' => 'Comfortable running shoes',
            'longDescription' => '<p>High-quality <strong>running</strong> shoes for all terrains.</p>',
            'price' => [
                'price' => 49.99,
                'currency' => [
                    'name' => 'EUR',
                    'sign' => '€',
                ],
            ],
            'imageGallery' => [
                'icon' => 'https://shop.local/icon.jpg',
                'thumb' => 'https://shop.local/thumb.jpg',
                'images' => [
                    ['image' => 'https://shop.local/full.jpg'],
                    ['image' => 'https://shop.local/full2.jpg'],
                ],
            ],
            'manufacturer' => [
                'title' => 'Nike',
            ],
            'category' => [
                'title' => 'Running',
            ],
            'seo' => [
                'url' => 'https://shop.local/Running/Blue-Running-Shoes.html',
            ],
            'rating' => 4.5,
            'stock' => 15,
        ];
    }

    // ==========================================
    // mapProductListResponse - basic operation
    // ==========================================

    public function testMapProductListResponseReturnsCorrectStructure(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapProductListResponse(
            ['products' => [$this->createFullProduct()]],
            []
        );

        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('limit', $result);
        $this->assertArrayHasKey('offset', $result);
    }

    public function testMapProductListResponseMapsAllProducts(): void
    {
        $mapper = $this->createMapper();
        $product1 = $this->createFullProduct();
        $product2 = $this->createFullProduct();
        $product2['id'] = 'prod_002';
        $product2['title'] = 'Red Sneakers';

        $result = $mapper->mapProductListResponse(
            ['products' => [$product1, $product2]],
            []
        );

        $this->assertCount(2, $result['products']);
        $this->assertSame('prod_001', $result['products'][0]['id']);
        $this->assertSame('prod_002', $result['products'][1]['id']);
    }

    public function testMapProductListResponseDefaultPagination(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapProductListResponse(['products' => []], []);

        $this->assertSame(20, $result['limit']);
        $this->assertSame(0, $result['offset']);
    }

    public function testMapProductListResponseCustomPagination(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapProductListResponse(
            ['products' => []],
            ['limit' => 50, 'offset' => 10]
        );

        $this->assertSame(50, $result['limit']);
        $this->assertSame(10, $result['offset']);
    }

    public function testMapProductListResponseEmptyProducts(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapProductListResponse(['products' => []], []);

        $this->assertSame([], $result['products']);
        $this->assertSame(0, $result['total']);
    }

    public function testMapProductListResponseMissingProductsKey(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapProductListResponse([], []);

        $this->assertSame([], $result['products']);
        $this->assertSame(0, $result['total']);
    }

    public function testMapProductListResponseNonArrayProducts(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapProductListResponse(['products' => 'invalid'], []);

        $this->assertSame([], $result['products']);
        $this->assertSame(0, $result['total']);
    }

    public function testMapProductListResponseTotalReflectsReturnedCount(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapProductListResponse(
            ['products' => [$this->createFullProduct(), $this->createFullProduct()]],
            []
        );

        $this->assertSame(2, $result['total']);
    }

    // ==========================================
    // mapSingleProductResponse
    // ==========================================

    public function testMapSingleProductResponseReturnsProduct(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertNotNull($result);
        $this->assertSame('prod_001', $result['id']);
    }

    public function testMapSingleProductResponseReturnsNullWhenMissing(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse([]);

        $this->assertNull($result);
    }

    public function testMapSingleProductResponseReturnsNullWhenNotArray(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => 'invalid']);

        $this->assertNull($result);
    }

    // ==========================================
    // Field mapping - core fields
    // ==========================================

    public function testMapProductExtractsId(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame('prod_001', $result['id']);
    }

    public function testMapProductExtractsTitle(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame('Blue Running Shoes', $result['title']);
    }

    public function testMapProductTruncatesTitleAt150Chars(): void
    {
        $product = $this->createFullProduct();
        $product['title'] = str_repeat('A', 200);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame(150, mb_strlen($result['title']));
        $this->assertStringEndsWith('...', $result['title']);
    }

    public function testMapProductStripsHtmlFromDescription(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertStringNotContainsString('<p>', $result['description']);
        $this->assertStringNotContainsString('<strong>', $result['description']);
        $this->assertStringContainsString('running', $result['description']);
    }

    public function testMapProductTruncatesDescriptionAt5000Chars(): void
    {
        $product = $this->createFullProduct();
        $product['longDescription'] = str_repeat('B', 6000);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame(5000, mb_strlen($result['description']));
        $this->assertStringEndsWith('...', $result['description']);
    }

    public function testMapProductUsesShortDescriptionWhenLongMissing(): void
    {
        $product = $this->createFullProduct();
        unset($product['longDescription']);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('Comfortable running shoes', $result['description']);
    }

    // ==========================================
    // Field mapping - price and currency
    // ==========================================

    public function testMapProductFormatsPrice(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame('49.99', $result['price']);
    }

    public function testMapProductFormatsPriceWithTwoDecimals(): void
    {
        $product = $this->createFullProduct();
        $product['price']['price'] = 10;

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('10.00', $result['price']);
    }

    public function testMapProductExtractsCurrency(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame('EUR', $result['currency']);
    }

    public function testMapProductDefaultsCurrencyToEur(): void
    {
        $product = $this->createFullProduct();
        unset($product['price']['currency']);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('EUR', $result['currency']);
    }

    public function testMapProductHandlesMissingPrice(): void
    {
        $product = $this->createFullProduct();
        unset($product['price']);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('0.00', $result['price']);
    }

    // ==========================================
    // Field mapping - availability
    // ==========================================

    public function testMapProductInStockWhenStockPositive(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame('in_stock', $result['availability']);
    }

    public function testMapProductOutOfStockWhenStockZero(): void
    {
        $product = $this->createFullProduct();
        $product['stock'] = 0;

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('out_of_stock', $result['availability']);
    }

    public function testMapProductOutOfStockWhenStockMissing(): void
    {
        $product = $this->createFullProduct();
        unset($product['stock']);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('out_of_stock', $result['availability']);
    }

    // ==========================================
    // Field mapping - images
    // ==========================================

    public function testMapProductExtractsPrimaryImage(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame('https://shop.local/full.jpg', $result['image_url']);
    }

    public function testMapProductFallsBackToThumb(): void
    {
        $product = $this->createFullProduct();
        $product['imageGallery']['images'] = [];

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('https://shop.local/thumb.jpg', $result['image_url']);
    }

    public function testMapProductFallsBackToIcon(): void
    {
        $product = $this->createFullProduct();
        $product['imageGallery']['images'] = [];
        $product['imageGallery']['thumb'] = '';

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('https://shop.local/icon.jpg', $result['image_url']);
    }

    public function testMapProductEmptyImageWhenGalleryMissing(): void
    {
        $product = $this->createFullProduct();
        unset($product['imageGallery']);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('', $result['image_url']);
    }

    // ==========================================
    // Field mapping - brand, category, SEO, rating
    // ==========================================

    public function testMapProductExtractsBrand(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame('Nike', $result['brand']);
    }

    public function testMapProductEmptyBrandWhenManufacturerMissing(): void
    {
        $product = $this->createFullProduct();
        unset($product['manufacturer']);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('', $result['brand']);
    }

    public function testMapProductExtractsCategory(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame('Running', $result['category']);
    }

    public function testMapProductNullCategoryWhenMissing(): void
    {
        $product = $this->createFullProduct();
        unset($product['category']);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertNull($result['category']);
    }

    public function testMapProductExtractsSeoUrl(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame(
            'https://shop.local/Running/Blue-Running-Shoes.html',
            $result['seo_url']
        );
    }

    public function testMapProductSeoUrlUsedAsUrl(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame($result['seo_url'], $result['url']);
    }

    public function testMapProductExtractsRating(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertSame(4.5, $result['rating']);
    }

    public function testMapProductNullRatingWhenMissing(): void
    {
        $product = $this->createFullProduct();
        unset($product['rating']);

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertNull($result['rating']);
    }

    // ==========================================
    // Field mapping - null fields (GTIN, MPN, weight, group_id)
    // ==========================================

    public function testMapProductGtinIsNull(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertNull($result['gtin']);
    }

    public function testMapProductMpnIsNull(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertNull($result['mpn']);
    }

    public function testMapProductWeightIsNull(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertNull($result['weight']);
    }

    public function testMapProductGroupIdIsNull(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(
            ['product' => $this->createFullProduct()]
        );

        $this->assertNull($result['group_id']);
    }

    // ==========================================
    // Edge cases - minimal product
    // ==========================================

    public function testMapProductMinimalDataNoErrors(): void
    {
        $product = ['id' => 'min_001'];

        $mapper = $this->createMapper();
        $result = $mapper->mapSingleProductResponse(['product' => $product]);

        $this->assertSame('min_001', $result['id']);
        $this->assertSame('', $result['title']);
        $this->assertSame('', $result['description']);
        $this->assertSame('0.00', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('out_of_stock', $result['availability']);
    }
}
