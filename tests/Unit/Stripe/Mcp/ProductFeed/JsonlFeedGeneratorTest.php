<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\ProductFeed;

use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\Payments\Stripe\Mcp\ProductFeed\JsonlFeedGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JsonlFeedGenerator.
 *
 * Tests OpenAI-compatible JSONL feed generation including field mapping,
 * country configuration, eligibility flags, and edge cases.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\ProductFeed\JsonlFeedGenerator
 */
class JsonlFeedGeneratorTest extends TestCase
{
    private function createGenerator(
        string $storeCountry = 'DE',
        string $targetCountries = 'DE,AT,CH'
    ): JsonlFeedGenerator {
        return new JsonlFeedGenerator($storeCountry, $targetCountries);
    }

    /**
     * Create a sample mapped product array.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createProduct(array $overrides = []): array
    {
        return array_merge([
            'id' => 'prod_001',
            'title' => 'Test Product',
            'description' => 'A test product description',
            'url' => 'https://shop.example.com/?cl=details&anid=prod_001',
            'brand' => 'TestBrand',
            'price' => '29.99',
            'currency' => 'EUR',
            'availability' => 'in_stock',
            'image_url' => 'https://shop.example.com/out/pictures/master/product/1/test.jpg',
            'gtin' => '1234567890123',
            'mpn' => 'MPN-001',
            'weight' => 1.5,
            'group_id' => 'parent_001',
        ], $overrides);
    }

    /**
     * Parse JSONL string into array of decoded objects.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseJsonl(string $jsonl): array
    {
        $lines = array_filter(explode("\n", $jsonl), fn (string $line) => $line !== '');
        return array_values(array_map(
            fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines
        ));
    }

    // ==========================================
    // Interface compliance
    // ==========================================

    public function testImplementsProductFeedGeneratorInterface(): void
    {
        $generator = $this->createGenerator();

        $this->assertInstanceOf(ProductFeedGeneratorInterface::class, $generator);
    }

    // ==========================================
    // Empty input
    // ==========================================

    public function testGenerateReturnsEmptyStringForNoProducts(): void
    {
        $generator = $this->createGenerator();

        $this->assertSame('', $generator->generate([]));
    }

    // ==========================================
    // Single product output
    // ==========================================

    public function testGenerateSingleProductReturnsOneJsonLine(): void
    {
        $product = $this->createProduct();
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);
        $this->assertCount(1, $entries);
    }

    public function testGenerateSingleProductMapsFieldsCorrectly(): void
    {
        $product = $this->createProduct();
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);
        $entry = $entries[0];

        $this->assertSame('prod_001', $entry['item_id']);
        $this->assertSame('Test Product', $entry['title']);
        $this->assertSame('A test product description', $entry['description']);
        $this->assertSame('https://shop.example.com/?cl=details&anid=prod_001', $entry['url']);
        $this->assertSame('TestBrand', $entry['brand']);
        $this->assertSame('in_stock', $entry['availability']);
        $this->assertSame(
            'https://shop.example.com/out/pictures/master/product/1/test.jpg',
            $entry['image_url']
        );
    }

    // ==========================================
    // Price formatting with currency
    // ==========================================

    public function testPriceIncludesCurrencyCode(): void
    {
        $product = $this->createProduct(['price' => '49.95', 'currency' => 'EUR']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('49.95 EUR', $entries[0]['price']);
    }

    public function testPriceWithUsdCurrency(): void
    {
        $product = $this->createProduct(['price' => '19.99', 'currency' => 'USD']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('19.99 USD', $entries[0]['price']);
    }

    public function testPriceDefaultsToZeroEurWhenMissing(): void
    {
        $product = ['id' => 'no_price'];
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('0.00 EUR', $entries[0]['price']);
    }

    // ==========================================
    // Country configuration
    // ==========================================

    public function testDefaultStoreCountryIsDE(): void
    {
        $product = $this->createProduct();
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('DE', $entries[0]['store_country']);
    }

    public function testDefaultTargetCountriesIsDEATCH(): void
    {
        $product = $this->createProduct();
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('DE,AT,CH', $entries[0]['target_countries']);
    }

    public function testCustomStoreCountry(): void
    {
        $product = $this->createProduct();
        $generator = $this->createGenerator('US', 'US,CA');
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('US', $entries[0]['store_country']);
    }

    public function testCustomTargetCountries(): void
    {
        $product = $this->createProduct();
        $generator = $this->createGenerator('FR', 'FR,BE,LU');
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('FR,BE,LU', $entries[0]['target_countries']);
    }

    // ==========================================
    // Eligibility flags
    // ==========================================

    public function testIsEligibleSearchAlwaysTrue(): void
    {
        $product = $this->createProduct(['availability' => 'out_of_stock']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertTrue($entries[0]['is_eligible_search']);
    }

    public function testIsEligibleCheckoutTrueWhenInStock(): void
    {
        $product = $this->createProduct(['availability' => 'in_stock']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertTrue($entries[0]['is_eligible_checkout']);
    }

    public function testIsEligibleCheckoutFalseWhenOutOfStock(): void
    {
        $product = $this->createProduct(['availability' => 'out_of_stock']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertFalse($entries[0]['is_eligible_checkout']);
    }

    public function testIsEligibleCheckoutFalseWhenBackorder(): void
    {
        $product = $this->createProduct(['availability' => 'backorder']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertFalse($entries[0]['is_eligible_checkout']);
    }

    public function testIsEligibleCheckoutFalseWhenAvailabilityMissing(): void
    {
        $product = ['id' => 'no_availability'];
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertFalse($entries[0]['is_eligible_checkout']);
    }

    // ==========================================
    // Optional fields (gtin, group_id)
    // ==========================================

    public function testGtinIncludedWhenPresent(): void
    {
        $product = $this->createProduct(['gtin' => '9876543210987']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('9876543210987', $entries[0]['gtin']);
    }

    public function testGtinExcludedWhenEmpty(): void
    {
        $product = $this->createProduct(['gtin' => '']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertArrayNotHasKey('gtin', $entries[0]);
    }

    public function testGtinExcludedWhenNull(): void
    {
        $product = $this->createProduct(['gtin' => null]);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertArrayNotHasKey('gtin', $entries[0]);
    }

    public function testGroupIdIncludedWhenPresent(): void
    {
        $product = $this->createProduct(['group_id' => 'parent_group_1']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('parent_group_1', $entries[0]['group_id']);
    }

    public function testGroupIdExcludedWhenEmpty(): void
    {
        $product = $this->createProduct(['group_id' => '']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertArrayNotHasKey('group_id', $entries[0]);
    }

    public function testGroupIdExcludedWhenNull(): void
    {
        $product = $this->createProduct(['group_id' => null]);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertArrayNotHasKey('group_id', $entries[0]);
    }

    // ==========================================
    // Multiple products
    // ==========================================

    public function testGenerateMultipleProductsReturnsMultipleLines(): void
    {
        $products = [
            $this->createProduct(['id' => 'p1']),
            $this->createProduct(['id' => 'p2']),
            $this->createProduct(['id' => 'p3']),
        ];

        $generator = $this->createGenerator();
        $result = $generator->generate($products);

        $entries = $this->parseJsonl($result);
        $this->assertCount(3, $entries);

        $this->assertSame('p1', $entries[0]['item_id']);
        $this->assertSame('p2', $entries[1]['item_id']);
        $this->assertSame('p3', $entries[2]['item_id']);
    }

    // ==========================================
    // JSONL format compliance
    // ==========================================

    public function testOutputEndsWithNewline(): void
    {
        $product = $this->createProduct();
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $this->assertStringEndsWith("\n", $result);
    }

    public function testEachLineIsValidJson(): void
    {
        $products = [
            $this->createProduct(['id' => 'p1', 'title' => 'Product One']),
            $this->createProduct(['id' => 'p2', 'title' => 'Product Two']),
        ];

        $generator = $this->createGenerator();
        $result = $generator->generate($products);

        $lines = array_filter(explode("\n", $result), fn (string $line) => $line !== '');
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $this->assertNotNull($decoded, "Invalid JSON line: {$line}");
        }
    }

    public function testLinesAreSeparatedByNewline(): void
    {
        $products = [
            $this->createProduct(['id' => 'p1']),
            $this->createProduct(['id' => 'p2']),
        ];

        $generator = $this->createGenerator();
        $result = $generator->generate($products);

        $lines = explode("\n", trim($result));
        $this->assertCount(2, $lines);
    }

    // ==========================================
    // Unicode handling
    // ==========================================

    public function testUnicodeCharactersPreserved(): void
    {
        $product = $this->createProduct([
            'title' => 'Produkt mit Umlauten: aeoeue',
            'description' => 'Beschreibung auf Deutsch',
        ]);

        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('Produkt mit Umlauten: aeoeue', $entries[0]['title']);
    }

    // ==========================================
    // Default values for missing fields
    // ==========================================

    public function testDefaultAvailabilityIsOutOfStock(): void
    {
        $product = ['id' => 'no_avail_prod'];
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('out_of_stock', $entries[0]['availability']);
    }

    public function testMissingFieldsDefaultToEmptyString(): void
    {
        $product = ['id' => 'minimal'];
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertSame('minimal', $entries[0]['item_id']);
        $this->assertSame('', $entries[0]['title']);
        $this->assertSame('', $entries[0]['description']);
        $this->assertSame('', $entries[0]['url']);
        $this->assertSame('', $entries[0]['brand']);
        $this->assertSame('', $entries[0]['image_url']);
    }

    // ==========================================
    // Content type and file extension
    // ==========================================

    public function testGetContentTypeReturnsJsonlMimeType(): void
    {
        $generator = $this->createGenerator();

        $this->assertSame('application/x-jsonlines; charset=utf-8', $generator->getContentType());
    }

    public function testGetFileExtensionReturnsJsonl(): void
    {
        $generator = $this->createGenerator();

        $this->assertSame('jsonl', $generator->getFileExtension());
    }

    // ==========================================
    // Field naming (internal → JSONL)
    // ==========================================

    public function testIdMappedToItemId(): void
    {
        $product = $this->createProduct(['id' => 'test_id_map']);
        $generator = $this->createGenerator();
        $result = $generator->generate([$product]);

        $entries = $this->parseJsonl($result);

        $this->assertArrayHasKey('item_id', $entries[0]);
        $this->assertArrayNotHasKey('id', $entries[0]);
        $this->assertSame('test_id_map', $entries[0]['item_id']);
    }
}
