<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\CustomerDataSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 90: Tests for customer data sanitization before Stripe API calls.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\CustomerDataSanitizer
 * @group sprint-90
 */
final class CustomerDataSanitizerTest extends TestCase
{
    private CustomerDataSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new CustomerDataSanitizer();
    }

    public function testPreservesNormalAscii(): void
    {
        $this->assertSame('John Doe', $this->sanitizer->sanitize('John Doe'));
    }

    public function testPreservesGermanUmlauts(): void
    {
        $this->assertSame('Müller-Straße', $this->sanitizer->sanitize('Müller-Straße'));
    }

    public function testPreservesAccentedCharacters(): void
    {
        $this->assertSame('José García', $this->sanitizer->sanitize('José García'));
    }

    public function testPreservesCyrillic(): void
    {
        $this->assertSame('Иванов Пётр', $this->sanitizer->sanitize('Иванов Пётр'));
    }

    public function testPreservesApostrophesAndHyphens(): void
    {
        $this->assertSame("O'Brien-Smith", $this->sanitizer->sanitize("O'Brien-Smith"));
    }

    public function testStripsControlCharacters(): void
    {
        $this->assertSame('JohnDoe', $this->sanitizer->sanitize("John\x00\x01Doe"));
    }

    public function testReplacesInvalidUtf8(): void
    {
        $result = $this->sanitizer->sanitize("Bad\xC0\xAFdata");

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'Result must be valid UTF-8');
        $this->assertStringNotContainsString("\xC0", $result);
    }

    public function testCollapsesWhitespace(): void
    {
        $this->assertSame('John Doe', $this->sanitizer->sanitize('  John   Doe  '));
    }

    public function testTruncatesToMaxLength(): void
    {
        $long = str_repeat('a', 300);
        $result = $this->sanitizer->sanitize($long, 255);

        $this->assertSame(255, mb_strlen($result));
    }

    public function testTruncatesAtCharBoundaryNotMidMultibyte(): void
    {
        // 'ä' is 2 bytes in UTF-8. Fill 254 chars + 'ä' = 255 chars
        $input = str_repeat('a', 254) . 'ä';
        $result = $this->sanitizer->sanitize($input, 255);

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'), 'Must not break multibyte char');
        $this->assertLessThanOrEqual(255, mb_strlen($result));
    }

    public function testPreservesEmoji(): void
    {
        $this->assertSame('José 😀', $this->sanitizer->sanitize('José 😀'));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }
}
