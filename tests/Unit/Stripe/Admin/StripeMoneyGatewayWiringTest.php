<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Payments\Stripe\Admin\StripeMoneyGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * VR-22 A5: without the `oe.payment.gateway` tag payment-base finds no gateway for Stripe and refuses every admin
 * capture, refund and void as `provider_unavailable`.
 */
final class StripeMoneyGatewayWiringTest extends TestCase
{
    public function testTheGatewayIsTaggedForPaymentBasesRegistry(): void
    {
        $services = Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        $definition = $services[StripeMoneyGateway::class] ?? null;

        self::assertIsArray($definition, 'services.yaml does not define the Stripe money gateway');
        self::assertContains(['name' => 'oe.payment.gateway'], $definition['tags']);
    }

    public function testTheGatewayIsWiredWithTheThreeStripeServicesItNeeds(): void
    {
        $services = Yaml::parseFile(__DIR__ . '/../../../../services.yaml', Yaml::PARSE_CUSTOM_TAGS)['services'];

        $arguments = $services[StripeMoneyGateway::class]['arguments'] ?? [];

        self::assertEqualsCanonicalizing(['$captures', '$cancellations', '$refunds'], array_keys($arguments));
    }
}
