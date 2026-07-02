<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Payments\Stripe\Controller\BasketNotBuyableException;
use OxidEsales\Payments\Stripe\Service\BuyabilityFailure;
use PHPUnit\Framework\TestCase;

/**
 * Story 1 (unbuyable-article-checkout): the exception carries the structured
 * failure list so the catching code in createCheckoutSession() can emit the
 * JSON error without re-running the buyability check.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\BasketNotBuyableException::class)]
#[\PHPUnit\Framework\Attributes\Group('buyability')]
final class BasketNotBuyableExceptionTest extends TestCase
{
    public function testGetFailures_ReturnsFailuresPassedToConstructor(): void
    {
        $failures = [
            new BuyabilityFailure('art-1', 'Sold Out A', 'ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE'),
            new BuyabilityFailure('art-2', 'Sold Out B', 'ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE'),
        ];

        $exception = new BasketNotBuyableException($failures);

        $this->assertSame($failures, $exception->getFailures());
    }
}
