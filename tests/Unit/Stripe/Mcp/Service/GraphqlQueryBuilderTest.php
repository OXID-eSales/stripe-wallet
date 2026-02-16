<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\Payments\Stripe\Mcp\Service\GraphqlQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GraphqlQueryBuilder.
 *
 * Tests GraphQL query construction for OXID's graphql-storefront product schema,
 * including filter handling, pagination, sorting, and input sanitization.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\GraphqlQueryBuilder
 */
class GraphqlQueryBuilderTest extends TestCase
{
    private function createBuilder(): GraphqlQueryBuilder
    {
        return new GraphqlQueryBuilder();
    }

    // ==========================================
    // buildProductsQuery - default behavior
    // ==========================================

    public function testBuildProductsQueryReturnsValidGraphqlString(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery([]);

        $this->assertStringContainsString('products(', $query);
        $this->assertStringContainsString('pagination:', $query);
    }

    public function testBuildProductsQueryIncludesProductFields(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery([]);

        $this->assertStringContainsString('id', $query);
        $this->assertStringContainsString('title', $query);
        $this->assertStringContainsString('shortDescription', $query);
        $this->assertStringContainsString('longDescription', $query);
        $this->assertStringContainsString('price {', $query);
        $this->assertStringContainsString('imageGallery {', $query);
        $this->assertStringContainsString('manufacturer {', $query);
        $this->assertStringContainsString('seo {', $query);
        $this->assertStringContainsString('rating', $query);
        $this->assertStringContainsString('stock', $query);
    }

    // ==========================================
    // buildProductsQuery - pagination
    // ==========================================

    public function testBuildProductsQueryDefaultPagination(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery([]);

        $this->assertStringContainsString('offset: 0', $query);
        $this->assertStringContainsString('limit: 20', $query);
    }

    public function testBuildProductsQueryCustomPagination(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['limit' => 50, 'offset' => 10]);

        $this->assertStringContainsString('offset: 10', $query);
        $this->assertStringContainsString('limit: 50', $query);
    }

    public function testBuildProductsQueryCapsLimitAt100(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['limit' => 500]);

        $this->assertStringContainsString('limit: 100', $query);
    }

    public function testBuildProductsQueryClampsNegativeOffsetToZero(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['offset' => -10]);

        $this->assertStringContainsString('offset: 0', $query);
    }

    public function testBuildProductsQueryHandlesNonNumericLimit(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['limit' => 'abc']);

        $this->assertStringContainsString('limit: 20', $query);
    }

    // ==========================================
    // buildProductsQuery - search filter
    // ==========================================

    public function testBuildProductsQueryWithSearch(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['search' => 'blue shoes']);

        $this->assertStringContainsString('filter:', $query);
        $this->assertStringContainsString('title: { contains: "blue shoes" }', $query);
    }

    public function testBuildProductsQueryWithoutSearchNoFilterClause(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery([]);

        $this->assertStringNotContainsString('filter:', $query);
    }

    public function testBuildProductsQueryEmptySearchNoFilterClause(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['search' => '']);

        $this->assertStringNotContainsString('filter:', $query);
    }

    public function testBuildProductsQueryNonStringSearchIgnored(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['search' => 123]);

        $this->assertStringNotContainsString('filter:', $query);
    }

    public function testBuildProductsQuerySearchEscapesQuotes(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['search' => 'test"injection']);

        $this->assertStringContainsString('test\\"injection', $query);
        $this->assertStringNotContainsString('test"injection', $query);
    }

    // ==========================================
    // buildProductsQuery - category filter
    // ==========================================

    public function testBuildProductsQueryWithCategory(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['category_id' => 'cat_shoes']);

        $this->assertStringContainsString('filter:', $query);
        $this->assertStringContainsString('category: { equals: "cat_shoes" }', $query);
    }

    public function testBuildProductsQueryEmptyCategoryIgnored(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['category_id' => '']);

        $this->assertStringNotContainsString('category:', $query);
    }

    public function testBuildProductsQueryNonStringCategoryIgnored(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['category_id' => 42]);

        $this->assertStringNotContainsString('category:', $query);
    }

    // ==========================================
    // buildProductsQuery - combined filters
    // ==========================================

    public function testBuildProductsQueryCombinesSearchAndCategory(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery([
            'search' => 'sneakers',
            'category_id' => 'cat_footwear',
        ]);

        $this->assertStringContainsString('title: { contains: "sneakers" }', $query);
        $this->assertStringContainsString('category: { equals: "cat_footwear" }', $query);
    }

    // ==========================================
    // buildProductsQuery - sorting
    // ==========================================

    public function testBuildProductsQueryWithPriceAscSort(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['sort' => 'price_asc']);

        $this->assertStringContainsString('sort:', $query);
        $this->assertStringContainsString('price: "ASC"', $query);
    }

    public function testBuildProductsQueryWithTitleDescSort(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['sort' => 'title_desc']);

        $this->assertStringContainsString('sort:', $query);
        $this->assertStringContainsString('title: "DESC"', $query);
    }

    public function testBuildProductsQueryInvalidSortIgnored(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['sort' => 'invalid_sort']);

        $this->assertStringNotContainsString('sort:', $query);
    }

    public function testBuildProductsQueryNoSortByDefault(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery([]);

        $this->assertStringNotContainsString('sort:', $query);
    }

    public function testBuildProductsQueryNonStringSortIgnored(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery(['sort' => 123]);

        $this->assertStringNotContainsString('sort:', $query);
    }

    // ==========================================
    // buildProductQuery - single product
    // ==========================================

    public function testBuildProductQueryIncludesProductId(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductQuery('abc123');

        $this->assertStringContainsString('product(productId: "abc123")', $query);
    }

    public function testBuildProductQueryIncludesProductFields(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductQuery('abc123');

        $this->assertStringContainsString('title', $query);
        $this->assertStringContainsString('price {', $query);
        $this->assertStringContainsString('seo {', $query);
    }

    public function testBuildProductQueryEscapesSpecialChars(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductQuery('id"with"quotes');

        $this->assertStringContainsString('id\\"with\\"quotes', $query);
        $this->assertStringNotContainsString('id"with"quotes', $query);
    }

    // ==========================================
    // buildProductsQuery - full integration
    // ==========================================

    public function testBuildProductsQueryFullCombination(): void
    {
        $builder = $this->createBuilder();
        $query = $builder->buildProductsQuery([
            'search' => 'shirt',
            'category_id' => 'cat_001',
            'limit' => 30,
            'offset' => 5,
            'sort' => 'price_asc',
        ]);

        $this->assertStringContainsString('title: { contains: "shirt" }', $query);
        $this->assertStringContainsString('category: { equals: "cat_001" }', $query);
        $this->assertStringContainsString('offset: 5', $query);
        $this->assertStringContainsString('limit: 30', $query);
        $this->assertStringContainsString('price: "ASC"', $query);
    }
}
