<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Stripe\Admin\AmountValidationResult;
use OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder;
use OxidEsales\Payments\Stripe\Admin\StripeTransactionHistoryBuilder;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Service\AllowedSymbolsDescriber;
use OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface;
use OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\OrderContractResolver;
use OxidEsales\Payments\Stripe\Service\StripeOrderApiService;
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
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-120')]
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
    // Sprint 127 (STRP-15123): capturableRaw is single-sourced to amountCapturable
    // ---------------------------------------------------------------------------
    /**
     * The builder must pass getCaptureableRaw() through unchanged to capturableRaw.
     * No transformation, no re-derivation — one source drives prefill, max, and label.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testCapturableRawInViewDataIsPassedThroughFromProvider(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->method('consume')->willReturn([]);

        $provider = $this->createMock(OrderRefundViewDataProvider::class);
        $provider->method('getCaptureableRaw')->willReturn(40.0);
        $provider->method('getCaptureableAmount')->willReturn('40.00');

        $viewData = $this->builderWithProvider($feedback, $provider)->build($this->orderStub());

        $this->assertSame(40.0, $viewData['capturableRaw']);
        $this->assertSame('40.00', $viewData['capturableAmount']);
    }

    /**
     * End-to-end SSOT: a PI with amountCapturable=4000 (40.00 EUR) produces
     * capturableRaw=40.0 in assembled view-data. Verified through the full
     * provider → builder chain using the fetchExpandedPaymentIntent seam.
     *
     * This is the single-source regression lock: any accidental re-derivation
     * from ->amount (the old bug) would return 100.0 instead of 40.0.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testCapturableRawInViewDataEqualsPiAmountCapturable(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->method('consume')->willReturn([]);

        $pi = new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'requires_capture',
            amount: 10000,
            currency: 'eur',
            created: 0,
            latestChargeId: null,
            charge: null,
            amountCapturable: 4000,
        );

        $provider = $this->realProviderWithPi($pi);
        $viewData  = $this->builderWithProvider($feedback, $provider)->build($this->orderStub());

        $this->assertSame(40.0, $viewData['capturableRaw']);
    }

    // ---------------------------------------------------------------------------
    // Sprint 127 Issue 1 (STRP-15123): remainingRefundableRaw prefill regression lock
    //
    // H3 confirms the builder's unit chain correctly computes remainingRefundableRaw
    // for a partial refund fixture. This is GREEN today — the unit chain (provider →
    // resolver) already works for fresh reads. The bug is upstream (cache not busted
    // after action), not in the compute chain itself.
    // ---------------------------------------------------------------------------
    /**
     * SSOT regression lock: a charge with amountCaptured=10000, amountRefunded=3000
     * (full capture, one 30 EUR refund) must produce remainingRefundableRaw=70.0
     * in the assembled view-data.
     *
     * Prevents any regression where the prefill incorrectly re-reads the pre-refund
     * captured amount (100.0) instead of the post-refund residual (70.0).
     * This test is GREEN today — it locks correct behaviour at the builder level.
     *
     * Cross-source note: Order::getStripeRefundedAmount() reads via a separate
     * fetchStripeCharge() seam in the Order model, not via OrderRefundViewDataProvider.
     * Both ultimately delegate to StripeChargeAmountResolver with the same charge data.
     * No SSOT violation requiring a refactor — the two reads are on separate code paths
     * that converge at the resolver.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testRemainingRefundableRawInViewDataReflectsPartialRefund(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->method('consume')->willReturn([]);

        $charge = new StripeChargeDto(
            id: 'ch_test',
            amount: 10000,
            amountCaptured: 10000,
            amountRefunded: 3000,
            currency: 'eur',
            captured: true,
            created: 0,
        );
        $pi = new StripePaymentIntentDto(
            id: 'pi_test',
            status: 'succeeded',
            amount: 10000,
            currency: 'eur',
            created: 0,
            latestChargeId: 'ch_test',
            charge: $charge,
            amountCapturable: 0,
        );

        $provider = $this->realProviderWithPiAndRealResolver($pi);
        $viewData  = $this->builderWithProvider($feedback, $provider)->build($this->orderStub());

        // remainingRefundableRaw is used as the amount field prefill (max).
        // With 30 EUR already refunded from 100 EUR, only 70 EUR remain.
        $this->assertSame(70.0, $viewData['remainingRefundableRaw']);
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

    /**
     * Builder variant that accepts an explicit provider (for SSOT tests).
     */
    private function builderWithProvider(
        AdminValidationFeedbackInterface $feedback,
        OrderRefundViewDataProvider $provider,
    ): StripePanelViewDataBuilder {
        $translator = $this->translatorStub();

        return new StripePanelViewDataBuilder(
            viewDataProvider: $provider,
            contractResolver: $this->createMock(OrderContractResolver::class),
            moduleConfig: $this->createMock(ModuleConfigurationServiceInterface::class),
            validationFeedback: $feedback,
            messageFormatter: $this->realFormatter($translator),
            translator: $translator,
        );
    }

    /**
     * Creates a real OrderRefundViewDataProvider whose fetchExpandedPaymentIntent()
     * returns the given PI, bypassing StripeOrderApiService (which is final).
     * Uses a mock ChargeAmountResolverInterface — suitable for passthrough tests.
     */
    private function realProviderWithPi(?StripePaymentIntentDto $pi): OrderRefundViewDataProvider
    {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->createMock(StripeAdapterInterface::class));
        $apiService = new StripeOrderApiService($adapterFactory);
        $resolver   = $this->createMock(ChargeAmountResolverInterface::class);

        return new class ($apiService, $resolver, $pi) extends OrderRefundViewDataProvider {
            public function __construct(
                StripeOrderApiService $apiService,
                ChargeAmountResolverInterface $chargeAmountResolver,
                private readonly ?StripePaymentIntentDto $stubPi,
            ) {
                parent::__construct($apiService, $chargeAmountResolver, new StripeTransactionHistoryBuilder());
            }

            protected function fetchExpandedPaymentIntent(Order $order): ?StripePaymentIntentDto
            {
                return $this->stubPi;
            }
        };
    }

    /**
     * Like realProviderWithPi() but wires the real StripeChargeAmountResolver
     * so amount computations (remainingRefundableRaw etc.) are exercised end-to-end.
     * Required for H3 regression lock where the fixture charge numbers must drive
     * the computed result.
     */
    private function realProviderWithPiAndRealResolver(?StripePaymentIntentDto $pi): OrderRefundViewDataProvider
    {
        $adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $adapterFactory->method('getStripeAdapter')->willReturn($this->createMock(StripeAdapterInterface::class));
        $apiService = new StripeOrderApiService($adapterFactory);
        $resolver   = new StripeChargeAmountResolver();

        return new class ($apiService, $resolver, $pi) extends OrderRefundViewDataProvider {
            public function __construct(
                StripeOrderApiService $apiService,
                ChargeAmountResolverInterface $chargeAmountResolver,
                private readonly ?StripePaymentIntentDto $stubPi,
            ) {
                parent::__construct($apiService, $chargeAmountResolver, new StripeTransactionHistoryBuilder());
            }

            protected function fetchExpandedPaymentIntent(Order $order): ?StripePaymentIntentDto
            {
                return $this->stubPi;
            }
        };
    }
}
