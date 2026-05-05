<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Admin;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Sprint 93 / Probe 3 — once `oe_payment_component` is properly
 * installed and activated in CI, its `oe.payment.admin_panel` tagged
 * iterator must collect the Stripe panel provider.
 *
 * If this test fails the most likely cause is that the
 * `install_shop_with_module` workflow either did not activate
 * `oe_payment_component` before activating `oe_payments_stripe_wallet`,
 * or the autoload classmap was stale when the activation ran.
 *
 * @group integration
 * @group sprint-93
 */
final class PaymentPanelRegistryIntegrationTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->container = ContainerFactory::getInstance()->getContainer();
        } catch (\Throwable $e) {
            self::markTestSkipped(
                'OXID container could not be initialised — '
                . 'integration tests require a fully booted shop. '
                . 'Error: ' . $e->getMessage()
            );
        }
    }

    public function testRegistryResolvesStripeProviderByName(): void
    {
        $registry = $this->container->get(PaymentPanelRegistryInterface::class);

        $provider = $registry->resolveByProviderName(StripePaymentPanelProvider::PROVIDER_KEY);

        self::assertNotNull(
            $provider,
            'PaymentPanelRegistry returned null for provider key '
            . '"' . StripePaymentPanelProvider::PROVIDER_KEY . '". '
            . 'Likely cause: oe_payment_component was not activated '
            . 'before oe_payments_stripe_wallet in CI — see Sprint 93.'
        );
        self::assertInstanceOf(StripePaymentPanelProvider::class, $provider);
    }
}
