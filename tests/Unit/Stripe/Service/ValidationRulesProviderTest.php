<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\AllowedSymbolsDescriber;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Stripe\Service\ValidationRulesProvider;
use PHPUnit\Framework\TestCase;

/**
 * STRP-129: provider reads the real validation-rules.php and exposes the
 * field -> allow-token-string map used by the AllowedSymbolsDescriber.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\ValidationRulesProvider
 * @group user-data-validation
 */
final class ValidationRulesProviderTest extends TestCase
{
    private ValidationRulesProvider $sut;

    protected function setUp(): void
    {
        $this->sut = new ValidationRulesProvider();
    }

    public function testReturnsAllowStringForKnownFields(): void
    {
        $map = $this->sut->getFieldAllowMap();

        $this->assertArrayHasKey('firstName', $map);
        $this->assertSame("UNICODE_LETTERS SPACES ' - .", $map['firstName']);
        $this->assertSame('NUMBERS SPACES + - ( )', $map['phone']);
    }

    public function testCoversAllFifteenFields(): void
    {
        $this->assertCount(15, $this->sut->getFieldAllowMap());
    }

    public function testRefundDescriptionAllowString(): void
    {
        // Sprint 121 (STRP-129): refund_description is POST-reachable free text
        // into Stripe refund metadata (not present in the panel form).
        $map = $this->sut->getFieldAllowMap();

        $this->assertArrayHasKey('refundDescription', $map);
        $this->assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :", $map['refundDescription']);
    }

    public function testCaptureReasonAllowString(): void
    {
        // Sprint 120 (STRP-129): admin Payment-tab capture-reason field.
        // UNICODE_LETTERS (not LETTERS) — German admins type umlauts.
        $map = $this->sut->getFieldAllowMap();

        $this->assertArrayHasKey('captureReason', $map);
        $this->assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . , / # ( ) :", $map['captureReason']);
    }

    public function testAddressFieldsAcceptUnicodeLetters(): void
    {
        // Sprint 124 (STRP-129): the six address fields used the ASCII-only
        // LETTERS token, blocking German umlauts / Polish letters on street,
        // company, etc. They now use UNICODE_LETTERS (\p{L}). Block lists and
        // literals are unchanged.
        $map = $this->sut->getFieldAllowMap();

        $this->assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . , / #", $map['additionalInfo']);
        $this->assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . , /", $map['street']);
        $this->assertSame('NUMBERS UNICODE_LETTERS - /', $map['houseNumber']);
        $this->assertSame('UNICODE_LETTERS NUMBERS SPACES -', $map['postalCode']);
        $this->assertSame("UNICODE_LETTERS NUMBERS SPACES ' - . & ,", $map['company']);
        $this->assertSame('UNICODE_LETTERS NUMBERS SPACES -', $map['vatId']);
    }

    public function testDescribesCaptureReasonAllowedSymbols(): void
    {
        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnMap([
            ['STRIPE_VALIDATION_CLASS_LETTERS', 'letters'],
            ['STRIPE_VALIDATION_CLASS_DIGITS', 'digits'],
            ['STRIPE_VALIDATION_CLASS_SPACES', 'spaces'],
        ]);

        $describer = $this->sut->createDescriber($translator);

        $this->assertSame(
            "letters, digits, spaces, ' - . , / # ( ) :",
            $describer->describe('captureReason')
        );
    }

    public function testBuildsDescriberWithTheRealMap(): void
    {
        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnMap([
            ['STRIPE_VALIDATION_CLASS_LETTERS', 'letters'],
            ['STRIPE_VALIDATION_CLASS_DIGITS', 'digits'],
            ['STRIPE_VALIDATION_CLASS_SPACES', 'spaces'],
        ]);

        $describer = $this->sut->createDescriber($translator);

        $this->assertInstanceOf(AllowedSymbolsDescriber::class, $describer);
        $this->assertSame("letters, spaces, ' - .", $describer->describe('firstName'));
    }
}
