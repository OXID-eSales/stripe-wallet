<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\BasketItem;
use OxidEsales\Payments\Stripe\Service\BasketBuyabilityValidator;
use OxidEsales\Payments\Stripe\Service\BuyabilityFailure;
use PHPUnit\Framework\TestCase;

/**
 * Story 1 (unbuyable-article-checkout): the validator answers "which items in
 * this basket are not buyable right now?" — the pre-dispatch guard that keeps
 * the checkout-session flow from starting for an unbuyable basket.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\BasketBuyabilityValidator::class)]
#[\PHPUnit\Framework\Attributes\Group('buyability')]
final class BasketBuyabilityValidatorTest extends TestCase
{
    public function testValidate_WhenAllArticlesBuyable_ReturnsEmptyArray(): void
    {
        $basket = $this->basketWith([
            $this->basketItem('art-1', 'Buyable A', true),
            $this->basketItem('art-2', 'Buyable B', true),
        ]);

        $failures = (new BasketBuyabilityValidator())->validate($basket);

        $this->assertSame([], $failures);
    }

    public function testValidate_WhenOneArticleNotBuyable_ReturnsSingleFailureWithArticleIdAndTitle(): void
    {
        $basket = $this->basketWith([
            $this->basketItem('art-1', 'Buyable A', true),
            $this->basketItem('art-2', 'Sold Out B', false),
        ]);

        $failures = (new BasketBuyabilityValidator())->validate($basket);

        $this->assertCount(1, $failures);
        $this->assertContainsOnlyInstancesOf(BuyabilityFailure::class, $failures);
        $this->assertSame('art-2', $failures[0]->articleId);
        $this->assertSame('Sold Out B', $failures[0]->productTitle);
        $this->assertSame(
            BasketBuyabilityValidator::REASON_NOT_BUYABLE,
            $failures[0]->reason,
        );
    }

    public function testValidate_WhenMultipleArticlesNotBuyable_ReturnsFailurePerArticle(): void
    {
        $basket = $this->basketWith([
            $this->basketItem('art-1', 'Sold Out A', false),
            $this->basketItem('art-2', 'Buyable B', true),
            $this->basketItem('art-3', 'Sold Out C', false),
        ]);

        $failures = (new BasketBuyabilityValidator())->validate($basket);

        $ids = array_map(static fn(BuyabilityFailure $f): string => $f->articleId, $failures);
        $this->assertSame(['art-1', 'art-3'], $ids);
    }

    public function testValidate_WhenBasketItemHasNoArticle_IsSkippedNotFatal(): void
    {
        $orphan = $this->createMock(BasketItem::class);
        $orphan->method('getArticle')->willReturn(null);

        $basket = $this->basketWith([
            $orphan,
            $this->basketItem('art-2', 'Buyable B', true),
        ]);

        $failures = (new BasketBuyabilityValidator())->validate($basket);

        $this->assertSame([], $failures);
    }

    /**
     * @param list<BasketItem> $items
     */
    private function basketWith(array $items): Basket
    {
        $basket = $this->createMock(Basket::class);
        $basket->method('getContents')->willReturn($items);

        return $basket;
    }

    private function basketItem(string $articleId, string $title, bool $buyable): BasketItem
    {
        $article = $this->createMock(Article::class);
        $article->method('getId')->willReturn($articleId);
        $article->method('isBuyable')->willReturn($buyable);

        $item = $this->createMock(BasketItem::class);
        $item->method('getArticle')->willReturn($article);
        $item->method('getTitle')->willReturn($title);

        return $item;
    }
}
