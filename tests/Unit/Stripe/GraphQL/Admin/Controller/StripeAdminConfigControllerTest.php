<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Controller;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Controller\StripeAdminConfigController;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminSettingsInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigSnapshot;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ConfigFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class StripeAdminConfigControllerTest extends TestCase
{
    public function testEveryOperationNeedsTheConfigRightAndNoneIsLogged(): void
    {
        foreach (['adminStripeConfig' => Query::class] as $method => $operation) {
            $reflection = new ReflectionMethod(StripeAdminConfigController::class, $method);

            $rights = $reflection->getAttributes(Right::class);
            self::assertCount(1, $rights, $method);
            self::assertSame('ADMIN_PAYMENT_CONFIG', $rights[0]->getArguments()[0] ?? $rights[0]->getArguments()['name'] ?? null, $method);
            self::assertCount(1, $reflection->getAttributes($operation), $method);
            self::assertSame([], $reflection->getAttributes('TheCodingMachine\\GraphQLite\\Annotations\\Logged'), 'admin operations carry #[Right] only');
        }
    }

    public function testAdminStripeConfig_ReturnsTheSettingsWithoutAnySecretValue(): void
    {
        $settings = new class implements StripeAdminSettingsInterface {
            public function snapshot(): StripeConfigSnapshot
            {
                return ConfigFixtures::snapshot();
            }
        };

        $config = (new StripeAdminConfigController($settings))->adminStripeConfig();

        self::assertSame('test', $config->getMode());
        self::assertTrue($config->isTestTokenSet());
        self::assertFalse($config->isLiveTokenSet());
    }
}
