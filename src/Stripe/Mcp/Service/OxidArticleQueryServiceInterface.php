<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

interface OxidArticleQueryServiceInterface
{
    /**
     * @return array<object> OXID article objects
     */
    public function findArticles(?string $search, ?string $categoryId, int $limit, int $offset): array;

    public function countArticles(?string $search, ?string $categoryId): int;

    public function findArticleById(string $articleId): ?object;
}
