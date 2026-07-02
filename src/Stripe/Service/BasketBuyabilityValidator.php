<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\BasketItem;

/**
 * Answers "which items in this basket are not buyable right now?".
 *
 * Used as a pre-dispatch guard in the checkout-session flow so that a product
 * turned unbuyable after the checkout page rendered is reported before any
 * contract or draft order is created.
 *
 * Story 1 (unbuyable-article-checkout).
 */
class BasketBuyabilityValidator
{
    /** OXID translation key reused from Article::checkForStock() / getArticle(). */
    public const REASON_NOT_BUYABLE = 'ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE';

    /**
     * @return BuyabilityFailure[]
     */
    public function validate(Basket $basket): array
    {
        $failures = [];

        foreach ($basket->getContents() as $item) {
            if (!$item instanceof BasketItem) {
                continue;
            }

            $failure = $this->inspect($item);
            if ($failure === null) {
                continue;
            }

            $failures[] = $failure;
        }

        return $failures;
    }

    private function inspect(BasketItem $item): ?BuyabilityFailure
    {
        $article = $item->getArticle();
        if (!$article instanceof Article) {
            return null;
        }

        if ($article->isBuyable()) {
            return null;
        }

        return new BuyabilityFailure(
            articleId: (string) $article->getId(),
            productTitle: (string) $item->getTitle(),
            reason: self::REASON_NOT_BUYABLE,
        );
    }
}
