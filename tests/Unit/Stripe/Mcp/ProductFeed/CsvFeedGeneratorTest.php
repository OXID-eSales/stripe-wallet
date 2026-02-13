<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\ProductFeed;

use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;
use OxidEsales\Payments\Stripe\Mcp\ProductFeed\CsvFeedGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CsvFeedGenerator.
 *
 * Tests Stripe-format CSV generation including header mapping,
 * field ordering, content type, and edge cases.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\ProductFeed\CsvFeedGenerator
 */
class CsvFeedGeneratorTest extends TestCase
{
    private function createGenerator(): CsvFeedGenerator
    {
        return new CsvFeedGenerator();
    }

    /**
     * Create a sample product data array as returned by OxidProductFieldMapper.
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
            'group_id' => null,
        ], $overrides);
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
    // CSV header
    // ==========================================

    public function testGenerateOutputsStripeHeaderRow(): void
    {
        $generator = $this->createGenerator();
        $csv = $generator->generate([]);

        $lines = explode("\n", trim($csv));
        $header = str_getcsv($lines[0]);

        $expectedHeader = [
            'ID', 'Title', 'Description', 'Link', 'Brand',
            'Price', 'Availability', 'image_link', 'GTIN', 'MPN', 'item_group_id',
        ];

        $this->assertSame($expectedHeader, $header);
    }

    public function testGenerateEmptyProductsReturnsOnlyHeader(): void
    {
        $generator = $this->createGenerator();
        $csv = $generator->generate([]);

        $lines = array_filter(explode("\n", $csv), fn (string $line) => $line !== '');

        $this->assertCount(1, $lines);
    }

    // ==========================================
    // CSV data rows
    // ==========================================

    public function testGenerateSingleProduct(): void
    {
        $product = $this->createProduct();
        $generator = $this->createGenerator();
        $csv = $generator->generate([$product]);

        $lines = explode("\n", trim($csv));
        $this->assertCount(2, $lines);

        $dataRow = str_getcsv($lines[1]);

        $this->assertSame('prod_001', $dataRow[0]);
        $this->assertSame('Test Product', $dataRow[1]);
        $this->assertSame('A test product description', $dataRow[2]);
        $this->assertSame('https://shop.example.com/?cl=details&anid=prod_001', $dataRow[3]);
        $this->assertSame('TestBrand', $dataRow[4]);
        $this->assertSame('29.99', $dataRow[5]);
        $this->assertSame('in_stock', $dataRow[6]);
        $this->assertSame(
            'https://shop.example.com/out/pictures/master/product/1/test.jpg',
            $dataRow[7]
        );
        $this->assertSame('1234567890123', $dataRow[8]);
        $this->assertSame('MPN-001', $dataRow[9]);
    }

    public function testGenerateMultipleProducts(): void
    {
        $products = [
            $this->createProduct(['id' => 'prod_a', 'title' => 'Product A']),
            $this->createProduct(['id' => 'prod_b', 'title' => 'Product B']),
            $this->createProduct(['id' => 'prod_c', 'title' => 'Product C']),
        ];

        $generator = $this->createGenerator();
        $csv = $generator->generate($products);

        $lines = explode("\n", trim($csv));
        $this->assertCount(4, $lines); // 1 header + 3 data rows

        $row1 = str_getcsv($lines[1]);
        $row2 = str_getcsv($lines[2]);
        $row3 = str_getcsv($lines[3]);

        $this->assertSame('prod_a', $row1[0]);
        $this->assertSame('prod_b', $row2[0]);
        $this->assertSame('prod_c', $row3[0]);
    }

    // ==========================================
    // Field mapping (internal name → Stripe name)
    // ==========================================

    public function testFieldMappingUsesStripeColumnNames(): void
    {
        $generator = $this->createGenerator();
        $csv = $generator->generate([]);

        $lines = explode("\n", trim($csv));
        $header = str_getcsv($lines[0]);

        // Verify specific Stripe field name mappings
        $this->assertContains('ID', $header);
        $this->assertContains('Link', $header);       // 'url' → 'Link'
        $this->assertContains('image_link', $header);  // 'image_url' → 'image_link'
        $this->assertContains('item_group_id', $header); // 'group_id' → 'item_group_id'
    }

    // ==========================================
    // Null and missing fields
    // ==========================================

    public function testNullFieldsRenderedAsEmptyString(): void
    {
        $product = $this->createProduct([
            'gtin' => null,
            'mpn' => null,
            'weight' => null,
            'group_id' => null,
        ]);

        $generator = $this->createGenerator();
        $csv = $generator->generate([$product]);

        $lines = explode("\n", trim($csv));
        $dataRow = str_getcsv($lines[1]);

        // gtin (index 8), mpn (index 9), item_group_id (index 10)
        $this->assertSame('', $dataRow[8]);
        $this->assertSame('', $dataRow[9]);
        $this->assertSame('', $dataRow[10]);
    }

    public function testMissingFieldsRenderedAsEmptyString(): void
    {
        // Product with only 'id' field set - all others missing
        $product = ['id' => 'minimal_prod'];

        $generator = $this->createGenerator();
        $csv = $generator->generate([$product]);

        $lines = explode("\n", trim($csv));
        $dataRow = str_getcsv($lines[1]);

        $this->assertSame('minimal_prod', $dataRow[0]);
        // All other fields should be empty string
        for ($i = 1; $i < count($dataRow); $i++) {
            $this->assertSame('', $dataRow[$i], "Field at index {$i} should be empty");
        }
    }

    // ==========================================
    // CSV escaping
    // ==========================================

    public function testCsvEscapesCommasInFields(): void
    {
        $product = $this->createProduct([
            'title' => 'Product, with commas',
            'description' => 'Description, also, has, commas',
        ]);

        $generator = $this->createGenerator();
        $csv = $generator->generate([$product]);

        $lines = explode("\n", trim($csv));
        $dataRow = str_getcsv($lines[1]);

        $this->assertSame('Product, with commas', $dataRow[1]);
        $this->assertSame('Description, also, has, commas', $dataRow[2]);
    }

    public function testCsvEscapesQuotesInFields(): void
    {
        $product = $this->createProduct([
            'title' => 'Product with "quotes"',
        ]);

        $generator = $this->createGenerator();
        $csv = $generator->generate([$product]);

        $lines = explode("\n", trim($csv));
        $dataRow = str_getcsv($lines[1]);

        $this->assertSame('Product with "quotes"', $dataRow[1]);
    }

    public function testCsvEscapesNewlinesInFields(): void
    {
        $product = $this->createProduct([
            'description' => "Line one\nLine two",
        ]);

        $generator = $this->createGenerator();
        $csv = $generator->generate([$product]);

        // Parse full CSV, as newlines inside quoted fields span multiple raw lines
        $parsed = array_map('str_getcsv', explode("\n", trim($csv)));
        // After parsing, the description should still contain the newline
        // We parse the full CSV content properly
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream);
        $dataRow = fgetcsv($stream);
        fclose($stream);

        $this->assertSame("Line one\nLine two", $dataRow[2]);
    }

    // ==========================================
    // Excluded fields (weight, currency not in Stripe CSV)
    // ==========================================

    public function testCsvExcludesWeightAndCurrencyFromStripeMap(): void
    {
        $generator = $this->createGenerator();
        $csv = $generator->generate([]);

        $lines = explode("\n", trim($csv));
        $header = str_getcsv($lines[0]);

        // weight and currency are not in the STRIPE_FIELD_MAP
        $this->assertNotContains('weight', $header);
        $this->assertNotContains('Weight', $header);
        $this->assertNotContains('currency', $header);
        $this->assertNotContains('Currency', $header);
    }

    // ==========================================
    // Content type and file extension
    // ==========================================

    public function testGetContentTypeReturnsCsvMimeType(): void
    {
        $generator = $this->createGenerator();

        $this->assertSame('text/csv; charset=utf-8', $generator->getContentType());
    }

    public function testGetFileExtensionReturnsCsv(): void
    {
        $generator = $this->createGenerator();

        $this->assertSame('csv', $generator->getFileExtension());
    }

    // ==========================================
    // Output is valid CSV
    // ==========================================

    public function testOutputIsParsableAsCsv(): void
    {
        $products = [
            $this->createProduct(['id' => 'p1']),
            $this->createProduct(['id' => 'p2']),
        ];

        $generator = $this->createGenerator();
        $csv = $generator->generate($products);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        $this->assertCount(3, $rows); // header + 2 data
        $this->assertSame('ID', $rows[0][0]);
        $this->assertSame('p1', $rows[1][0]);
        $this->assertSame('p2', $rows[2][0]);
    }

    public function testGenerateReturnsStringType(): void
    {
        $generator = $this->createGenerator();
        $result = $generator->generate([]);

        $this->assertIsString($result);
    }
}
