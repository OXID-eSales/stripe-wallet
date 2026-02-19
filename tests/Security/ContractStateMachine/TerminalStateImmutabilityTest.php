<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\ContractStateMachine;

use DomainException;
use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that terminal states (fulfilled, cancelled, expired, failed) are truly immutable.
 *
 * No transition should be possible from a terminal state.
 *
 * @covers \OxidEsales\PaymentComponent\Contract\PaymentContract
 * @group security
 * @group pci-dss
 * @group sprint-58
 */
final class TerminalStateImmutabilityTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<mixed>}>
     */
    public static function terminalStateTransitionProvider(): array
    {
        $cases = [];
        $terminalStates = SecurityTestHelper::terminalStates();
        $transitions = [
            'transitionToNotFinished' => ['order_x'],
            'transitionToPending' => [],
            'authorize' => [],
            'captureAuthorization' => [],
            'fulfill' => [],
            'cancel' => ['reason'],
            'fail' => ['reason'],
            'expire' => [],
        ];

        foreach ($terminalStates as $state) {
            foreach ($transitions as $method => $args) {
                $cases["{$state} -> {$method}()"] = [$state, $method, $args];
            }
        }

        return $cases;
    }

    /**
     * @test
     * @dataProvider terminalStateTransitionProvider
     *
     * Compliance: PCI DSS 10.2 — Audit trail integrity
     *
     * @param array<mixed> $args
     */
    public function testTerminalStateRejectsAllTransitions(string $state, string $method, array $args): void
    {
        $contract = SecurityTestHelper::createContractInState($state);

        $this->expectException(DomainException::class);

        $contract->$method(...$args);
    }

    /**
     * @test
     */
    public function testTerminalStateCannotBeOverwrittenByCancel(): void
    {
        $contract = SecurityTestHelper::createContractInState('fulfilled');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('terminal');

        $contract->cancel('attempt to override fulfilled');
    }

    /**
     * @test
     */
    public function testTerminalStateCannotBeFailed(): void
    {
        $contract = SecurityTestHelper::createContractInState('cancelled');

        $this->expectException(DomainException::class);

        $contract->fail('attempt to override cancelled');
    }

    /**
     * @test
     */
    public function testTerminalStatesCount(): void
    {
        $this->assertCount(4, SecurityTestHelper::terminalStates());
    }
}
