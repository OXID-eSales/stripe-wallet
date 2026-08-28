<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\PaymentBase\Repository\VoucherReleaseInterface;
use OxidEsales\Payments\Stripe\Service\OxidContractLinkedOrderUpdater;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OxidContractLinkedOrderUpdater.
 *
 * Sprint 114.13 (§8): Characterization tests written before the production
 * class was changed. Uses the testable-subclass pattern to override the
 * protected loadOrder() seam so oxNew() is never called (R-1.5).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\OxidContractLinkedOrderUpdater::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-114-13')]
final class OxidContractLinkedOrderUpdaterTest extends TestCase
{
    private RecordingVoucherReleaser $voucherReleaser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->voucherReleaser = new RecordingVoucherReleaser();
    }

    public function testImplementsInterface(): void
    {
        $updater = $this->buildUpdater(null);

        self::assertInstanceOf(ContractLinkedOrderUpdaterInterface::class, $updater);
    }

    // --- markCancelled ---

    public function testMarkCancelledSetsTransStatusToCancelled(): void
    {
        $order = $this->createMockOrder();
        $order->expects($this->once())->method('save');

        $updater = $this->buildUpdater($order);
        $updater->markCancelled('order-123');

        self::assertSame('CANCELLED', $order->oxorder__oxtransstatus->value);
    }

    public function testMarkCancelledIsNoOpForEmptyOrderId(): void
    {
        $order = $this->createMockOrder();
        $order->expects($this->never())->method('save');

        $updater = $this->buildUpdater($order);
        $updater->markCancelled('');

        // No assertion needed beyond the never() expectation on save
    }

    public function testMarkCancelledIsNoOpWhenOrderNotFound(): void
    {
        $order = $this->createMockOrder();
        $order->expects($this->never())->method('save');

        $updater = $this->buildUpdater(null);
        $updater->markCancelled('nonexistent-order');
    }

    // --- markFailed ---

    public function testMarkFailedSetsTransStatusToFailed(): void
    {
        $order = $this->createMockOrder();
        $order->expects($this->once())->method('save');

        $updater = $this->buildUpdater($order);
        $updater->markFailed('order-456', 'payment_declined');

        self::assertSame('FAILED', $order->oxorder__oxtransstatus->value);
    }

    public function testMarkFailedIsNoOpForEmptyOrderId(): void
    {
        $order = $this->createMockOrder();
        $order->expects($this->never())->method('save');

        $updater = $this->buildUpdater($order);
        $updater->markFailed('', 'some reason');
    }

    public function testMarkFailedIsNoOpWhenOrderNotFound(): void
    {
        $order = $this->createMockOrder();
        $order->expects($this->never())->method('save');

        $updater = $this->buildUpdater(null);
        $updater->markFailed('nonexistent-order', 'declined');
    }

    // --- STRP-168: a mirrored ending must leave the order where the cron would ---

    /**
     * markCancelled set the status and nothing else, so the order was never
     * stornoed and its vouchers stayed spent. Worse, the cleanup command only
     * collects orders still at NOT_FINISHED — once this ran, the row was past
     * it forever, and the customer's voucher with it.
     */
    public function testMarkCancelledStornosTheOrder(): void
    {
        $order = $this->createMockOrder();

        $updater = $this->buildUpdater($order);
        $updater->markCancelled('order-123');

        self::assertSame(1, (int) $order->oxorder__oxstorno->value);
    }

    public function testMarkCancelledReleasesTheVouchers(): void
    {
        $updater = $this->buildUpdater($this->createMockOrder());
        $updater->markCancelled('order-123');

        self::assertSame(['order-123'], $this->voucherReleaser->released);
    }

    public function testMarkFailedStornosTheOrderAndReleasesVouchers(): void
    {
        $order = $this->createMockOrder();

        $updater = $this->buildUpdater($order);
        $updater->markFailed('order-456', 'card_declined');

        self::assertSame(1, (int) $order->oxorder__oxstorno->value);
        self::assertSame(['order-456'], $this->voucherReleaser->released);
    }

    public function testNoVouchersAreReleasedWhenTheOrderDoesNotExist(): void
    {
        $updater = $this->buildUpdater(null);
        $updater->markCancelled('nonexistent-order');

        self::assertSame([], $this->voucherReleaser->released);
    }

    public function testNoVouchersAreReleasedForAnEmptyOrderId(): void
    {
        $updater = $this->buildUpdater($this->createMockOrder());
        $updater->markCancelled('');

        self::assertSame([], $this->voucherReleaser->released);
    }

    /**
     * Handing the voucher back is a courtesy on top of recording the ending.
     * If it throws, the order must still end up cancelled.
     */
    public function testTheOrderIsStillCancelledWhenReleasingVouchersFails(): void
    {
        $order = $this->createMockOrder();
        $this->voucherReleaser->throw = true;

        $updater = $this->buildUpdater($order);
        $updater->markCancelled('order-123');

        self::assertSame('CANCELLED', $order->oxorder__oxtransstatus->value);
    }

    // --- helpers ---

    /**
     * Builds a testable subclass that overrides loadOrder() to return the
     * given stub. When $orderStub is null, loadOrder() returns null
     * (simulating an order-not-found scenario).
     */
    private function buildUpdater(?Order $orderStub): OxidContractLinkedOrderUpdater
    {
        return new class ($orderStub, $this->voucherReleaser) extends OxidContractLinkedOrderUpdater {
            public function __construct(private readonly ?Order $stub, VoucherReleaseInterface $voucherReleaser)
            {
                parent::__construct($voucherReleaser);
            }

            protected function loadOrder(string $orderId): ?Order
            {
                if ($orderId === '') {
                    return null;
                }

                return $this->stub;
            }
        };
    }

    /**
     * Returns a partial mock of Order that allows setting magic properties
     * (like oxorder__oxtransstatus) and records save() calls.
     *
     * @return Order&MockObject
     */
    private function createMockOrder(): Order&MockObject
    {
        // We need a mock that still allows dynamic property assignment
        // (Eloquent-style $order->oxorder__xxx = new Field(…))
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();

        return $order;
    }
}

/**
 * @internal
 */
final class RecordingVoucherReleaser implements VoucherReleaseInterface
{
    /** @var list<string> */
    public array $released = [];

    public bool $throw = false;

    public function releaseVouchers(string $orderId): int
    {
        if ($this->throw) {
            throw new \RuntimeException('vouchers table is locked');
        }

        $this->released[] = $orderId;

        return 1;
    }
}
