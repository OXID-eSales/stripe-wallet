<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\DataProtection;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies BasketSnapshot value object immutability:
 * - Private constructor (cannot be instantiated directly)
 * - No setter methods
 * - toArray() returns copy (modifications don't affect internal state)
 *
 * @covers \OxidEsales\PaymentComponent\Contract\BasketSnapshot
 * @group security
 * @group gdpr
 * @group sprint-58
 */
final class BasketSnapshotImmutabilityTest extends TestCase
{
    /**
     * @test
     *
     * Value object integrity: private constructor prevents direct instantiation.
     */
    public function testSnapshotHasPrivateConstructor(): void
    {
        $reflection = new ReflectionClass(BasketSnapshot::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate(), 'Constructor must be private');
    }

    /**
     * @test
     *
     * Value object integrity: no setter methods that could mutate state.
     */
    public function testSnapshotHasNoSetters(): void
    {
        $reflection = new ReflectionClass(BasketSnapshot::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $setters = array_filter($methods, function (\ReflectionMethod $method): bool {
            return str_starts_with($method->getName(), 'set');
        });

        $this->assertEmpty(
            $setters,
            'BasketSnapshot should have no setter methods. Found: ' .
            implode(', ', array_map(fn($m) => $m->getName(), $setters))
        );
    }

    /**
     * @test
     *
     * Value object integrity: toArray() returns a copy, not a reference.
     */
    public function testSnapshotToArrayReturnsCopy(): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [['id' => 'art_001', 'price' => 10.0]],
            'discounts' => [],
            'totalGross' => 10.0,
            'totalNet' => 8.40,
            'totalVat' => 1.60,
            'currency' => 'EUR',
        ]);

        $array1 = $snapshot->toArray();
        $array1['totalGross'] = 999.99; // Modify the returned array

        $array2 = $snapshot->toArray();

        $this->assertSame(10.0, $array2['totalGross'], 'Modifying toArray() result must not affect snapshot');
    }

    /**
     * @test
     *
     * Value object integrity: getItems() returns array copy.
     */
    public function testSnapshotItemsArrayIsCopy(): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [['id' => 'art_001', 'price' => 10.0]],
            'discounts' => [],
            'totalGross' => 10.0,
            'totalNet' => 8.40,
            'totalVat' => 1.60,
            'currency' => 'EUR',
        ]);

        $items1 = $snapshot->getItems();
        $items1[] = ['id' => 'injected_item', 'price' => 0.01];

        $items2 = $snapshot->getItems();

        $this->assertCount(1, $items2, 'Modifying getItems() result must not affect snapshot');
    }

    /**
     * @test
     */
    public function testSnapshotFromArrayValidatesRequiredFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BasketSnapshot::fromArray([
            'items' => [],
            // Missing: totalGross, totalNet, totalVat, currency
        ]);
    }
}
