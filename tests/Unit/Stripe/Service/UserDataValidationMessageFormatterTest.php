<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Stripe\Module;
use OxidEsales\Payments\Stripe\Service\AllowedSymbolsDescriber;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Stripe\Service\UserDataValidationMessageFormatter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * STRP-129: the validation message must name the field and list the ALLOWED
 * symbols (not the offending character).
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\UserDataValidationMessageFormatter::class)]
#[\PHPUnit\Framework\Attributes\Group('user-data-validation')]
final class UserDataValidationMessageFormatterTest extends TestCase
{
    private LanguageTranslatorInterface&MockObject $translator;
    private UserDataValidationMessageFormatter $sut;

    protected function setUp(): void
    {
        $this->translator = $this->createMock(LanguageTranslatorInterface::class);
        $this->translator->method('translateString')->willReturnMap([
            ['STRIPE_VALIDATION_FIELD_INVALID',
                'The %1$s field is not valid, please check the syntax. Allowed symbols are: %2$s'],
            ['STRIPE_VALIDATION_LABEL_FIRSTNAME', 'first name'],
            ['STRIPE_VALIDATION_LABEL_STREET', 'street'],
            ['STRIPE_VALIDATION_CLASS_LETTERS', 'letters'],
            ['STRIPE_VALIDATION_CLASS_DIGITS', 'digits'],
            ['STRIPE_VALIDATION_CLASS_SPACES', 'spaces'],
        ]);

        $describer = new AllowedSymbolsDescriber($this->translator, [
            'firstName' => "UNICODE_LETTERS SPACES ' - .",
            'street'    => "LETTERS NUMBERS SPACES ' - . , /",
        ]);

        $this->sut = new UserDataValidationMessageFormatter($this->translator, $describer);
    }

    public function testImplementsMessageFormatterInterface(): void
    {
        $this->assertInstanceOf(MessageFormatterInterface::class, $this->sut);
    }

    public function testKnowsItsPluginId(): void
    {
        $this->assertSame(Module::MODULE_ID, $this->sut->getPluginModuleId());
    }

    public function testMessageNamesFieldAndListsAllowedSymbols(): void
    {
        $result = $this->sut->format('firstName', 'blocked_character', ':');

        $this->assertSame(
            "The first name field is not valid, please check the syntax. Allowed symbols are: letters, spaces, ' - .",
            $result,
        );
    }

    public function testMessageIsTheSameRegardlessOfViolationCode(): void
    {
        // The user-facing message lists allowed symbols; the specific code
        // (blocked/disallowed/control) does not change the wording.
        $blocked = $this->sut->format('street', 'blocked_character', ':');
        $control = $this->sut->format('street', 'control_character', "\t");

        $this->assertSame($blocked, $control);
        $this->assertStringContainsString('Allowed symbols are:', $blocked);
    }

    public function testDoesNotLeakRawTranslationKeys(): void
    {
        $result = $this->sut->format('street', 'disallowed_character', '<');

        $this->assertStringNotContainsString('STRIPE_VALIDATION_', $result);
    }
}
