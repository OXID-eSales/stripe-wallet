<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\ArticleList;
use OxidEsales\Eshop\Core\DatabaseProvider;

class OxidArticleQueryService implements OxidArticleQueryServiceInterface
{
    public function findArticles(?string $search, ?string $categoryId, int $limit, int $offset): array
    {
        /** @var ArticleList $articleList oxNew returns mixed — OXID core pattern */
        $articleList = oxNew(ArticleList::class);

        if ($categoryId !== null && $categoryId !== '') {
            $articleList->loadCategoryArticles($categoryId, []);
        } elseif ($search !== null && $search !== '') {
            $articleList->selectString($this->buildSearchQuery($search, $limit, $offset));

            /** @var array<object> $articles */
            $articles = $articleList->getArray();

            return array_values($articles);
        } else {
            $articleList->selectString($this->buildSelectQuery($limit, $offset));

            /** @var array<object> $articles */
            $articles = $articleList->getArray();

            return array_values($articles);
        }

        /** @var array<object> $articles */
        $articles = $articleList->getArray();

        return array_slice(array_values($articles), $offset, $limit);
    }

    public function countArticles(?string $search, ?string $categoryId): int
    {
        /** @var ArticleList $articleList oxNew returns mixed — OXID core pattern */
        $articleList = oxNew(ArticleList::class);

        if ($categoryId !== null && $categoryId !== '') {
            $articleList->loadCategoryArticles($categoryId, []);
        } elseif ($search !== null && $search !== '') {
            $articleList->selectString($this->buildSearchCountQuery($search));

            return count($articleList);
        } else {
            $articleList->selectString($this->buildCountQuery());

            return count($articleList);
        }

        return count($articleList);
    }

    public function findArticleById(string $articleId): ?object
    {
        /** @var Article $article oxNew returns mixed — OXID core pattern */
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

    private function buildSearchQuery(string $search, int $limit, int $offset): string
    {
        $db = DatabaseProvider::getDb();
        $quoted = $db->quote('%' . $search . '%');

        return "SELECT oxarticles.* FROM oxarticles"
            . " WHERE oxarticles.OXACTIVE = 1"
            . " AND oxarticles.OXHIDDEN = 0"
            . " AND (oxarticles.OXTITLE LIKE {$quoted}"
            . " OR oxarticles.OXSHORTDESC LIKE {$quoted}"
            . " OR oxarticles.OXARTNUM LIKE {$quoted})"
            . " ORDER BY oxarticles.OXTIMESTAMP DESC"
            . " LIMIT {$offset}, {$limit}";
    }

    private function buildCountQuery(): string
    {
        return "SELECT oxarticles.* FROM oxarticles"
            . " WHERE oxarticles.OXACTIVE = 1"
            . " AND oxarticles.OXHIDDEN = 0";
    }

    private function buildSearchCountQuery(string $search): string
    {
        $db = DatabaseProvider::getDb();
        $quoted = $db->quote('%' . $search . '%');

        return "SELECT oxarticles.* FROM oxarticles"
            . " WHERE oxarticles.OXACTIVE = 1"
            . " AND oxarticles.OXHIDDEN = 0"
            . " AND (oxarticles.OXTITLE LIKE {$quoted}"
            . " OR oxarticles.OXSHORTDESC LIKE {$quoted}"
            . " OR oxarticles.OXARTNUM LIKE {$quoted})";
    }
}
