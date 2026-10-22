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
 * mapper; every type they use (results, the `guest` input) is payment-base's and
 * mapped by payment-base, so this module maps no type namespace.
 */
final class NamespaceMapperTest extends TestCase
{
    public function testMapsTheControllerNamespaceToAnExistingDirectoryAndNoTypeNamespace(): void
    {
        $mapper = new NamespaceMapper();

        $controllers = $mapper->getControllerNamespaceMapping();
        self::assertArrayHasKey('OxidEsales\\Payments\\Stripe\\GraphQL\\Controller', $controllers);
        self::assertDirectoryExists($controllers['OxidEsales\\Payments\\Stripe\\GraphQL\\Controller']);

        self::assertSame([], $mapper->getTypeNamespaceMapping(), 'the input types live in payment-base, once');
    }
}
