<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Translation;

use PHPUnit\Framework\TestCase;

/**
 * Verifies all required frontend translation keys exist in EN and DE (Sprint 91; reduced to the live keys in VR-17 DC-S8).
 */
#[\PHPUnit\Framework\Attributes\Group('sprint-91')]
final class FrontendTranslationCompletenessTest extends TestCase
{
    /** Keys read by stripe_i18n.html.twig (window.oStripe.i18n for the Stimulus controllers). */
    private const REQUIRED_KEYS = [
        'STRIPE_JS_AGB_REQUIRED',
        'STRIPE_JS_PAYMENT_FAILED',
        'STRIPE_JS_NOT_LOADED',
        'STRIPE_JS_KEY_NOT_CONFIGURED',
        'STRIPE_JS_CREATING_SESSION',
        'STRIPE_JS_SESSION_FAILED',
        'STRIPE_JS_SESSION_INVALID',
        'STRIPE_JS_PROCESSING',
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
