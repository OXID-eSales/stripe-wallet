<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\GraphQL\Admin\Contract\PaymentOwnershipProviderInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripePaymentOwnershipProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[\PHPUnit\Framework\Attributes\CoversClass(StripePaymentOwnershipProvider::class)]
final class StripePaymentOwnershipProviderTest extends TestCase
{
    public function testItIsAnOwnershipProviderNamedStripe(): void
    {
        $provider = new StripePaymentOwnershipProvider();

        $this->assertInstanceOf(PaymentOwnershipProviderInterface::class, $provider);
        $this->assertSame('stripe', $provider->providerName());
    }

    public function testItOwnsTheWalletPaymentRow(): void
    {
        $this->assertSame(
            [StripeDefinitions::STRIPE_WALLET_PAYMENT_ID],
            (new StripePaymentOwnershipProvider())->paymentIds()
        );
        $this->assertSame(['oe_payments_stripe_wallet'], (new StripePaymentOwnershipProvider())->paymentIds());
    }

    public function testItIsTaggedForPaymentBase(): void
    {
        $services = Yaml::parseFile(__DIR__ . '/../../../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        $this->assertSame(
            ['oe.payment.ownership_provider'],
            $services[StripePaymentOwnershipProvider::class]['tags']
        );
    }
}
