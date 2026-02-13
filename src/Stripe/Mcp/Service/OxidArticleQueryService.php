<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\ArticleList;

class OxidArticleQueryService implements OxidArticleQueryServiceInterface
{
    public function findArticles(?string $search, ?string $categoryId, int $limit, int $offset): array
    {
        $articleList = oxNew(ArticleList::class);

        if ($categoryId !== null && $categoryId !== '') {
            $articleList->loadCategoryArticles($categoryId, null);
        } elseif ($search !== null && $search !== '') {
            $articleList->loadSearchArticles($search);
        } else {
            $articleList->selectString($this->buildSelectQuery($limit, $offset));

            return array_values($articleList->getArray());
        }

        return array_slice(array_values($articleList->getArray()), $offset, $limit);
    }

    public function countArticles(?string $search, ?string $categoryId): int
    {
        $articleList = oxNew(ArticleList::class);

        if ($categoryId !== null && $categoryId !== '') {
            $articleList->loadCategoryArticles($categoryId, null);
        } elseif ($search !== null && $search !== '') {
            $articleList->loadSearchArticles($search);
        } else {
            $articleList->selectString($this->buildCountQuery());

            return count($articleList);
        }

        return count($articleList);
    }

    public function findArticleById(string $articleId): ?object
    {
        $article = oxNew(Article::class);
        if (!$article->load($articleId)) {
            return null;
        }

        return $article;
    }

    private function buildSelectQuery(int $limit, int $offset): string
    {
        return "SELECT oxarticles.* FROM oxarticles"
            . " WHERE oxarticles.OXACTIVE = 1"
            . " AND oxarticles.OXHIDDEN = 0"
            . " ORDER BY oxarticles.OXTIMESTAMP DESC"
            . " LIMIT {$offset}, {$limit}";
    }

    private function buildCountQuery(): string
    {
        return "SELECT oxarticles.* FROM oxarticles"
            . " WHERE oxarticles.OXACTIVE = 1"
            . " AND oxarticles.OXHIDDEN = 0";
    }
}
