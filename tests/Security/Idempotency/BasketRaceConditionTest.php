<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Idempotency;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F17: Race Condition in Basket-to-Payment Flow
 *
 * HIGH — OWASP A04:2021
 *
 * Between getBasketFromSession() validation and checkout session creation,
 * basket can be modified in a concurrent request (multi-tab attack).
 * No content hash or optimistic lock exists.
 *
 * @group security
 * @group f17
 * @since Sprint 60
 */
class BasketRaceConditionTest extends TestCase
{
    /**
     * F17: StripeOrderController has no basket content hash mechanism.
     */
    public function testNoBasketContentHashInController(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Controller/StripeOrderController.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // No content hash or checksum mechanism
        $this->assertStringNotContainsString(
            'contentHash',
            $source,
            'F17: No content hash mechanism exists'
        );
        $this->assertStringNotContainsString(
            'basket_hash',
            $source,
            'F17: No basket hash mechanism exists'
        );
    }

    /**
     * F17: No optimistic locking on basket operations.
     */
    public function testNoOptimisticLockingOnBasket(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Controller/StripeOrderController.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        $this->assertStringNotContainsString(
            'version',
            strtolower($source),
            'F17: No version/optimistic locking field'
        );
        $this->assertStringNotContainsString(
            'SELECT FOR UPDATE',
            strtoupper($source),
            'F17: No pessimistic locking'
        );
    }

    /**
     * F17: Basket snapshot is taken independently of checkout session creation.
     *
     * Documents that the basket read and the checkout creation are two
     * separate operations with no atomicity guarantee.
     */
    public function testBasketReadAndCheckoutAreNotAtomic(): void
    {
        $sourceFile = dirname(__DIR__, 3)
            . '/src/Stripe/Controller/StripeOrderController.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // Basket is retrieved from session (read operation)
        $this->assertStringContainsString(
            'getBasket',
            $source,
            'Basket is read from session'
        );

        // Checkout session is created separately (write operation)
        $hasCheckoutCreation = str_contains($source, 'createCheckoutSession')
            || str_contains($source, 'CheckoutSession');
        $this->assertTrue(
            $hasCheckoutCreation,
            'Checkout session creation exists separately from basket read'
        );
    }

    /**
     * Positive: BasketSnapshot is immutable once created.
     */
    public function testBasketSnapshotIsImmutableOnceCreated(): void
    {
        $reflection = new ReflectionClass(
            \OxidEsales\PaymentComponent\Contract\BasketSnapshot::class
        );

        $setters = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn(\ReflectionMethod $m) => str_starts_with($m->getName(), 'set')
        );

        $this->assertCount(
            0,
            $setters,
            'BasketSnapshot has no public setters (immutable after creation)'
        );
    }
}
