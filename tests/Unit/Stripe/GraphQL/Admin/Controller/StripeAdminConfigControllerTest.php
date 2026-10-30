<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Controller;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Controller\StripeAdminConfigController;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ConfigFixtures;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryStripeSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class StripeAdminConfigControllerTest extends TestCase
{
    public function testTheQueryNeedsTheConfigRightAndIsNotLogged(): void
    {
        $reflection = new ReflectionMethod(StripeAdminConfigController::class, 'adminStripeConfig');

        $rights = $reflection->getAttributes(Right::class);
        self::assertCount(1, $rights);
        self::assertSame('ADMIN_PAYMENT_CONFIG', $rights[0]->getArguments()[0] ?? $rights[0]->getArguments()['name'] ?? null);
        self::assertCount(1, $reflection->getAttributes(Query::class));
        self::assertSame([], $reflection->getAttributes('TheCodingMachine\\GraphQLite\\Annotations\\Logged'), 'admin operations carry #[Right] only');
    }

    public function testTheControllerHasNoMutation_TheSettingsChangeInTheClassicAdminOnly(): void
    {
        foreach ((new ReflectionClass(StripeAdminConfigController::class))->getMethods() as $method) {
            self::assertSame([], $method->getAttributes(Mutation::class), $method->getName());
        }
    }

    public function testAdminStripeConfig_ReturnsTheSettingsWithoutAnySecretValue(): void
    {
        $config = (new StripeAdminConfigController(new InMemoryStripeSettings(ConfigFixtures::snapshot())))->adminStripeConfig();

        self::assertSame('test', $config->getMode());
        self::assertTrue($config->isTestTokenSet());
        self::assertFalse($config->isLiveTokenSet());
    }
}
