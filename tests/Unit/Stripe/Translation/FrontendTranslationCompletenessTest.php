<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Translation;

use PHPUnit\Framework\TestCase;

/**
 * Sprint 91: Verifies all required frontend translation keys exist in EN and DE.
 */
#[\PHPUnit\Framework\Attributes\Group('sprint-91')]
final class FrontendTranslationCompletenessTest extends TestCase
{
    /** Keys used in base_stripe_element_config.html.twig + payment.html.twig */
    private const REQUIRED_KEYS = [
        'OSC_STRIPE_CARD_NUMBER',
        'OSC_STRIPE_CARD_EXDATE',
        'OSC_STRIPE_CARD_CVC',
        'OSC_STRIPE_CARD_NAME',
        'OSC_STRIPE_ERROR_MISSING_NAME',
        'OSC_STRIPE_ERROR_MISSING_NUMBER',
        'OSC_STRIPE_ERROR_MISSING_CVC',
        'OSC_STRIPE_ERROR_MISSING_EXDATE',
        'OSC_STRIPE_ERROR_INBOX',
        'OSC_STRIPE_UNKNOWN_ERROR',
        'OSC_STRIPE_AUTHORIZATION_DENIED_ERROR',
        'OSC_STRIPE_VAULTING_VAULTED_PAYMENTS',
        'OSC_STRIPE_CONTINUE_TO_NEXT_STEP',
    ];

    public function testAllRequiredKeysExistInEnglish(): void
    {
        $translations = $this->loadTranslations('en');
        $missing = $this->findMissingKeys($translations);

        $this->assertSame([], $missing, 'Missing EN translation keys: ' . implode(', ', $missing));
    }

    public function testAllRequiredKeysExistInGerman(): void
    {
        $translations = $this->loadTranslations('de');
        $missing = $this->findMissingKeys($translations);

        $this->assertSame([], $missing, 'Missing DE translation keys: ' . implode(', ', $missing));
    }

    public function testNoEmptyTranslationValuesInEnglish(): void
    {
        $translations = $this->loadTranslations('en');
        $empty = $this->findEmptyValues($translations);

        $this->assertSame([], $empty, 'Empty EN translation values: ' . implode(', ', $empty));
    }

    public function testNoEmptyTranslationValuesInGerman(): void
    {
        $translations = $this->loadTranslations('de');
        $empty = $this->findEmptyValues($translations);

        $this->assertSame([], $empty, 'Empty DE translation values: ' . implode(', ', $empty));
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslations(string $lang): array
    {
        $file = dirname(__DIR__, 4) . '/translations/' . $lang . '/stripe_lang.php';
        $this->assertFileExists($file, "Translation file not found: {$file}");

        $aLang = [];
        include $file;

        return $aLang;
    }

    /**
     * @param array<string, string> $translations
     * @return string[]
     */
    private function findMissingKeys(array $translations): array
    {
        $missing = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $translations)) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * @param array<string, string> $translations
     * @return string[]
     */
    private function findEmptyValues(array $translations): array
    {
        $empty = [];
        foreach (self::REQUIRED_KEYS as $key) {
            if (array_key_exists($key, $translations) && trim($translations[$key]) === '') {
                $empty[] = $key;
            }
        }
        return $empty;
    }
}
