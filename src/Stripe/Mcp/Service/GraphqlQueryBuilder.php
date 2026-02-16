<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

class GraphqlQueryBuilder
{
    private const PRODUCT_FIELDS = <<<'GRAPHQL'
        id
        title
        shortDescription
        longDescription
        price {
            price
            currency {
                name
                sign
            }
        }
        imageGallery {
            icon
            thumb
            images {
                image
            }
        }
        manufacturer {
            title
        }
        category {
            title
        }
        seo {
            url
        }
        rating
        stock
    GRAPHQL;

    private const ALLOWED_SORTS = ['price_asc', 'price_desc', 'title_asc', 'title_desc'];

    /**
     * @param array<string, mixed> $filters
     */
    public function buildProductsQuery(array $filters): string
    {
        $limit = min(is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 20, 100);
        $offset = max(is_numeric($filters['offset'] ?? null) ? (int) $filters['offset'] : 0, 0);

        $filterClause = $this->buildFilterClause($filters);
        $sortClause = $this->buildSortClause($filters);
        $paginationClause = "pagination: { offset: {$offset}, limit: {$limit} }";

        $arguments = array_filter([$filterClause, $paginationClause, $sortClause]);
        $argumentsStr = implode(', ', $arguments);

        $fields = self::PRODUCT_FIELDS;

        return <<<GRAPHQL
            {
                products({$argumentsStr}) {
                    {$fields}
                }
            }
            GRAPHQL;
    }

    public function buildProductQuery(string $productId): string
    {
        $escapedId = addslashes($productId);
        $fields = self::PRODUCT_FIELDS;

        return <<<GRAPHQL
            {
                product(productId: "{$escapedId}") {
                    {$fields}
                }
            }
            GRAPHQL;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildFilterClause(array $filters): string
    {
        $search = isset($filters['search']) && is_string($filters['search']) ? $filters['search'] : null;
        $categoryId = isset($filters['category_id']) && is_string($filters['category_id'])
            ? $filters['category_id']
            : null;

        $parts = [];

        if ($search !== null && $search !== '') {
            $escapedSearch = addslashes($search);
            $parts[] = "title: { contains: \"{$escapedSearch}\" }";
        }

        if ($categoryId !== null && $categoryId !== '') {
            $escapedCategory = addslashes($categoryId);
            $parts[] = "category: { equals: \"{$escapedCategory}\" }";
        }

        if ($parts === []) {
            return '';
        }

        return 'filter: { ' . implode(', ', $parts) . ' }';
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildSortClause(array $filters): string
    {
        $sort = isset($filters['sort']) && is_string($filters['sort']) ? $filters['sort'] : null;

        if ($sort === null || !in_array($sort, self::ALLOWED_SORTS, true)) {
            return '';
        }

        [$field, $direction] = explode('_', $sort);

        return "sort: { {$field}: \"" . strtoupper($direction) . '" }';
    }
}
