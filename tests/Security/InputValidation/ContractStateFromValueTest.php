<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use InvalidArgumentException;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that ContractState::fromValue() rejects invalid input.
 *
 * @covers \OxidEsales\PaymentComponent\Contract\ContractState
 * @group security
 * @group sprint-58
 */
final class ContractStateFromValueTest extends TestCase
{
    /**
     * @test
     */
    public function testRejectsInvalidStateString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid contract state');

        ContractState::fromValue('hacked');
    }

    /**
     * @test
     */
    public function testRejectsEmptyStateString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContractState::fromValue('');
    }

    /**
     * @test
     */
    public function testRejectsSqlInjectionInStateValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContractState::fromValue("'; DROP TABLE--");
    }

    /**
     * @test
     */
    public function testRejectsXssInStateValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContractState::fromValue('<script>alert(1)</script>');
    }

    /**
     * @test
     */
    public function testRejectsNullByteInStateValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContractState::fromValue("draft\x00hacked");
    }

    /**
     * @test
     * @dataProvider validStateProvider
     */
    public function testAcceptsAllValidStates(string $state): void
    {
        $contractState = ContractState::fromValue($state);

        $this->assertSame($state, $contractState->getValue());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validStateProvider(): array
    {
        $cases = [];
        foreach (SecurityTestHelper::allStates() as $state) {
            $cases[$state] = [$state];
        }
        return $cases;
    }

    /**
     * @test
     */
    public function testRejectsCaseSensitiveVariation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContractState::fromValue('Draft'); // Capital D
    }

    /**
     * @test
     */
    public function testRejectsWhitespaceInState(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContractState::fromValue(' draft ');
    }
}
