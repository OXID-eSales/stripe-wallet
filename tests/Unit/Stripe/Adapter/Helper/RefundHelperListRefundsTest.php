<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\RefundHelper;
use PHPUnit\Framework\TestCase;
use Stripe\Collection;
use Stripe\Service\RefundService;
use Stripe\StripeClient;

/**
 * VR-21 F5: Stripe's own list of a charge's refunds - the source for `charge.refunded` events that carry no list.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(RefundHelper::class)]
final class RefundHelperListRefundsTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function listsTheRefundsOfTheCharge(): void
    {
        $client = $this->createMock(StripeClient::class);
        $service = $this->createMock(RefundService::class);
        $service->expects($this->once())->method('all')
            ->with(['charge' => 'ch_1', 'limit' => 100])
            ->willReturn(Collection::constructFrom([
                'object' => 'list',
                'url' => '/v1/refunds',
                'has_more' => false,
                'data' => [
                    ['id' => 're_1', 'object' => 'refund', 'amount' => 1000, 'currency' => 'eur', 'status' => 'succeeded'],
                    ['id' => 're_2', 'object' => 'refund', 'amount' => 500, 'currency' => 'eur', 'status' => 'pending'],
                ],
            ]));
        $client->refunds = $service;

        $refunds = (new RefundHelper($this->createMock(IdempotencyRepositoryInterface::class)))
            ->listRefundsByCharge($client, 'ch_1');

        $this->assertSame(['re_1', 're_2'], array_map(static fn ($refund): string => $refund->id, $refunds));
    }
}
