<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\AllowedSymbolsDescriber;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * STRP-129: human-readable "allowed symbols" rendering per field.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\AllowedSymbolsDescriber::class)]
#[\PHPUnit\Framework\Attributes\Group('user-data-validation')]
final class AllowedSymbolsDescriberTest extends TestCase
{
    private LanguageTranslatorInterface&MockObject $translator;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(LanguageTranslatorInterface::class);
        $this->translator->method('translateString')->willReturnMap([
            ['STRIPE_VALIDATION_CLASS_LETTERS', 'letters'],
            ['STRIPE_VALIDATION_CLASS_DIGITS', 'digits'],
            ['STRIPE_VALIDATION_CLASS_SPACES', 'spaces'],
        ]);
    }

    private function describer(array $allowMap): AllowedSymbolsDescriber
    {
        return new AllowedSymbolsDescriber($this->translator, $allowMap);
    }

    public function testRendersClassWordsThenLiteralSymbols(): void
    {
        $sut = $this->describer(['firstName' => "UNICODE_LETTERS SPACES ' - ."]);

        $this->assertSame("letters, spaces, ' - .", $sut->describe('firstName'));
    }

    public function testRendersDigitsAndPhoneLiterals(): void
    {
        $sut = $this->describer(['phone' => 'NUMBERS SPACES + - ( )']);

        $this->assertSame('digits, spaces, + - ( )', $sut->describe('phone'));
    }

    public function testRendersLettersAndDigitsWithLiterals(): void
    {
        $sut = $this->describer(['houseNumber' => 'NUMBERS LETTERS - /']);

        $this->assertSame('digits, letters, - /', $sut->describe('houseNumber'));
    }

    public function testDeduplicatesEquivalentLetterClasses(): void
    {
        $sut = $this->describer(['weird' => 'UNICODE_LETTERS LETTERS -']);

        $this->assertSame('letters, -', $sut->describe('weird'));
    }

    public function testOnlyLiterals(): void
    {
        $sut = $this->describer(['x' => "' - ."]);

        $this->assertSame("' - .", $sut->describe('x'));
    }

    public function testUnknownFieldReturnsEmptyString(): void
    {
        $sut = $this->describer(['firstName' => 'LETTERS']);

        $this->assertSame('', $sut->describe('doesNotExist'));
    }
}
