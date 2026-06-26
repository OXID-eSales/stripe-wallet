<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\PaymentBase\Validation\FilesystemValidationRuleLoader;
use OxidEsales\PaymentBase\Validation\PluginPathResolverInterface;
use OxidEsales\PaymentBase\Validation\ValidationBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 124 (STRP-129): behavioural proof that the six address fields accept
 * extended-Latin letters (German umlauts, Polish letters, …) after the
 * LETTERS -> UNICODE_LETTERS widening.
 *
 * This exercises the REAL Stripe validation-rules.php through a REAL
 * payment-base ValidationBase + FilesystemValidationRuleLoader — only the
 * path resolver is stubbed (no DB, no OXID boot). It is the end-to-end check
 * that the original checkout symptom is gone, complementing the allow-string
 * assertions in {@see ValidationRulesProviderTest}.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\PaymentBase\Validation\ValidationBase::class)]
#[\PHPUnit\Framework\Attributes\Group('user-data-validation')]
final class ExtendedLatinAddressFieldsTest extends TestCase
{
    private ValidationBase $sut;

    protected function setUp(): void
    {
        $moduleRoot = dirname(__DIR__, 4);

        $resolver = new class ($moduleRoot) implements PluginPathResolverInterface {
            public function __construct(private readonly string $moduleRoot)
            {
            }

            public function resolvePath(string $pluginModuleId): string
            {
                return $this->moduleRoot;
            }
        };

        $this->sut = new ValidationBase(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            new FilesystemValidationRuleLoader($resolver),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validExtendedLatinProvider(): array
    {
        return [
            'street german umlaut + eszett' => ['street', 'Müllerstraße'],
            'street polish letters'         => ['street', 'ul. Łąkowa'],
            'company french accents'        => ['company', 'Café Müller GmbH'],
            'company ligature'              => ['company', 'Œuvre Frères'],
            'additionalInfo umlaut'         => ['additionalInfo', 'c/o Jürgen Groß'],
            // regressions — ASCII / digits still accepted
            'houseNumber ascii'             => ['houseNumber', '12a'],
            'postalCode digits'             => ['postalCode', '1010'],
            'postalCode uk alpha'           => ['postalCode', 'EC1A 1BB'],
            'vatId ascii'                   => ['vatId', 'DE123456789'],
        ];
    }

    #[DataProvider('validExtendedLatinProvider')]
    public function testAddressFieldAcceptsExtendedLatin(string $field, string $value): void
    {
        $result = $this->sut->validateField($field, $value);

        $this->assertTrue(
            $result->valid,
            sprintf('Expected "%s" to be valid for field "%s" but got code "%s"', $value, $field, (string) $result->code)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function stillRejectedProvider(): array
    {
        return [
            // widening the LETTER class must NOT loosen the block list / blocklist.
            // '<' and '|' are in the per-field block lists -> blocked_character;
            // tab is caught by the universal blocklist -> control_character;
            // '€' is neither allowed nor blocked -> allow-list miss -> disallowed_character.
            'street angle bracket'  => ['street', 'Main<script>', FieldValidationResult::CODE_BLOCKED_CHARACTER],
            'street tab control'    => ['street', "Main\tStreet", FieldValidationResult::CODE_CONTROL_CHARACTER],
            'street euro disallowed' => ['street', 'Main€', FieldValidationResult::CODE_DISALLOWED_CHARACTER],
            'company pipe blocked'  => ['company', 'Acme | Co', FieldValidationResult::CODE_BLOCKED_CHARACTER],
        ];
    }

    #[DataProvider('stillRejectedProvider')]
    public function testWideningDoesNotLoosenBlockRules(string $field, string $value, string $expectedCode): void
    {
        $result = $this->sut->validateField($field, $value);

        $this->assertFalse($result->valid, sprintf('Expected "%s" to be rejected for field "%s"', $value, $field));
        $this->assertSame($expectedCode, $result->code);
    }

    public function testStreetAcceptsPrecomposedUmlautNfc(): void
    {
        // \p{L} matches precomposed (NFC) letters — U+00F6 is a single code point.
        // A decomposed "o" + U+0308 combining diaeresis would classify the mark as
        // \p{M}, not \p{L}, and is intentionally OUT OF SCOPE for this sprint
        // (browser form input is NFC in practice). See the sprint report §7.
        $result = $this->sut->validateField('street', "Stra\u{00F6}e");

        $this->assertTrue($result->valid);
    }
}
