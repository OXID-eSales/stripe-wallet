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
 * STRP-178: an ampersand in the address line ("Lohmann & Rauscher,
 * 1 Wellington Court, …") refused the checkout with disallowed_character.
 * Company names are legitimate address-line content and the company field
 * has always allowed '&' through the identical downstream sinks, so street
 * and additionalInfo now allow it too. Masking was rejected: rewriting a
 * shipping address before it reaches the carrier corrupts the delivery data.
 *
 * Exercises the REAL Stripe validation-rules.php through a REAL payment-base
 * ValidationBase + FilesystemValidationRuleLoader — only the path resolver is
 * stubbed (no DB, no OXID boot), same rig as {@see ExtendedLatinAddressFieldsTest}.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\PaymentBase\Validation\ValidationBase::class)]
#[\PHPUnit\Framework\Attributes\Group('user-data-validation')]
final class AmpersandInAddressFieldsTest extends TestCase
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
    public static function ampersandAcceptedProvider(): array
    {
        return [
            // the reported address, field by field as OXID splits it
            'street with company name'    => ['street', 'Lohmann & Rauscher, 1 Wellington Court'],
            'street ampersand only'       => ['street', '5th & Main'],
            'additionalInfo company name' => ['additionalInfo', 'c/o Lohmann & Rauscher'],
            // regression — company allowed '&' before STRP-178 and must keep it
            'company ampersand'           => ['company', 'Lohmann & Rauscher GmbH'],
        ];
    }

    #[DataProvider('ampersandAcceptedProvider')]
    public function testAmpersandAcceptedInAddressLineFields(string $field, string $value): void
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
    public static function ampersandStillRejectedProvider(): array
    {
        return [
            // name and city fields keep '&' on their block lists — a person or
            // a city is never named with an ampersand; the widening is scoped
            // to the two address-line fields only.
            'firstName ampersand' => ['firstName', 'Tom & Jerry', FieldValidationResult::CODE_BLOCKED_CHARACTER],
            'lastName ampersand'  => ['lastName', 'Smith & Co', FieldValidationResult::CODE_BLOCKED_CHARACTER],
            'city ampersand'      => ['city', 'Burton & Trent', FieldValidationResult::CODE_BLOCKED_CHARACTER],
        ];
    }

    #[DataProvider('ampersandStillRejectedProvider')]
    public function testAmpersandStillRejectedOutsideAddressLines(string $field, string $value, string $expectedCode): void
    {
        $result = $this->sut->validateField($field, $value);

        $this->assertFalse($result->valid, sprintf('Expected "%s" to be rejected for field "%s"', $value, $field));
        $this->assertSame($expectedCode, $result->code);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function blockListUnchangedProvider(): array
    {
        return [
            // allowing '&' must not loosen anything else on street/additionalInfo
            'street angle bracket'        => ['street', 'Main<script>', FieldValidationResult::CODE_BLOCKED_CHARACTER],
            'street at sign'              => ['street', 'Main@Street', FieldValidationResult::CODE_BLOCKED_CHARACTER],
            'street percent'              => ['street', 'Main%20Street', FieldValidationResult::CODE_BLOCKED_CHARACTER],
            'street euro disallowed'      => ['street', 'Main€', FieldValidationResult::CODE_DISALLOWED_CHARACTER],
            'additionalInfo angle bracket' => ['additionalInfo', '<b>c/o</b>', FieldValidationResult::CODE_BLOCKED_CHARACTER],
        ];
    }

    #[DataProvider('blockListUnchangedProvider')]
    public function testBlockListsUnchanged(string $field, string $value, string $expectedCode): void
    {
        $result = $this->sut->validateField($field, $value);

        $this->assertFalse($result->valid, sprintf('Expected "%s" to be rejected for field "%s"', $value, $field));
        $this->assertSame($expectedCode, $result->code);
    }
}
