<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Controller;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Controller\StripeAdminWebhookController;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookAdministrator;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ConfigFixtures;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryStripeSettings;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryWebhookState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Query;
use TheCodingMachine\GraphQLite\Annotations\Right;

final class StripeAdminWebhookControllerTest extends TestCase
{
    public function testTheOnlyOperationIsTheStatusQuery_AndItNeedsTheConfigRight(): void
    {
        $operations = [];
        foreach ((new ReflectionClass(StripeAdminWebhookController::class))->getMethods() as $method) {
            self::assertSame([], $method->getAttributes(Mutation::class), $method->getName() . ' must not be a mutation');
            if ($method->getAttributes(Query::class) !== []) {
                $operations[] = $method->getName();
                self::assertCount(1, $method->getAttributes(Right::class));
            }
        }

        self::assertSame(['adminStripeWebhookStatus'], $operations);
    }

    public function testStatus_ReportsTheModeTheEndpointAndWhetherASecretIsStoredNeverTheSecret(): void
    {
        $controller = new StripeAdminWebhookController(new StripeWebhookAdministrator(
            new InMemoryStripeSettings(ConfigFixtures::snapshot()),
            new InMemoryWebhookState(['test' => 'we_123'], ['test' => 'whsec_storedsecret']),
        ));

        $status = $controller->adminStripeWebhookStatus();

        self::assertSame('test', $status->getMode());
        self::assertTrue($status->isConfigured());
        self::assertSame('we_123', $status->getEndpointId());
        self::assertStringNotContainsString('whsec_', (string) json_encode([$status->getMode(), $status->getEndpointId(), $status->getUrl()]));
    }
}
