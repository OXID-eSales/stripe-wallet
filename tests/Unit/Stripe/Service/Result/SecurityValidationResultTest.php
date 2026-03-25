<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service\Result;

use OxidEsales\Payments\Stripe\Service\Result\SecurityValidationResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\OxidEsales\Payments\Stripe\Service\Result\SecurityValidationResult::class)]
class SecurityValidationResultTest extends TestCase
{
    public function testCreateWithScore(): void
    {
        $result = new SecurityValidationResult(85, [], true);

        $this->assertEquals(85, $result->getScore());
        $this->assertTrue($result->isAllowed());
        $this->assertEmpty($result->getWarnings());
    }

    public function testCreateWithWarnings(): void
    {
        $warnings = ['ip_changed', 'slow_return'];
        $result = new SecurityValidationResult(60, $warnings, true);

        $this->assertEquals(['ip_changed', 'slow_return'], $result->getWarnings());
    }

    public function testIsAllowedReflectsConstructorValue(): void
    {
        $allowed = new SecurityValidationResult(80, [], true);
        $blocked = new SecurityValidationResult(30, [], false);

        $this->assertTrue($allowed->isAllowed());
        $this->assertFalse($blocked->isAllowed());
    }

    public function testToArrayReturnsAllData(): void
    {
        $result = new SecurityValidationResult(75, ['ip_changed'], true);

        $array = $result->toArray();

        $this->assertEquals(75, $array['score']);
        $this->assertEquals(['ip_changed'], $array['warnings']);
        $this->assertTrue($array['allowed']);
    }

    public function testScoreIsBoundedAt0(): void
    {
        $low = new SecurityValidationResult(-10, [], false);

        $this->assertEquals(0, $low->getScore());
    }

    public function testScoreIsBoundedAt100(): void
    {
        $high = new SecurityValidationResult(150, [], true);

        $this->assertEquals(100, $high->getScore());
    }

    public function testHasWarningReturnsTrueWhenWarningExists(): void
    {
        $result = new SecurityValidationResult(70, ['ip_changed', 'slow_return'], true);

        $this->assertTrue($result->hasWarning('ip_changed'));
        $this->assertTrue($result->hasWarning('slow_return'));
        $this->assertFalse($result->hasWarning('country_changed'));
    }

    public function testGetWarningCountReturnsCorrectCount(): void
    {
        $noWarnings = new SecurityValidationResult(100, [], true);
        $twoWarnings = new SecurityValidationResult(60, ['a', 'b'], true);

        $this->assertEquals(0, $noWarnings->getWarningCount());
        $this->assertEquals(2, $twoWarnings->getWarningCount());
    }
}
