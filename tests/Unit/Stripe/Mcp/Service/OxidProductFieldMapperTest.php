<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Manufacturer;
use OxidEsales\Eshop\Core\Price;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFieldMapperInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\OxidProductFieldMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OxidProductFieldMapper.
 *
 * Tests field mapping from OXID article objects to ACP product fields,
 * including truncation, availability mapping, image URL resolution,
 * and price formatting.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\OxidProductFieldMapper
 */
class OxidProductFieldMapperTest extends TestCase
{
    private ShopAdapterInterface&MockObject $shopAdapter;

    protected function setUp(): void
    {
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopUrl')->willReturn('https://shop.example.com/');
        $this->shopAdapter->method('getShopCurrency')->willReturn('EUR');
    }

    private function createMapper(): OxidProductFieldMapper
    {
        return new OxidProductFieldMapper($this->shopAdapter);
    }

    /**
     * Create a mock article object with the given properties.
     *
     * @param array<string, mixed> $overrides
     */
    private function createArticleMock(array $overrides = []): Article&MockObject
    {
        $defaults = [
            'id' => 'art_001',
            'title' => 'Test Product',
            'longDescription' => 'A detailed product description',
            'manufacturer' => null,
            'bruttoPrice' => 29.99,
            'oxean' => '4006381333931',
            'oxmpn' => 'MPN-123',
            'oxstock' => '10',
            'oxstockflag' => '1',
            'oxpic1' => 'product_1.jpg',
            'weight' => 1.5,
            'parentId' => null,
        ];

        $data = array_merge($defaults, $overrides);

        $price = $this->createPriceMock($data['bruttoPrice']);
        $manufacturer = $data['manufacturer'];

        $article = $this->createMock(Article::class);

        $article->method('getId')->willReturn($data['id']);
        $article->method('getManufacturer')->willReturn($manufacturer);
        $article->method('getPrice')->willReturn($price);
        $article->method('getWeight')->willReturn($data['weight']);
        $article->method('getParentId')->willReturn($data['parentId']);

        $fieldDataMap = [
            ['oxtitle', $data['title']],
            ['oxlongdesc', $data['longDescription']],
            ['oxean', $data['oxean']],
            ['oxmpn', $data['oxmpn']],
            ['oxstock', $data['oxstock']],
            ['oxstockflag', $data['oxstockflag']],
            ['oxpic1', $data['oxpic1']],
        ];
        $article->method('getFieldData')->willReturnMap($fieldDataMap);

        return $article;
    }

    private function createPriceMock(float $bruttoPrice): Price&MockObject
    {
        $price = $this->createMock(Price::class);
        $price->method('getBruttoPrice')->willReturn($bruttoPrice);

        return $price;
    }

    private function createManufacturerMock(string $title): Manufacturer&MockObject
    {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getFieldData')
            ->willReturnMap([['oxtitle', $title]]);

        return $manufacturer;
    }

    // ==========================================
    // Interface compliance
    // ==========================================

    public function testImplementsProductFieldMapperInterface(): void
    {
        $mapper = $this->createMapper();

        $this->assertInstanceOf(ProductFieldMapperInterface::class, $mapper);
    }

    // ==========================================
    // mapProduct - all required fields
    // ==========================================

