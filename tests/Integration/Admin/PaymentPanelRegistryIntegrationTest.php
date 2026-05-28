<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Admin;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Sprint 93 / Probe 3 — once `oe_payment_base` is properly
 * installed and activated in CI, its `oe.payment.admin_panel` tagged
 * iterator must collect the Stripe panel provider.
 *
 * If this test fails the most likely cause is that the
 * `install_shop_with_module` workflow either did not activate
 * `oe_payment_base` before activating `oe_payments_stripe_wallet`,
 * or the autoload classmap was stale when the activation ran.
 *
 * @group integration
 * @group sprint-93
 * @group requires-oxid-container
 */
final class PaymentPanelRegistryIntegrationTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        // T6 (Sprint 114.13): container boot failure is now a HARD test failure in this
        // suite. These tests are gated by @group requires-oxid-container and run only
        // via --testsuite Integration-with-container where a booted shop is guaranteed.
        $this->container = ContainerFactory::getInstance()->getContainer();
    }

    public function testRegistryResolvesStripeProviderByName(): void
    {
        $registry = $this->container->get(PaymentPanelRegistryInterface::class);

        $provider = $registry->resolveByProviderName(StripePaymentPanelProvider::PROVIDER_KEY);

        self::assertNotNull(
            $provider,
            'PaymentPanelRegistry returned null for provider key '
            . '"' . StripePaymentPanelProvider::PROVIDER_KEY . '". '
            . 'Likely cause: oe_payment_base was not activated '
            . 'before oe_payments_stripe_wallet in CI — see Sprint 93.'
        );
        self::assertInstanceOf(StripePaymentPanelProvider::class, $provider);
    }
}
