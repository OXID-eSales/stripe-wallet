<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\LinkedOrderEndingInterface;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Service\OxidContractLinkedOrderUpdater;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * OPC-175 / S4: the order ends the shared payment-base way (storno, stock and
 * vouchers back for an unpaid order). The ending itself is tested in
 * payment-base (unit OxidLinkedOrderEndingTest, integration OxidOrderRestockTest).
 */
#[CoversClass(OxidContractLinkedOrderUpdater::class)]
final class OxidContractLinkedOrderUpdaterTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(
            ContractLinkedOrderUpdaterInterface::class,
            new OxidContractLinkedOrderUpdater($this->createMock(LinkedOrderEndingInterface::class))
        );
    }

    public function testMarkCancelledEndsTheOrderAsCancelled(): void
    {
        $ending = $this->createMock(LinkedOrderEndingInterface::class);
        $ending->expects(self::once())->method('end')->with('order-123', 'CANCELLED');

        (new OxidContractLinkedOrderUpdater($ending))->markCancelled('order-123');
    }

    public function testMarkFailedEndsTheOrderAsFailed(): void
    {
        $ending = $this->createMock(LinkedOrderEndingInterface::class);
        $ending->expects(self::once())->method('end')->with('order-456', 'FAILED');

        (new OxidContractLinkedOrderUpdater($ending))->markFailed('order-456', 'card_declined');
    }
}
