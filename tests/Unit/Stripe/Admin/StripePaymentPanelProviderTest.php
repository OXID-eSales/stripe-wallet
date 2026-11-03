<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\UnsupportedPaymentActionException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Stripe\Admin\StripePanelOrderLoader;
use OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder;
use OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * Sprint I — Stripe panel provider contract tests.
 *
 * The provider is the thin shim that injects Stripe's existing view-data +
 * view-data into payment-base's shared admin tab. Tests here validate the
 * Sprint-I contract (supports / build). VR-22 A5: the money actions are
 * payment-base's runner's and StripeMoneyGatewayTest's.
 */
final class StripePaymentPanelProviderTest extends TestCase
{
    public function testProviderNameIsStripe(): void
    {
        $provider = $this->provider();

        self::assertSame('stripe', $provider->getProviderName());
    }

    public function testSupportsOrderPaidWithStripe(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supports(
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        ));
    }

    public function testSupportsByContractProviderEvenWhenPaymentTypeUnknown(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supports(
            $this->context(paymentType: '', provider: 'stripe'),
        ));
    }

    public function testDoesNotSupportNonStripeOrder(): void
    {
        $provider = $this->provider();

        self::assertFalse($provider->supports(
            $this->context(paymentType: 'oe_payments_paypal', provider: 'paypal'),
        ));
        self::assertFalse($provider->supports(
            $this->context(paymentType: 'oxidinvoice', provider: null),
        ));
    }

    public function testBuildReturnsRenderableWithStripePanelPathAndViewData(): void
    {
        $provider = $this->provider(
            withViewData: [
                'contractId' => 'c_1',
                'transactionId' => 'pi_3TOypMRKy8lrhVfC0oGQGojh',
                'capturedAmount' => '100.00',
                'refundedAmount' => '0.00',
                'currency' => 'EUR',
            ],
        );

        $renderable = $provider->build($this->context(
            paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            provider: 'stripe',
        ));

        self::assertSame('stripe', $renderable->providerKey);
        self::assertSame('@oe_payments_stripe_wallet/admin/panel/stripe_panel.html.twig', $renderable->templatePath);
        self::assertSame('c_1', $renderable->viewData['contractId']);
        self::assertSame('pi_3TOypMRKy8lrhVfC0oGQGojh', $renderable->viewData['transactionId']);
        self::assertSame('EUR', $renderable->viewData['currency']);
    }

    public function testThePanelHasNoMoneyActions(): void
    {
        $this->expectException(UnsupportedPaymentActionException::class);

        $this->provider()->handleAction(
            'refund',
            ['refund_amount' => '12.50'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    private function provider(
        array $withViewData = [],
        bool $noOrder = false,
    ): StripePaymentPanelProvider {
        return new StripePaymentPanelProvider(
            viewDataBuilder: $this->viewDataBuilderStub($withViewData),
            orderLoader: $this->orderLoaderStub($noOrder ? null : $this->orderStub()),
            actionBounds: $this->createMock(AdminActionBoundsInterface::class),
        );
    }

    private function viewDataBuilderStub(array $data): StripePanelViewDataBuilder
    {
        return new class ($data) extends StripePanelViewDataBuilder {
            /** @phpstan-ignore-next-line constructor.dependency */
            public function __construct(private readonly array $stubData)
            {
            }
            public function build(Order $order): array
            {
                return $this->stubData;
            }
            public function resetViewCache(): void
            {
            }
        };
    }

    private function orderLoaderStub(?Order $order): StripePanelOrderLoader
    {
        return new class ($order) extends StripePanelOrderLoader {
            public function __construct(private readonly ?Order $stubOrder)
            {
            }
            public function loadById(string $orderId): ?Order
            {
                return $this->stubOrder;
            }
        };
    }

    private function context(string $paymentType, ?string $provider): PaymentPanelContext
    {
        $contract = null;
        if ($provider !== null) {
            $contract = $this->createMock(PaymentContractInterface::class);
            $contract->method('getProvider')->willReturn($provider);
        }

        return new PaymentPanelContext(
            orderId: 'order_1',
            paymentType: $paymentType,
            contract: $contract,
        );
    }

    private function orderStub(): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        return $order;
    }

}
