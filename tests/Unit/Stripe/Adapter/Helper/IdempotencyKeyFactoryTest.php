<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use OxidEsales\Payments\Stripe\Adapter\Helper\IdempotencyKeyFactory;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 133 · Story 2 (F2) — request-scoped idempotency keys.
 *
 * The old keys were 'refund:{paymentIntentId}' / 'refund_charge:{chargeId}',
 * i.e. one key per payment rather than per refund request, so a second
 * legitimate partial refund collided with the first.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(IdempotencyKeyFactory::class)]
#[\PHPUnit\Framework\Attributes\Group('idempotency')]
final class IdempotencyKeyFactoryTest extends TestCase
{
    public function testSameInputsProduceSameKey(): void
    {
        $a = IdempotencyKeyFactory::forRefund('pi_1', 1000, 'duplicate', 'state:0');
        $b = IdempotencyKeyFactory::forRefund('pi_1', 1000, 'duplicate', 'state:0');

        $this->assertSame($a, $b, 'A retry of the same request must dedupe.');
    }

    public function testDifferentAmountsProduceDifferentKeys(): void
    {
        $a = IdempotencyKeyFactory::forRefund('pi_1', 1000, null, 'state:0');
        $b = IdempotencyKeyFactory::forRefund('pi_1', 2000, null, 'state:0');

        $this->assertNotSame($a, $b);
    }

    public function testDifferentPriorStateProducesDifferentKeys(): void
    {
        // Two legitimate, identical partial refunds: the second one happens
        // when 1000 minor units are already refunded, so it must not collide.
        $first = IdempotencyKeyFactory::forRefund('pi_1', 1000, null, 'state:0');
        $second = IdempotencyKeyFactory::forRefund('pi_1', 1000, null, 'state:1000');

        $this->assertNotSame($first, $second);
    }

    public function testDifferentReasonsProduceDifferentKeys(): void
    {
        $a = IdempotencyKeyFactory::forRefund('pi_1', 1000, 'duplicate', 'state:0');
        $b = IdempotencyKeyFactory::forRefund('pi_1', 1000, 'fraudulent', 'state:0');

        $this->assertNotSame($a, $b);
    }

    public function testByChargeKeysAreNamespacedSeparatelyFromPaymentIntentKeys(): void
    {
        $pi = IdempotencyKeyFactory::forRefund('x_1', 1000, null, 'state:0');
        $charge = IdempotencyKeyFactory::forRefundByCharge('x_1', 1000, null, 'state:0');

        $this->assertNotSame($pi, $charge);
        $this->assertStringStartsWith('refund:', $pi);
        $this->assertStringStartsWith('refund_charge:', $charge);
    }

    public function testKeyStaysGreppableAndFitsTheColumn(): void
    {
        // oe_payments_idempotency.OXKEY is VARCHAR(128) with a UNIQUE index.
        $key = IdempotencyKeyFactory::forRefund('pi_3MtwBwLkdIwHu7ix28a3tqPa', 999999, 'requested_by_customer', 'state:123456');

        $this->assertLessThanOrEqual(128, strlen($key));
        $this->assertStringContainsString('pi_3MtwBwLkdIwHu7ix28a3tqPa', $key, 'Key must stay debuggable.');
    }

    public function testCaptureKeyIsStableAndPaymentScoped(): void
    {
        // Stripe allows one capture per PaymentIntent, so payment-scoped is correct here.
        $this->assertSame(
            IdempotencyKeyFactory::forCapture('pi_1'),
            IdempotencyKeyFactory::forCapture('pi_1')
        );
        $this->assertSame('capture:pi_1', IdempotencyKeyFactory::forCapture('pi_1'));
    }
}
