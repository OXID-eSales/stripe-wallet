<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Stripe\Admin\StripePanelOrderLoader;
use OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder;
use OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider;
use PHPUnit\Framework\TestCase;

/**
 * PAY-3 S5: the provider API of payment-base's admin payment API (options, bounds). VR-22 A5: the refusals and the
 * dispatching moved to payment-base's runner and {@see StripeMoneyGatewayTest}.
 */
final class StripePaymentPanelProviderPay1Test extends TestCase
{
    public function testItOffersTheOptionalProviderInterfaces(): void
    {
        $provider = $this->provider();

        self::assertInstanceOf(AdminActionOptionsProviderInterface::class, $provider);
        self::assertInstanceOf(AdminActionBoundsProviderInterface::class, $provider);
    }

    public function testActionOptions_AreTheClassicPanelsChoices(): void
    {
        $options = $this->provider()->actionOptions($this->contract());

        self::assertSame(['duplicate', 'requested_by_customer', 'fraudulent'], $options->refundReasons);
        self::assertSame(['duplicate', 'requested_by_customer', 'fraudulent', 'abandoned'], $options->voidReasons);
        self::assertTrue($options->refundDescription);
        self::assertSame(140, $options->reasonMaxLength);
    }

    public function testBoundsFor_AreTheLiveBoundsOfTheOrdersIntent(): void
    {
        $bounds = $this->createStub(AdminActionBoundsInterface::class);
        $bounds->method('readBounds')->willReturn(new AdminActionBounds(25.0, 5.0));

        $result = $this->provider(bounds: $bounds)->boundsFor($this->contract());

        self::assertSame(25.0, $result?->capturable);
        self::assertSame(5.0, $result->refundable);
    }

    public function testBoundsFor_AContractWithoutAnOrder_LeavesTheNumbersToPaymentBase(): void
    {
        self::assertNull($this->provider()->boundsFor($this->contract(orderId: null)));
    }

    public function testBoundsFor_AnOrderThatCannotBeLoaded_IsUnavailable(): void
    {
        $result = $this->provider(noOrder: true)->boundsFor($this->contract());

        self::assertFalse($result?->available);
    }

    private function contract(?string $orderId = 'order-1'): PaymentContractInterface
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn($orderId);

        return $contract;
    }

    private function provider(
        ?AdminActionBoundsInterface $bounds = null,
        bool $noOrder = false,
    ): StripePaymentPanelProvider {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $loader = $this->createStub(StripePanelOrderLoader::class);
        $loader->method('loadById')->willReturn($noOrder ? null : $order);
        $bounds ??= $this->createStub(AdminActionBoundsInterface::class);
        $viewData = $this->createStub(StripePanelViewDataBuilder::class);

        return new StripePaymentPanelProvider(
            viewDataBuilder: $viewData,
            orderLoader: $loader,
            actionBounds: $bounds,
        );
    }
}
