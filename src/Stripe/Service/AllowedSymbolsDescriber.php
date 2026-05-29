<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Renders the human-readable "allowed symbols" string for a validation field.
 *
 * Reads the per-field `allow` token strings (same grammar as the payment-base
 * ValidationBase rule loader) and turns them into a user-facing description:
 * named character classes become translated words (letters / digits / spaces)
 * and literal tokens are listed verbatim.
 *
 *   firstName: "UNICODE_LETTERS SPACES ' - ."  ->  "letters, spaces, ' - ."
 *   phone:     "NUMBERS SPACES + - ( )"        ->  "digits, spaces, + - ( )"
 *
 * STRP-129.
 */
class AllowedSymbolsDescriber
{
    /** Class token -> translation key for its human-readable word. */
    private const CLASS_WORD_KEYS = [
        'UNICODE_LETTERS' => 'STRIPE_VALIDATION_CLASS_LETTERS',
        'LETTERS'         => 'STRIPE_VALIDATION_CLASS_LETTERS',
        'NUMBERS'         => 'STRIPE_VALIDATION_CLASS_DIGITS',
        'SPACES'          => 'STRIPE_VALIDATION_CLASS_SPACES',
    ];

    /**
     * @param array<string, string> $fieldAllowMap field name => space-separated allow-token string
     */
    public function __construct(
        private readonly LanguageTranslatorInterface $translator,
        private readonly array $fieldAllowMap,
    ) {
    }

    public function describe(string $field): string
    {
        $allow = $this->fieldAllowMap[$field] ?? null;
        if ($allow === null || $allow === '') {
            return '';
        }

        $words = [];
        $literals = [];
        foreach (explode(' ', $allow) as $token) {
            if ($token === '') {
                continue;
            }
            $this->classifyToken($token, $words, $literals);
        }

        return $this->joinParts(array_values(array_unique($words)), $literals);
    }

    /**
     * @param list<string> $words
     * @param list<string> $literals
     */
    private function classifyToken(string $token, array &$words, array &$literals): void
    {
        if (isset(self::CLASS_WORD_KEYS[$token])) {
            $words[] = $this->translator->translateString(self::CLASS_WORD_KEYS[$token]);
            return;
        }

        $literals[] = $token;
    }

    /**
     * @param list<string> $words
     * @param list<string> $literals
     */
    private function joinParts(array $words, array $literals): string
    {
        $parts = $words;
        if ($literals !== []) {
            $parts[] = implode(' ', $literals);
        }

        return implode(', ', $parts);
    }
}
