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

class IframeConsentProbeView
{
    public function __construct(
        private readonly bool $agbActive,
        private readonly bool $priorConsent,
    ) {
    }

    public function isConfirmAGBActive(): bool
    {
        return $this->agbActive;
    }

    public function isPriorAgbConsent(): bool
    {
        return $this->priorConsent;
    }

    public function isLowOrderPrice(): bool
    {
        return false;
    }

    public function isSingleShippingAutoAssigned(): bool
    {
        return true;
    }

    public function isSinglePaymentAutoAssigned(): bool
    {
        return true;
    }

    public function getPayment(): IframeConsentProbePayment
    {
        return new IframeConsentProbePayment();
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

class IframeConsentProbePayment
{
    public function isStripePaymentMethod(): bool
    {
        return true;
    }

    public function getId(): string
    {
        return 'oe_payments_stripe_wallet';
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Stands in for the shop's oViewConf global: a Twig context variable of the
 * same name shadows the global, so the iframe flag is under the test's control.
 */
class IframeConsentProbeViewConf
{
    public function __construct(private readonly bool $iframe)
    {
    }

    public function isStripeIframeCheckout(): bool
    {
        return $this->iframe;
    }

    public function getSslSelfLink(): string
    {
        return 'https://shop.test/index.php?';
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

class IframeConsentProbeBasket
{
    public function getProductsCount(): int
    {
        return 1;
    }

    /** @param array<int, mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        return null;
    }
}

/**
 * Sprint 137 (IFRAME-05) — in iframe mode the Place-Order button is never the
 * shopper's control, whatever the session's consent history.
 *
 * Before this sprint the template fell back to a visible button whenever
 * blConfirmAGB was on and the session held no persisted consent — which is
 * every fresh session. The sheet is now mounted the moment Terms are ticked
 * instead; the button stays in the DOM, hidden and disabled, purely as the
 * payability signal the JS observes.
 */
#[Group('integration')]
#[Group('requires-oxid-container')]
final class OrderPageIframeConsentTemplateTest extends TestCase
{
    private const TEMPLATE = 'page/checkout/order.html.twig';

    public function testFreshSessionInIframeModeHidesTheButtonAndArmsMountOnConsent(): void
    {
        $output = $this->renderOrderPage(iframe: true, agbActive: true, priorConsent: false);
        $button = $this->extractButtonTag($output);

        self::assertStringContainsString('hidden', $button, 'the button must never be painted in iframe mode');
        self::assertStringContainsString('disabled', $button, 'server renders the not-yet-consented state');
        self::assertStringContainsString('data-order-submit-eager-value="false"', $output);
        self::assertStringContainsString('data-order-submit-mount-on-consent-value="true"', $output);
        self::assertStringContainsString('data-order-submit-target="consentHint"', $output);
    }

    public function testConsentedSessionInIframeModeEagerMountsWithoutHint(): void
    {
        $output = $this->renderOrderPage(iframe: true, agbActive: true, priorConsent: true);
        $button = $this->extractButtonTag($output);

        self::assertStringContainsString('hidden', $button);
        self::assertStringContainsString('data-order-submit-eager-value="true"', $output);
        self::assertStringContainsString('data-order-submit-mount-on-consent-value="false"', $output);
        self::assertStringNotContainsString('data-order-submit-target="consentHint"', $output);
    }

    public function testIframeModeWithoutAgbConfirmationEagerMounts(): void
    {
        $output = $this->renderOrderPage(iframe: true, agbActive: false, priorConsent: false);

        self::assertStringContainsString('data-order-submit-eager-value="true"', $output);
        self::assertStringContainsString('data-order-submit-mount-on-consent-value="false"', $output);
        self::assertStringNotContainsString('data-order-submit-target="consentHint"', $output);
    }

    public function testRedirectModeKeepsTheVisibleButton(): void
    {
        $output = $this->renderOrderPage(iframe: false, agbActive: true, priorConsent: false);
        $button = $this->extractButtonTag($output);

        self::assertStringNotContainsString('hidden', $button, 'redirect mode: the button is the trigger');
        self::assertStringContainsString('data-order-submit-render-mode-value="redirect"', $output);
        self::assertStringContainsString('data-order-submit-mount-on-consent-value="false"', $output);
        self::assertStringNotContainsString('data-order-submit-target="consentHint"', $output);
    }

    private function renderOrderPage(bool $iframe, bool $agbActive, bool $priorConsent): string
    {
        $renderer = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer();

        $output = $renderer->renderTemplate(self::TEMPLATE, [
            'oView' => new IframeConsentProbeView($agbActive, $priorConsent),
            'oViewConf' => new IframeConsentProbeViewConf($iframe),
            'oxcmp_basket' => new IframeConsentProbeBasket(),
        ]);

        self::assertNotSame(
            self::TEMPLATE,
            trim($output),
            'the shop renderer returned the template name — no frontend theme in this environment'
        );

        return $output;
    }

    private function extractButtonTag(string $output): string
    {
        // Attribute values may contain '>' (data-action="click->…"), so the tag
        // ends at the first '>' outside a quoted value.
        $matched = preg_match('/<button\s+id="stripe-checkout-btn"(?:[^>"]|"[^"]*")*>/s', $output, $m);
        self::assertSame(1, $matched, 'the Place-Order button must be in the DOM in every mode');

        return $m[0];
    }
}
