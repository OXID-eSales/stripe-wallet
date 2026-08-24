<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\CustomerDataSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 90: Tests for customer data sanitization before Stripe API calls.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\CustomerDataSanitizer::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-90')]
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

    // ---------------------------------------------------------------------
    // Sprint 135 (S5) — mutation-hardening for the GDPR redaction path.
    // Boundary and multibyte behaviour was previously unasserted, so the
    // truncation arithmetic and the mb_* calls could be mutated undetected.
    // ---------------------------------------------------------------------

    /**
     * Kills GreaterThan at CustomerDataSanitizer:42 — a value of exactly
     * $maxLength characters must pass through untouched (`>` not `>=`).
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function valueOfExactlyMaxLengthIsNotTruncated(): void
    {
        $value = str_repeat('a', 10);

        $this->assertSame($value, $this->sanitizer->sanitize($value, 10));
    }

    /**
     * Kills IncrementInteger at :43 — truncation must keep exactly $maxLength
     * characters, not $maxLength + 1.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function overlongValueIsTruncatedToExactlyMaxLength(): void
    {
        $result = $this->sanitizer->sanitize(str_repeat('a', 11), 10);

        $this->assertSame(10, mb_strlen($result, 'UTF-8'));
        $this->assertSame(str_repeat('a', 10), $result);
    }

    /**
     * Kills IncrementInteger and DecrementInteger at :25 — the documented
     * default limit is 255 characters, and the default must be used when the
     * caller omits it.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function defaultMaxLengthIsTwoHundredFiftyFiveCharacters(): void
    {
        $this->assertSame(255, mb_strlen($this->sanitizer->sanitize(str_repeat('a', 300)), 'UTF-8'));
        $this->assertSame(255, mb_strlen($this->sanitizer->sanitize(str_repeat('a', 255)), 'UTF-8'));
    }

    /**
     * Kills MBString at :42 and :43 — length and truncation must count
     * characters, not bytes. "ä" is two bytes and one character, so a
     * byte-based implementation truncates this value early.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function truncationCountsCharactersNotBytes(): void
    {
        $value = str_repeat('ä', 10);

        $result = $this->sanitizer->sanitize($value, 10);

        $this->assertSame($value, $result);
        $this->assertSame(10, mb_strlen($result, 'UTF-8'));
        $this->assertSame(20, strlen($result));
    }

    /**
     * Kills MBString at :43 — truncating a multibyte value must cut at a
     * character boundary and never emit a broken sequence.
     */
    #[\PHPUnit\Framework\Attributes\Group('sprint-135')]
    #[\PHPUnit\Framework\Attributes\Test]
    public function multibyteTruncationCutsAtCharacterBoundary(): void
    {
        $result = $this->sanitizer->sanitize(str_repeat('ä', 12), 10);

        $this->assertSame(str_repeat('ä', 10), $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

}
