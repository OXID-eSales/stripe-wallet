<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\StripeLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shop's language abbreviation -> the `locale` of a Stripe Checkout Session.
 * Stripe falls back to the BROWSER's language ('auto') when a session has no locale.
 */
final class StripeLocaleTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function abbreviations(): array
    {
        return [
            'german' => ['de', 'de'],
            'english' => ['en', 'en'],
            'french' => ['fr', 'fr'],
            'case is ignored' => ['DE', 'de'],
            'surrounding space is ignored' => [' en ', 'en'],
            'regional variant Stripe knows' => ['pt-br', 'pt-BR'],
            'a language Stripe does not offer' => ['xx', 'auto'],
            'empty' => ['', 'auto'],
        ];
    }

    #[DataProvider('abbreviations')]
    public function testMapsTheShopLanguageToAStripeLocale(string $abbreviation, string $expected): void
    {
        $this->assertSame($expected, StripeLocale::forLanguage($abbreviation));
    }
}
