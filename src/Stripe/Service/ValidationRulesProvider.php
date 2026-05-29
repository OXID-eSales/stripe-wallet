<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

/**
 * Loads the Stripe plugin's validation-rules.php and exposes the per-field
 * `allow` token strings. This is the same file the payment-base
 * FilesystemValidationRuleLoader consumes for validation; here it is read for
 * DISPLAY purposes (the allowed-symbols message), where the raw allow string
 * — not the parsed RuleSet — is what {@see AllowedSymbolsDescriber} needs.
 *
 * Also acts as the DI factory for AllowedSymbolsDescriber so the describer can
 * keep its unit-testable `(translator, array $map)` constructor.
 *
 * STRP-129.
 */
class ValidationRulesProvider
{
    private const RULES_FILE = __DIR__ . '/../../Resources/validation-rules.php';

    /**
     * @return array<string, string> field name => space-separated allow-token string
     */
    public function getFieldAllowMap(): array
    {
        /** @var array{fields?: array<array{field: string, rules: array{allow?: string}}>} $data */
        $data = require self::RULES_FILE;

        $map = [];
        foreach ($data['fields'] ?? [] as $entry) {
            $map[$entry['field']] = (string) ($entry['rules']['allow'] ?? '');
        }

        return $map;
    }

    public function createDescriber(LanguageTranslatorInterface $translator): AllowedSymbolsDescriber
    {
        return new AllowedSymbolsDescriber($translator, $this->getFieldAllowMap());
    }
}
