<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefund;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefundLister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(ChargeRefundLister::class)]
final class ChargeRefundListerTest extends TestCase
{
    private StripeAdapterInterface&MockObject $adapter;
    private ChargeRefundLister $lister;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($this->adapter);
        $this->lister = new ChargeRefundLister($factory);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function listsStripesRefundsInCurrencyUnitsWithoutTheOnesThatMovedNoMoney(): void
    {
        $this->adapter->expects($this->once())->method('listChargeRefunds')->with('ch_1')->willReturn([
            new StripeRefundDto('re_ok', 2500, 'eur', 'succeeded', null, 1),
            new StripeRefundDto('re_pending', 1000, 'eur', 'pending', null, 2),
            new StripeRefundDto('re_failed', 700, 'eur', 'failed', null, 3),
            new StripeRefundDto('re_canceled', 300, 'eur', 'canceled', null, 4),
        ]);

        $refunds = $this->lister->listFor('ch_1');

        $this->assertEquals(
            [new ChargeRefund('re_ok', 25.0, 'EUR'), new ChargeRefund('re_pending', 10.0, 'EUR')],
            $refunds
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function readsZeroDecimalCurrenciesWithoutDividing(): void
    {
        $this->adapter->method('listChargeRefunds')->willReturn([new StripeRefundDto('re_jpy', 1500, 'jpy', 'succeeded', null, 1)]);

        $this->assertEquals([new ChargeRefund('re_jpy', 1500.0, 'JPY')], $this->lister->listFor('ch_jpy'));
    }
}
