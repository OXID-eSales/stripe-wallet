<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\Payments\Stripe\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Stripe\Admin\AmountValidationResult;
use OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Service\AllowedSymbolsDescriber;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\OrderContractResolver;
use OxidEsales\Payments\Stripe\Service\UserDataValidationMessageFormatter;
use OxidEsales\Payments\Stripe\Service\ValidationRulesProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 120 Phase D (STRP-129): the view-data builder consumes the
 * session-backed validation feedback and projects translated messages into
 * viewData['validationErrors'] for the panel alert block.
 *
 * The formatter is REAL (UserDataValidationMessageFormatter over a stubbed
 * translator and the real AllowedSymbolsDescriber/rules file) so the test
 * pins the actual message shape the admin sees.
 *
 * @covers \OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder
 * @group sprint-120
 */
final class StripePanelViewDataBuilderTest extends TestCase
{
    public function testProjectsConsumedFailuresAsTranslatedMessages(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())
            ->method('consume')
            ->willReturn([
                [
                    'field'  => 'captureReason',
                    'code'   => FieldValidationResult::CODE_BLOCKED_CHARACTER,
                    'char'   => '<',
                    'action' => 'capture',
                ],
            ]);

        $viewData = $this->builder($feedback)->build($this->orderStub());

        $this->assertSame(
            ["The capture reason field is not valid. Allowed symbols are: letters, digits, spaces, ' - . , / # ( ) :"],
            $viewData['validationErrors']
        );
    }

    public function testNoFeedbackYieldsEmptyValidationErrors(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->method('consume')->willReturn([]);

        $viewData = $this->builder($feedback)->build($this->orderStub());

        $this->assertSame([], $viewData['validationErrors']);
    }

    public function testConsumeIsCalledExactlyOncePerBuild(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())->method('consume')->willReturn([]);

        $this->builder($feedback)->build($this->orderStub());
    }

    // ---------------------------------------------------------------------------
    // Sprint 121 Phase D (STRP-129): per-code routing for amount failures
    // ---------------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('amountCodes')]
    public function testAmountFailureCodesRouteToPerCodeMessages(string $code, string $expected): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->method('consume')->willReturn([
            ['field' => 'captureAmount', 'code' => $code, 'char' => null, 'action' => 'capture'],
        ]);

        $viewData = $this->builder($feedback)->build($this->orderStub());

        $this->assertSame([$expected], $viewData['validationErrors']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function amountCodes(): iterable
    {
        yield 'malformed'         => [AmountValidationResult::CODE_MALFORMED, 'The amount is not a valid number. Use a format like 12.50.'];
        yield 'not positive'      => [AmountValidationResult::CODE_NOT_POSITIVE, 'The amount must be greater than zero.'];
        yield 'precision'         => [AmountValidationResult::CODE_PRECISION, 'The amount has too many decimal places for this currency.'];
        yield 'exceeds bound'     => [AmountValidationResult::CODE_EXCEEDS_BOUND, 'The amount exceeds the maximum available for this action.'];
        yield 'bound unavailable' => [AmountValidationResult::CODE_BOUND_UNAVAILABLE, 'The available amount could not be verified with Stripe. Please try again.'];
    }

    public function testMixedAmountAndCharFailuresKeepStorageOrder(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->method('consume')->willReturn([
            [
                'field'  => 'captureReason',
                'code'   => FieldValidationResult::CODE_BLOCKED_CHARACTER,
                'char'   => '<',
                'action' => 'capture',
            ],
            [
                'field'  => 'captureAmount',
                'code'   => AmountValidationResult::CODE_MALFORMED,
                'char'   => null,
                'action' => 'capture',
            ],
        ]);

        $viewData = $this->builder($feedback)->build($this->orderStub());

        $this->assertCount(2, $viewData['validationErrors']);
        $this->assertStringContainsString('capture reason', $viewData['validationErrors'][0]);
        $this->assertStringContainsString('not a valid number', $viewData['validationErrors'][1]);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function builder(AdminValidationFeedbackInterface $feedback): StripePanelViewDataBuilder
    {
        $translator = $this->translatorStub();

        return new StripePanelViewDataBuilder(
            viewDataProvider: $this->createMock(OrderRefundViewDataProvider::class),
            contractResolver: $this->createMock(OrderContractResolver::class),
            moduleConfig: $this->createMock(ModuleConfigurationServiceInterface::class),
            validationFeedback: $feedback,
            messageFormatter: $this->realFormatter($translator),
            translator: $translator,
        );
    }

    /**
     * Stubbed en strings mirroring the admin lang files.
     */
    private function translatorStub(): LanguageTranslatorInterface
    {
        $translator = $this->createMock(LanguageTranslatorInterface::class);
        $translator->method('translateString')->willReturnMap([
            ['STRIPE_VALIDATION_FIELD_INVALID', 'The %1$s field is not valid. Allowed symbols are: %2$s'],
            ['STRIPE_VALIDATION_LABEL_CAPTUREREASON', 'capture reason'],
            ['STRIPE_VALIDATION_CLASS_LETTERS', 'letters'],
            ['STRIPE_VALIDATION_CLASS_DIGITS', 'digits'],
            ['STRIPE_VALIDATION_CLASS_SPACES', 'spaces'],
            ['STRIPE_VALIDATION_AMOUNT_MALFORMED', 'The amount is not a valid number. Use a format like 12.50.'],
            ['STRIPE_VALIDATION_AMOUNT_NOT_POSITIVE', 'The amount must be greater than zero.'],
            ['STRIPE_VALIDATION_AMOUNT_PRECISION', 'The amount has too many decimal places for this currency.'],
            ['STRIPE_VALIDATION_AMOUNT_EXCEEDS_BOUND', 'The amount exceeds the maximum available for this action.'],
            ['STRIPE_VALIDATION_AMOUNT_BOUND_UNAVAILABLE', 'The available amount could not be verified with Stripe. Please try again.'],
        ]);

        return $translator;
    }

    /**
     * Real formatter + real describer over the real rules file; only the
     * translator is stubbed.
     */
    private function realFormatter(LanguageTranslatorInterface $translator): UserDataValidationMessageFormatter
    {
        return new UserDataValidationMessageFormatter(
            $translator,
            (new ValidationRulesProvider())->createDescriber($translator),
        );
    }

    private function orderStub(): Order
    {
        return $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }
}
