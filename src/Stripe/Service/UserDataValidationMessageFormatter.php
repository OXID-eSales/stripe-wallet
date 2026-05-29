<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Stripe\Module;

/**
 * Builds the user-facing validation message:
 *
 *   "The {field label} field is not valid, please check the syntax.
 *    Allowed symbols are: {allowed symbols}."
 *
 * The field label is translated via STRIPE_VALIDATION_LABEL_{FIELD}; the
 * allowed-symbol list is rendered by {@see AllowedSymbolsDescriber}. The
 * violation code and offending character are intentionally not surfaced —
 * the message tells the user what IS allowed, per STRP-129.
 */
class UserDataValidationMessageFormatter implements MessageFormatterInterface
{
    private const TEMPLATE_KEY = 'STRIPE_VALIDATION_FIELD_INVALID';
    private const LABEL_KEY_PREFIX = 'STRIPE_VALIDATION_LABEL_';

    public function __construct(
        private readonly LanguageTranslatorInterface $translator,
        private readonly AllowedSymbolsDescriber $allowedSymbolsDescriber,
    ) {
    }

    public function getPluginModuleId(): string
    {
        return Module::MODULE_ID;
    }

    public function format(string $field, string $code, ?string $offendingChar): string
    {
        $template = $this->translator->translateString(self::TEMPLATE_KEY);
        $label = $this->resolveLabel($field);
        $allowed = $this->allowedSymbolsDescriber->describe($field);

        return sprintf($template, $label, $allowed);
    }

    private function resolveLabel(string $field): string
    {
        $key = self::LABEL_KEY_PREFIX . strtoupper($field);
        $translation = $this->translator->translateString($key);

        if ($translation === $key) {
            return $field;
        }

        return $translation;
    }
}
