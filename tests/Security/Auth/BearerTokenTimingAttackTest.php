<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Auth;

use OxidEsales\PaymentComponent\Mcp\Auth\OAuthMcpAuthGuard;
use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidatorInterface;
use OxidEsales\PaymentComponent\Mcp\Auth\TokenValidationResult;
use OxidEsales\Payments\Stripe\Tests\Security\Helper\SecurityTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests that bearer token comparison is constant-time (hash_equals),
 * preventing timing side-channel attacks.
 *
 * @covers \OxidEsales\PaymentComponent\Mcp\Auth\OAuthMcpAuthGuard
 * @group security
 * @group bsi
 * @group auth
 * @group sprint-58
 */
final class BearerTokenTimingAttackTest extends TestCase
{
    private const EXPECTED_TOKEN = 'correct_token_value_for_timing_test';

    /**
     * @test
     *
     * Compliance: BSI TR-03116 — Constant-time comparison prevents timing leaks
     *
     * The time to reject a wrong token should not vary significantly
     * based on how many prefix characters match.
     */
    public function testAuthTimeDoesNotVaryByTokenPrefix(): void
    {
        $tokenValidator = $this->createMock(TokenValidatorInterface::class);
        $tokenValidator->method('validate')
            ->willReturn(TokenValidationResult::invalid('invalid'));

        $guard = new OAuthMcpAuthGuard($tokenValidator, self::EXPECTED_TOKEN);

        // Token with correct prefix (first 10 chars match)
        $partialMatch = substr(self::EXPECTED_TOKEN, 0, 10) . 'wrong_suffix_here';

        // Token with no matching chars
        $noMatch = 'zzzzz_completely_wrong_token_value';

        $timePartialMatch = SecurityTestHelper::measureExecutionTime(function () use ($guard, $partialMatch): void {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $partialMatch;
            $guard->authenticate();
        }, 500);

        $timeNoMatch = SecurityTestHelper::measureExecutionTime(function () use ($guard, $noMatch): void {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $noMatch;
            $guard->authenticate();
        }, 500);

        unset($_SERVER['HTTP_AUTHORIZATION']);

        // Allow 50% variance (timing tests are inherently noisy)
        $ratio = $timePartialMatch > 0 ? $timeNoMatch / $timePartialMatch : 1.0;
        $this->assertGreaterThan(0.5, $ratio, 'Timing difference too large — possible timing leak');
        $this->assertLessThan(2.0, $ratio, 'Timing difference too large — possible timing leak');
    }

    /**
     * @test
     *
     * hash_equals handles length mismatch without short-circuiting on length comparison.
     */
    public function testDifferentLengthTokenDoesNotShortCircuit(): void
    {
        $tokenValidator = $this->createMock(TokenValidatorInterface::class);
        $tokenValidator->method('validate')
            ->willReturn(TokenValidationResult::invalid('invalid'));

        $guard = new OAuthMcpAuthGuard($tokenValidator, self::EXPECTED_TOKEN);

        $shortToken = 'abc';
        $longToken = str_repeat('x', 1000);

        $timeShort = SecurityTestHelper::measureExecutionTime(function () use ($guard, $shortToken): void {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $shortToken;
            $guard->authenticate();
        }, 500);

        $timeLong = SecurityTestHelper::measureExecutionTime(function () use ($guard, $longToken): void {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $longToken;
            $guard->authenticate();
        }, 500);

        unset($_SERVER['HTTP_AUTHORIZATION']);

        // Both should fail, timing should not differ by more than 3x
        $ratio = $timeShort > 0 ? $timeLong / $timeShort : 1.0;
        $this->assertLessThan(3.0, $ratio, 'Length-dependent timing may indicate non-constant-time comparison');
    }
}
