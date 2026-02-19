<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\ContractStateMachine;

use DomainException;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the contract state machine rejects all illegal transitions.
 *
 * Tests a full NxM matrix of (current state) x (transition method) combinations,
 * asserting that only the valid transitions succeed and all others throw DomainException.
 *
 * @covers \OxidEsales\PaymentComponent\Contract\PaymentContract
 * @group security
 * @group pci-dss
 * @group sprint-58
 */
final class IllegalStateTransitionTest extends TestCase
{
    /**
     * Maps each state to the set of transition methods that are VALID from that state.
     * Every other combination must throw DomainException.
     *
     * @return array<string, array<string>>
     */
    private static function validTransitions(): array
    {
        return [
            'draft' => ['transitionToNotFinished', 'cancel', 'fail', 'expire'],
            'not_finished' => ['transitionToPending', 'cancel', 'fail', 'expire'],
            'pending' => ['authorize', 'cancel', 'fail', 'expire'],
            'authorized' => ['captureAuthorization', 'cancel', 'fail', 'expire'],
            'ready_to_commit' => ['cancel', 'fail', 'expire'],
            'committed' => ['fulfill', 'cancel', 'fail', 'expire'],
            'fulfilled' => [],
            'cancelled' => [],
            'expired' => [],
            'failed' => [],
        ];
    }

    /**
     * Arguments needed for transition methods that require parameters.
     *
     * @return array<string, array<mixed>>
     */
    private static function transitionArgs(): array
    {
        return [
            'transitionToNotFinished' => ['order_001'],
            'transitionToPending' => [],
            'authorize' => [],
            'captureAuthorization' => [],
            'fulfill' => [],
            'cancel' => ['reason'],
            'fail' => ['reason'],
            'expire' => [],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidTransitionProvider(): array
    {
        $cases = [];
        $allMethods = SecurityTestHelper::allTransitionMethods();
        $validMap = self::validTransitions();

        foreach (SecurityTestHelper::allStates() as $state) {
            $validForState = $validMap[$state] ?? [];
            foreach ($allMethods as $method) {
                if (!in_array($method, $validForState, true)) {
                    $cases["{$state} -> {$method}()"] = [$state, $method];
                }
            }
        }

        return $cases;
    }

    /**
     * @test
     * @dataProvider invalidTransitionProvider
     *
     * Compliance: PCI DSS 6.5.10 — Broken authentication and session management
     */
    public function testInvalidTransitionsThrowDomainException(string $state, string $method): void
    {
        $contract = SecurityTestHelper::createContractInState($state);
        $args = self::transitionArgs()[$method] ?? [];

        $this->expectException(DomainException::class);

        $contract->$method(...$args);
    }

    /**
     * @test
     *
     * Sanity: verifies we are testing a significant number of invalid transitions.
     */
    public function testInvalidTransitionMatrixHasExpectedSize(): void
    {
        $cases = self::invalidTransitionProvider();

        // 10 states x 8 methods = 80 total; minus ~24 valid = ~56 invalid
        $this->assertGreaterThan(50, count($cases), 'Should test at least 50 invalid transitions');
    }
}
