<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\BuyabilityFailure;
use PHPUnit\Framework\TestCase;

/**
 * Story 1 (unbuyable-article-checkout): the immutable value object that
 * describes a single cart item that is no longer buyable.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\BuyabilityFailure::class)]
#[\PHPUnit\Framework\Attributes\Group('buyability')]
final class BuyabilityFailureTest extends TestCase
{
    public function testConstruct_ExposesArticleIdTitleAndReasonAsReadonly(): void
    {
        $failure = new BuyabilityFailure(
            articleId: 'art-123',
            productTitle: 'Cool Shirt',
            reason: 'ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE',
        );

        $this->assertSame('art-123', $failure->articleId);
        $this->assertSame('Cool Shirt', $failure->productTitle);
        $this->assertSame('ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE', $failure->reason);
    }
}
