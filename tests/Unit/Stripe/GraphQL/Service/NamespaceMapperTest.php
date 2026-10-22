<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Service;

use OxidEsales\Payments\Stripe\GraphQL\Service\NamespaceMapper;
use PHPUnit\Framework\TestCase;

/**
 * GRAPH-QL / PS4 — graphql-base discovers the Stripe mutations through this
 * mapper; the result types are payment-base's, the only type namespace here is the
 * guest input factory (`GuestCheckoutInput`, `GuestAddressInput`).
 */
final class NamespaceMapperTest extends TestCase
{
    public function testMapsTheControllerAndTheGuestInputNamespacesToExistingDirectories(): void
    {
        $mapper = new NamespaceMapper();

        $controllers = $mapper->getControllerNamespaceMapping();
        self::assertArrayHasKey('OxidEsales\\Payments\\Stripe\\GraphQL\\Controller', $controllers);
        self::assertDirectoryExists($controllers['OxidEsales\\Payments\\Stripe\\GraphQL\\Controller']);

        $types = $mapper->getTypeNamespaceMapping();
        self::assertSame(['OxidEsales\\Payments\\Stripe\\GraphQL\\DataType'], array_keys($types));
        self::assertDirectoryExists($types['OxidEsales\\Payments\\Stripe\\GraphQL\\DataType']);
        self::assertFileExists($types['OxidEsales\\Payments\\Stripe\\GraphQL\\DataType'] . 'GuestCheckoutInputFactory.php');
    }
}
