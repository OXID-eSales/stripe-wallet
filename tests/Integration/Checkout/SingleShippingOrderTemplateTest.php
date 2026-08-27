<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Checkout;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stands in for the order controller. Both single-* answers are real and
 * independently settable; everything else the page asks is absorbed.
 */
class StripeOrderProbeView
{
    public function __construct(
        private readonly bool $shippingAutoAssigned,
        private readonly bool $paymentAutoAssigned = false,
    ) {
    }

    public function isSingleShippingAutoAssigned(): bool
    {
        return $this->shippingAutoAssigned;
    }

    public function isSinglePaymentAutoAssigned(): bool
    {
        return $this->paymentAutoAssigned;
    }

    public function getPayment(): StripeOrderProbePayment
    {
        return new StripeOrderProbePayment();
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * A Stripe payment — which is what puts the page on the private copy of
 * `shippingAndPayment` this test exists for.
 */
class StripeOrderProbePayment
{
    public function isStripePaymentMethod(): bool
    {
        return true;
    }

    public function getId(): string
    {
        return 'oe_payments_stripe';
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

class StripeOrderProbeBasket
{
    public function getProductsCount(): int
    {
        return 3;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Sprint 07 S5 — this module's order template has to opt in to the decision.
 *
 * payment-base overrides core's `checkout_order_shipping_carrier` block, but
 * that override cannot reach a Stripe order: this module replaces the whole
 * `shippingAndPayment` section with a private copy and never calls parent().
 * So the copy carries its own condition, and this test is what proves the two
 * agree — the same trap sprint 06 hit for the payment half, found only on a
 * live shop.
 *
 * Every "absent" assertion is paired with a "present" one: a page that failed
 * to render satisfies assertStringNotContainsString perfectly.
 */
#[Group('integration')]
#[Group('requires-oxid-container')]
final class SingleShippingOrderTemplateTest extends TestCase
{
    private const TEMPLATE = 'page/checkout/order.html.twig';

    /** This module's private carrier form — present iff the block renders. */
    private const SHIPPING_BLOCK_MARKER = 'orderShipping';

    /** Its private payment form, decided separately (sprint 06). */
    private const PAYMENT_BLOCK_MARKER = 'orderPayment';

    public function testCarrierBlockRendersWhenTheCustomerHadAChoice(): void
    {
        $output = $this->renderOrderPage(shippingAutoAssigned: false);

        self::assertStringContainsString(self::SHIPPING_BLOCK_MARKER, $output);
    }

    public function testCarrierBlockIsLeftOutForASingleDeliverySet(): void
    {
        $output = $this->renderOrderPage(shippingAutoAssigned: true);

        self::assertStringNotContainsString(self::SHIPPING_BLOCK_MARKER, $output);
        self::assertStringContainsString(self::PAYMENT_BLOCK_MARKER, $output);
    }

    /**
     * The two blocks are decided independently inside the private copy, just as
     * they are in core's markup.
     */
    public function testCarrierBlockSurvivesWhenOnlyPaymentIsAutoAssigned(): void
    {
        $output = $this->renderOrderPage(shippingAutoAssigned: false, paymentAutoAssigned: true);

        self::assertStringContainsString(self::SHIPPING_BLOCK_MARKER, $output);
        self::assertStringNotContainsString(self::PAYMENT_BLOCK_MARKER, $output);
    }

    public function testBothBlocksDisappearWhenBothAreAutoAssigned(): void
    {
        $output = $this->renderOrderPage(shippingAutoAssigned: true, paymentAutoAssigned: true);

        self::assertStringNotContainsString(self::SHIPPING_BLOCK_MARKER, $output);
        self::assertStringNotContainsString(self::PAYMENT_BLOCK_MARKER, $output);
        self::assertStringContainsString('stripe-checkout-btn', $output);
    }

    private function renderOrderPage(bool $shippingAutoAssigned, bool $paymentAutoAssigned = false): string
    {
        $renderer = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer();

        $output = $renderer->renderTemplate(self::TEMPLATE, [
            'oView' => new StripeOrderProbeView($shippingAutoAssigned, $paymentAutoAssigned),
            'oxcmp_basket' => new StripeOrderProbeBasket(),
        ]);

        // A shop without a usable frontend theme answers with the template name
        // instead of markup. Say so plainly — a silent pass here would claim the
        // opt-in was verified when nothing was rendered.
        self::assertNotSame(
            self::TEMPLATE,
            trim($output),
            'the shop renderer returned the template name — no frontend theme in this environment'
        );

        return $output;
    }
}