    public function testMapsAllRequiredFieldsCorrectly(): void
    {
        $manufacturer = $this->createManufacturerMock('Acme Corp');
        $article = $this->createArticleMock([
            'id' => 'art_full',
            'title' => 'Full Product',
            'longDescription' => 'Full description of the product',
            'manufacturer' => $manufacturer,
            'bruttoPrice' => 49.95,
            'oxean' => '1234567890123',
            'oxmpn' => 'ACME-001',
            'oxstock' => '5',
            'oxstockflag' => '1',
            'oxpic1' => 'full_product.jpg',
            'weight' => 2.3,
            'parentId' => 'parent_001',
        ]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('art_full', $result['id']);
        $this->assertSame('Full Product', $result['title']);
        $this->assertSame('Full description of the product', $result['description']);
        $this->assertSame('https://shop.example.com/?cl=details&anid=art_full', $result['url']);
        $this->assertSame('Acme Corp', $result['brand']);
        $this->assertSame('49.95', $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('in_stock', $result['availability']);
        $this->assertSame(
            'https://shop.example.com/out/pictures/master/product/1/full_product.jpg',
            $result['image_url']
        );
        $this->assertSame('1234567890123', $result['gtin']);
        $this->assertSame('ACME-001', $result['mpn']);
        $this->assertSame(2.3, $result['weight']);
        $this->assertSame('parent_001', $result['group_id']);
    }

    public function testMapProductReturnsAllExpectedKeys(): void
    {
        $article = $this->createArticleMock();

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $expectedKeys = [
            'id', 'title', 'description', 'url', 'brand', 'price',
            'currency', 'availability', 'image_url', 'gtin', 'mpn',
            'weight', 'group_id',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function testMapProductReturnsEmptyArrayForNonArticle(): void
    {
        $mapper = $this->createMapper();
        $result = $mapper->mapProduct(new \stdClass());

        $this->assertSame([], $result);
    }

    // ==========================================
    // Title truncation
    // ==========================================

    public function testTruncatesTitleAt150Chars(): void
    {
        $longTitle = str_repeat('A', 200);
        $article = $this->createArticleMock(['title' => $longTitle]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame(150, mb_strlen($result['title']));
        $this->assertStringEndsWith('...', $result['title']);
        $this->assertStringStartsWith(str_repeat('A', 147), $result['title']);
    }

    public function testDoesNotTruncateTitleAtExactly150Chars(): void
    {
        $exactTitle = str_repeat('B', 150);
        $article = $this->createArticleMock(['title' => $exactTitle]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame($exactTitle, $result['title']);
        $this->assertSame(150, mb_strlen($result['title']));
    }

    public function testDoesNotTruncateShortTitle(): void
    {
        $shortTitle = 'Short';
        $article = $this->createArticleMock(['title' => $shortTitle]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('Short', $result['title']);
    }

    // ==========================================
    // Description truncation and HTML stripping
    // ==========================================

    public function testTruncatesDescriptionAt5000Chars(): void
    {
        $longDesc = str_repeat('D', 6000);
        $article = $this->createArticleMock(['longDescription' => $longDesc]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame(5000, mb_strlen($result['description']));
        $this->assertStringEndsWith('...', $result['description']);
    }

    public function testDoesNotTruncateDescriptionAtExactly5000Chars(): void
    {
        $exactDesc = str_repeat('E', 5000);
        $article = $this->createArticleMock(['longDescription' => $exactDesc]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame($exactDesc, $result['description']);
    }

    public function testStripsHtmlTagsFromDescription(): void
    {
        $htmlDesc = '<p>This is a <strong>bold</strong> description with <a href="#">links</a>.</p>';
        $article = $this->createArticleMock(['longDescription' => $htmlDesc]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('This is a bold description with links.', $result['description']);
    }

    public function testStripsHtmlBeforeTruncation(): void
    {
        // HTML that makes it over 5000 chars but plain text is under
        $htmlDesc = '<div>' . str_repeat('<b>X</b>', 1000) . '</div>';
        $article = $this->createArticleMock(['longDescription' => $htmlDesc]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        // After stripping tags, only 1000 'X' characters remain, which is < 5000
        $this->assertSame(str_repeat('X', 1000), $result['description']);
    }

    // ==========================================
    // Availability mapping
    // ==========================================

    public function testAvailabilityInStockWhenStockGreaterThanZero(): void
    {
        $article = $this->createArticleMock(['oxstock' => '10', 'oxstockflag' => '1']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('in_stock', $result['availability']);
    }

    public function testAvailabilityInStockWhenStockIsOne(): void
    {
        $article = $this->createArticleMock(['oxstock' => '1', 'oxstockflag' => '1']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('in_stock', $result['availability']);
    }

    public function testAvailabilityOutOfStockWhenStockZeroAndFlag1(): void
    {
        $article = $this->createArticleMock(['oxstock' => '0', 'oxstockflag' => '1']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('out_of_stock', $result['availability']);
    }

    public function testAvailabilityBackorderWhenStockZeroAndFlag4(): void
    {
        $article = $this->createArticleMock(['oxstock' => '0', 'oxstockflag' => '4']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('backorder', $result['availability']);
    }

    public function testAvailabilityOutOfStockForUnknownFlag(): void
    {
        $article = $this->createArticleMock(['oxstock' => '0', 'oxstockflag' => '2']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('out_of_stock', $result['availability']);
    }

    public function testAvailabilityOutOfStockWhenStockNegative(): void
    {
        $article = $this->createArticleMock(['oxstock' => '-5', 'oxstockflag' => '1']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('out_of_stock', $result['availability']);
    }

    // ==========================================
    // Image URL resolution
    // ==========================================

    public function testImageUrlResolvesRelativePathToAbsolute(): void
    {
        $article = $this->createArticleMock(['oxpic1' => 'product_image.jpg']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame(
            'https://shop.example.com/out/pictures/master/product/1/product_image.jpg',
            $result['image_url']
        );
    }

    public function testImageUrlKeepsAbsoluteUrl(): void
    {
        $absoluteUrl = 'https://cdn.example.com/images/product.jpg';
        $article = $this->createArticleMock(['oxpic1' => $absoluteUrl]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame($absoluteUrl, $result['image_url']);
    }

    public function testImageUrlKeepsHttpAbsoluteUrl(): void
    {
        $httpUrl = 'http://cdn.example.com/images/product.jpg';
        $article = $this->createArticleMock(['oxpic1' => $httpUrl]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame($httpUrl, $result['image_url']);
    }

    public function testImageUrlReturnsEmptyStringWhenNoPicture(): void
    {
        $article = $this->createArticleMock(['oxpic1' => '']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('', $result['image_url']);
    }

    public function testImageUrlHandlesShopUrlWithTrailingSlash(): void
    {
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopUrl')->willReturn('https://shop.example.com/');
        $this->shopAdapter->method('getShopCurrency')->willReturn('EUR');

        $article = $this->createArticleMock(['oxpic1' => 'pic.jpg']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame(
            'https://shop.example.com/out/pictures/master/product/1/pic.jpg',
            $result['image_url']
        );
    }

    public function testImageUrlHandlesShopUrlWithoutTrailingSlash(): void
    {
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopUrl')->willReturn('https://shop.example.com');
        $this->shopAdapter->method('getShopCurrency')->willReturn('EUR');

        $article = $this->createArticleMock(['oxpic1' => 'pic.jpg']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame(
            'https://shop.example.com/out/pictures/master/product/1/pic.jpg',
            $result['image_url']
        );
    }

    // ==========================================
    // Price formatting
    // ==========================================

    public function testPriceFormattedWithTwoDecimalPlaces(): void
    {
        $article = $this->createArticleMock(['bruttoPrice' => 29.99]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('29.99', $result['price']);
    }

    public function testPriceFormattedForWholeNumber(): void
    {
        $article = $this->createArticleMock(['bruttoPrice' => 100.0]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('100.00', $result['price']);
    }

    public function testPriceFormattedForZero(): void
    {
        $article = $this->createArticleMock(['bruttoPrice' => 0.0]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('0.00', $result['price']);
    }

    public function testPriceFormattedWithExtraDecimals(): void
    {
        $article = $this->createArticleMock(['bruttoPrice' => 19.999]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('20.00', $result['price']);
    }

    public function testPriceFormattedWithoutThousandsSeparator(): void
    {
        $article = $this->createArticleMock(['bruttoPrice' => 1234.56]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('1234.56', $result['price']);
    }

    // ==========================================
    // Brand / manufacturer
    // ==========================================

    public function testNullManufacturerReturnsEmptyBrand(): void
    {
        $article = $this->createArticleMock(['manufacturer' => null]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('', $result['brand']);
    }

    public function testManufacturerTitleMappedToBrand(): void
    {
        $manufacturer = $this->createManufacturerMock('Nike');
        $article = $this->createArticleMock(['manufacturer' => $manufacturer]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('Nike', $result['brand']);
    }

    // ==========================================
    // Optional fields (gtin, mpn, weight, group_id)
    // ==========================================

    public function testGtinReturnedFromOxean(): void
    {
        $article = $this->createArticleMock(['oxean' => '4006381333931']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('4006381333931', $result['gtin']);
    }

    public function testGtinNullWhenOxeanEmpty(): void
    {
        $article = $this->createArticleMock(['oxean' => '']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertNull($result['gtin']);
    }

    public function testMpnReturnedFromOxmpn(): void
    {
        $article = $this->createArticleMock(['oxmpn' => 'MPN-456']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('MPN-456', $result['mpn']);
    }

    public function testMpnNullWhenOxmpnEmpty(): void
    {
        $article = $this->createArticleMock(['oxmpn' => '']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertNull($result['mpn']);
    }

    public function testWeightReturnedWhenPositive(): void
    {
        $article = $this->createArticleMock(['weight' => 3.5]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame(3.5, $result['weight']);
    }

    public function testWeightNullWhenZero(): void
    {
        $article = $this->createArticleMock(['weight' => 0.0]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertNull($result['weight']);
    }

    public function testGroupIdReturnedWhenParentIdSet(): void
    {
        $article = $this->createArticleMock(['parentId' => 'parent_abc']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('parent_abc', $result['group_id']);
    }

    public function testGroupIdNullWhenNoParentId(): void
    {
        $article = $this->createArticleMock(['parentId' => null]);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertNull($result['group_id']);
    }

    public function testGroupIdNullWhenParentIdEmptyString(): void
    {
        $article = $this->createArticleMock(['parentId' => '']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertNull($result['group_id']);
    }

    // ==========================================
    // URL construction
    // ==========================================

    public function testUrlContainsProductId(): void
    {
        $article = $this->createArticleMock(['id' => 'art_url_test']);

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertStringContainsString('anid=art_url_test', $result['url']);
    }

    public function testUrlUsesShopUrl(): void
    {
        $article = $this->createArticleMock();

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertStringStartsWith('https://shop.example.com/', $result['url']);
    }

    // ==========================================
    // Currency
    // ==========================================

    public function testCurrencyFromShopAdapter(): void
    {
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopUrl')->willReturn('https://shop.test.com');
        $this->shopAdapter->method('getShopCurrency')->willReturn('USD');

        $article = $this->createArticleMock();

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);

        $this->assertSame('USD', $result['currency']);
    }

    // ==========================================
    // getFieldNames
    // ==========================================

    public function testGetFieldNamesReturnsExpectedList(): void
    {
        $mapper = $this->createMapper();
        $fieldNames = $mapper->getFieldNames();

        $expected = [
            'id', 'title', 'description', 'url', 'brand', 'price',
            'currency', 'availability', 'image_url', 'gtin', 'mpn',
            'weight', 'group_id',
        ];

        $this->assertSame($expected, $fieldNames);
    }

    public function testGetFieldNamesMatchesMapProductKeys(): void
    {
        $article = $this->createArticleMock();

        $mapper = $this->createMapper();
        $result = $mapper->mapProduct($article);
        $fieldNames = $mapper->getFieldNames();

        $this->assertSame($fieldNames, array_keys($result));
    }
}
