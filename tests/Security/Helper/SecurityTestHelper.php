<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Helper;

use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;

/**
 * Helper utilities for security tests.
 *
 * Provides factory methods for creating contracts in specific states,
 * timing measurements for constant-time comparison tests, and
 * state enumeration helpers.
 *
 * @since Sprint 58
 */
final class SecurityTestHelper
{
    /**
     * Create a contract in any valid state by walking the state machine legitimately.
     *
     * @throws \DomainException if targetState is not reachable
     */
    public static function createContractInState(string $targetState): PaymentContract
    {
        $snapshot = self::createMinimalSnapshot();
        $contract = new PaymentContract(1, 'test_user', $snapshot);

        if ($targetState === 'draft') {
            return $contract;
        }

        // Add conditions needed for state transitions
        $contract->addCondition(ContractCondition::paymentAuthorized());

        if ($targetState === 'not_finished') {
            $contract->transitionToNotFinished('order_001');
            return $contract;
        }

        $contract->transitionToNotFinished('order_001');
        $contract->transitionToPending();

        if ($targetState === 'pending') {
            return $contract;
        }

        if ($targetState === 'authorized') {
            $contract->authorize();
            return $contract;
        }

        // For ready_to_commit via condition fulfillment
        if ($targetState === 'ready_to_commit') {
            $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
            return $contract;
        }

        // For committed
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        if ($targetState === 'committed') {
            $contract->commitToOrder('order_001');
            return $contract;
        }

        // For fulfilled
        if ($targetState === 'fulfilled') {
            $contract->commitToOrder('order_001');
            $contract->fulfill();
            return $contract;
        }

        // Terminal states from pending
        if ($targetState === 'cancelled') {
            $contract->cancel('test cancellation');
            return $contract;
        }

        if ($targetState === 'expired') {
            $contract->expire();
            return $contract;
        }

        if ($targetState === 'failed') {
            $contract->fail('test failure');
            return $contract;
        }

        throw new \DomainException("Cannot create contract in state: {$targetState}");
    }

    /**
     * Create a minimal BasketSnapshot for testing.
     */
    public static function createMinimalSnapshot(float $amount = 99.99): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [
                ['id' => 'art_001', 'title' => 'Test Article', 'quantity' => 1, 'price' => $amount],
            ],
            'discounts' => [],
            'totalGross' => $amount,
            'totalNet' => round($amount / 1.19, 2),
            'totalVat' => round($amount - ($amount / 1.19), 2),
            'currency' => 'EUR',
        ]);
    }

    /**
     * Measure execution time of a callable over N iterations.
     *
     * @return float Average time in nanoseconds
     */
    public static function measureExecutionTime(callable $fn, int $iterations = 1000): float
    {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $fn();
        }
        $end = hrtime(true);

        return ($end - $start) / $iterations;
    }

    /**
     * All valid state strings.
     *
     * @return array<string>
     */
    public static function allStates(): array
    {
        return [
            'draft',
            'not_finished',
            'pending',
            'authorized',
            'ready_to_commit',
            'committed',
            'fulfilled',
            'cancelled',
            'expired',
            'failed',
        ];
    }

    /**
     * All terminal state strings.
     *
     * @return array<string>
     */
    public static function terminalStates(): array
    {
        return ['fulfilled', 'cancelled', 'expired', 'failed'];
    }

    /**
     * All transition method names on PaymentContract.
     *
     * @return array<string>
     */
    public static function allTransitionMethods(): array
    {
        return [
            'transitionToNotFinished',
            'transitionToPending',
            'authorize',
            'captureAuthorization',
            'fulfill',
            'cancel',
            'fail',
            'expire',
        ];
    }
}
