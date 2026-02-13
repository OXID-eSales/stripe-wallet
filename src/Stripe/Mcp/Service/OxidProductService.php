<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFieldMapperInterface;

class OxidProductService implements AcpProductServiceInterface
{
    public function __construct(
        private readonly ProductFieldMapperInterface $fieldMapper,
        private readonly OxidArticleQueryServiceInterface $articleQuery
    ) {
    }

    public function listProducts(array $filters = []): array
    {
        $limitVal = $filters['limit'] ?? 20;
        $limit = min(is_numeric($limitVal) ? (int) $limitVal : 20, 100);
        $offsetVal = $filters['offset'] ?? 0;
        $offset = max(is_numeric($offsetVal) ? (int) $offsetVal : 0, 0);
        $search = isset($filters['search']) && is_string($filters['search']) ? $filters['search'] : null;
        $categoryId = isset($filters['category_id']) && is_string($filters['category_id'])
            ? $filters['category_id']
            : null;

        $articles = $this->articleQuery->findArticles($search, $categoryId, $limit, $offset);
        $total = $this->articleQuery->countArticles($search, $categoryId);

        $products = array_map(
            fn ($article) => $this->fieldMapper->mapProduct($article),
            $articles
        );

        return [
            'products' => $products,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function getProduct(string $productId): ?array
    {
        $article = $this->articleQuery->findArticleById($productId);
        if ($article === null) {
            return null;
        }

        return $this->fieldMapper->mapProduct($article);
    }
}
