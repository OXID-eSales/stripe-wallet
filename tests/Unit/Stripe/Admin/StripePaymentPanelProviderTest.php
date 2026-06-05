<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\Payments\Stripe\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Stripe\Admin\AdminAmountValidator;
use OxidEsales\Payments\Stripe\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Stripe\Admin\AmountValidationResult;
use OxidEsales\Payments\Stripe\Admin\StripePanelOrderLoader;
use OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder;
use OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sprint I — Stripe panel provider contract tests.
 *
 * The provider is the thin shim that injects Stripe's existing view-data +
 * action services into payment-base's shared admin tab. Tests here
 * validate the Sprint-I contract (supports / build / handleAction); the
 * underlying view-data + dispatcher behaviours are covered by their own
 * existing test suites and reused unchanged.
 */
final class StripePaymentPanelProviderTest extends TestCase
{
    public function testProviderNameIsStripe(): void
    {
        $provider = $this->provider();

        self::assertSame('stripe', $provider->getProviderName());
    }

    public function testSupportsOrderPaidWithStripe(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supports(
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        ));
    }

    public function testSupportsByContractProviderEvenWhenPaymentTypeUnknown(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supports(
            $this->context(paymentType: '', provider: 'stripe'),
        ));
    }

    public function testDoesNotSupportNonStripeOrder(): void
    {
        $provider = $this->provider();

        self::assertFalse($provider->supports(
            $this->context(paymentType: 'oe_payments_paypal', provider: 'paypal'),
        ));
        self::assertFalse($provider->supports(
            $this->context(paymentType: 'oxidinvoice', provider: null),
        ));
    }

    public function testBuildReturnsRenderableWithStripePanelPathAndViewData(): void
    {
        $provider = $this->provider(
            withViewData: [
                'contractId' => 'c_1',
                'transactionId' => 'pi_3TOypMRKy8lrhVfC0oGQGojh',
                'capturedAmount' => '100.00',
                'refundedAmount' => '0.00',
                'currency' => 'EUR',
            ],
        );

        $renderable = $provider->build($this->context(
            paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            provider: 'stripe',
        ));

        self::assertSame('stripe', $renderable->providerKey);
        self::assertSame('@oe_payments_stripe_wallet/admin/panel/stripe_panel.html.twig', $renderable->templatePath);
        self::assertSame('c_1', $renderable->viewData['contractId']);
        self::assertSame('pi_3TOypMRKy8lrhVfC0oGQGojh', $renderable->viewData['transactionId']);
        self::assertSame('EUR', $renderable->viewData['currency']);
    }

    public function testHandleRefundActionDispatchesThroughStripeDispatcher(): void
    {
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('refund')
            ->with(
                self::isInstanceOf(Order::class),
                12.5,
                'requested_by_customer',
                self::callback(static fn(array $extras): bool => array_key_exists('description', $extras)),
            );

        $provider = $this->provider(dispatcher: $dispatcher);

        $provider->handleAction(
            'refund',
            ['refund_amount' => '12.50', 'refund_reason' => 'requested_by_customer'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testHandleActionReturnsSilentlyWhenOrderCannotBeLoaded(): void
    {
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('refund');
        $dispatcher->expects(self::never())->method('capture');
        $dispatcher->expects(self::never())->method('cancel');

        $provider = $this->provider(dispatcher: $dispatcher, noOrder: true);

        $provider->handleAction(
            'refund',
            [],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    // ---------------------------------------------------------------------------
    // Sprint 120 Phase C (STRP-129): capture_reason pre-dispatch gate
    // ---------------------------------------------------------------------------

    public function testInvalidCaptureReasonBlocksDispatchAndStoresFeedback(): void
    {
        // C1 — the critical pair: capture() NEVER called, failures stored.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('capture');

        $failure = $this->captureReasonFailure('<');
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateFieldMap')
            ->with(['captureReason' => '<script>'], 'admin')
            ->willReturn([$failure]);

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())
            ->method('store')
            ->with(self::anything(), 'capture', [$failure]);

        $provider = $this->provider(dispatcher: $dispatcher, validator: $validator, feedback: $feedback);

        $provider->handleAction(
            'capture',
            ['capture_amount' => '10.00', 'capture_reason' => '<script>'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testValidCaptureReasonDispatchesUnchangedAndStoresNothing(): void
    {
        // C2
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('capture')
            ->with(
                self::isInstanceOf(Order::class),
                10.0,
                'Teillieferung #2',
                self::callback(static fn(array $extras): bool => array_key_exists('paymentIntentId', $extras)),
            );

        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateFieldMap')->willReturn([]);

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::never())->method('store');

        $provider = $this->provider(dispatcher: $dispatcher, validator: $validator, feedback: $feedback);

        $provider->handleAction(
            'capture',
            ['capture_amount' => '10.00', 'capture_reason' => 'Teillieferung #2'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testEmptyCaptureReasonSkipsValidationAndDispatchesWithNullReason(): void
    {
        // C3 — reason is optional; full-capture semantics untouched.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('capture')
            ->with(self::isInstanceOf(Order::class), null, null, self::anything());

        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->expects(self::never())->method('validateFieldMap');

        $provider = $this->provider(dispatcher: $dispatcher, validator: $validator);

        $provider->handleAction(
            'capture',
            ['capture_amount' => '', 'capture_reason' => ''],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testRefundAndCancelActionsDoNotInvokeTheReasonValidator(): void
    {
        // C4 — guards the Sprint 121 follow-up boundary.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('refund');
        $dispatcher->expects(self::once())->method('cancel');

        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->expects(self::never())->method('validateFieldMap');

        $provider = $this->provider(dispatcher: $dispatcher, validator: $validator);
        $context = $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe');

        $provider->handleAction('refund', ['refund_reason' => 'duplicate'], $context);
        $provider->handleAction('cancel', ['cancel_reason' => 'duplicate'], $context);
    }

    // ---------------------------------------------------------------------------
    // Sprint 121 Phase C (STRP-129): amount gates + refund_description gate
    // ---------------------------------------------------------------------------

    public function testMalformedCaptureAmountBlocksDispatch(): void
    {
        // C1 — the sprint's raison d'être: '12,30 EUR' used to become null = FULL capture.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('capture');

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())
            ->method('store')
            ->with(self::anything(), 'capture', self::callback(
                static fn(array $failures): bool => count($failures) === 1
                    && $failures[0]->field === 'captureAmount'
                    && $failures[0]->code === AmountValidationResult::CODE_MALFORMED
            ));

        $provider = $this->provider(dispatcher: $dispatcher, feedback: $feedback);

        $provider->handleAction(
            'capture',
            ['capture_amount' => '12,30 EUR', 'capture_reason' => ''],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testCaptureAmountAboveBoundBlocksDispatch(): void
    {
        // C2
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('capture');

        $bounds = $this->createMock(AdminActionBoundsInterface::class);
        $bounds->method('captureBound')->willReturn(100.00);

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())
            ->method('store')
            ->with(self::anything(), 'capture', self::callback(
                static fn(array $failures): bool =>
                    $failures[0]->code === AmountValidationResult::CODE_EXCEEDS_BOUND
            ));

        $provider = $this->provider(dispatcher: $dispatcher, feedback: $feedback, bounds: $bounds);

        $provider->handleAction(
            'capture',
            ['capture_amount' => '100.01'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testValidCommaAmountDispatchesTheParsedFloat(): void
    {
        // C3 — parse once: the validated float travels, not a re-parse of the raw.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('capture')
            ->with(self::isInstanceOf(Order::class), 50.0, null, self::anything());

        $provider = $this->provider(dispatcher: $dispatcher);

        $provider->handleAction(
            'capture',
            ['capture_amount' => '50,00'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testEmptyCaptureAmountStillMeansFullCaptureAndSkipsBoundLookup(): void
    {
        // C4 — absent amount = full capture; no PSP bound call wasted on it.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('capture')
            ->with(self::isInstanceOf(Order::class), null, null, self::anything());

        $bounds = $this->createMock(AdminActionBoundsInterface::class);
        $bounds->expects(self::never())->method('captureBound');

        $provider = $this->provider(dispatcher: $dispatcher, bounds: $bounds);

        $provider->handleAction(
            'capture',
            ['capture_amount' => ''],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testMalformedRefundAmountBlocksDispatch(): void
    {
        // C5a — same footgun killed on the refund path.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('refund');

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())
            ->method('store')
            ->with(self::anything(), 'refund', self::callback(
                static fn(array $failures): bool => $failures[0]->field === 'refundAmount'
                    && $failures[0]->code === AmountValidationResult::CODE_MALFORMED
            ));

        $provider = $this->provider(dispatcher: $dispatcher, feedback: $feedback);

        $provider->handleAction(
            'refund',
            ['refund_amount' => 'abc'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testValidRefundAmountChecksRefundBoundAndDispatches(): void
    {
        // C5b
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('refund')
            ->with(self::isInstanceOf(Order::class), 12.5, 'requested_by_customer', self::anything());

        $bounds = $this->createMock(AdminActionBoundsInterface::class);
        $bounds->expects(self::once())->method('refundBound')->willReturn(20.00);
        $bounds->expects(self::never())->method('captureBound');

        $provider = $this->provider(dispatcher: $dispatcher, bounds: $bounds);

        $provider->handleAction(
            'refund',
            ['refund_amount' => '12,50', 'refund_reason' => 'requested_by_customer'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testAmountAndReasonFailuresAreStoredTogetherInOneCall(): void
    {
        // C6
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('capture');

        $reasonFailure = $this->captureReasonFailure('<');
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateFieldMap')->willReturn([$reasonFailure]);

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())
            ->method('store')
            ->with(self::anything(), 'capture', self::callback(
                static function (array $failures): bool {
                    $fields = array_map(static fn($f) => $f->field, $failures);
                    return count($failures) === 2
                        && in_array('captureReason', $fields, true)
                        && in_array('captureAmount', $fields, true);
                }
            ));

        $provider = $this->provider(dispatcher: $dispatcher, validator: $validator, feedback: $feedback);

        $provider->handleAction(
            'capture',
            ['capture_amount' => 'abc', 'capture_reason' => '<bad>'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testInvalidRefundDescriptionBlocksDispatch(): void
    {
        // C7 — refund_description is POST-reachable free text into Stripe metadata.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('refund');

        $failure = new FieldValidationFailure(
            field: 'refundDescription',
            addressKind: 'admin',
            code: FieldValidationResult::CODE_BLOCKED_CHARACTER,
            offendingChar: '<',
            oxidColumn: null,
        );
        $validator = $this->createMock(UserDataValidatorInterface::class);
        $validator->method('validateFieldMap')
            ->with(['refundDescription' => '<img src=x>'], 'admin')
            ->willReturn([$failure]);

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())
            ->method('store')
            ->with(self::anything(), 'refund', [$failure]);

        $provider = $this->provider(dispatcher: $dispatcher, validator: $validator, feedback: $feedback);

        $provider->handleAction(
            'refund',
            ['refund_description' => '<img src=x>'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    public function testBoundResolutionFailureFailsClosed(): void
    {
        // C8 — PSP unavailable: reject the action, never fail-open to full capture.
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('capture');

        $bounds = $this->createMock(AdminActionBoundsInterface::class);
        $bounds->method('captureBound')->willThrowException(new \RuntimeException('PI fetch failed'));

        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())
            ->method('store')
            ->with(self::anything(), 'capture', self::callback(
                static fn(array $failures): bool =>
                    $failures[0]->code === AmountValidationResult::CODE_BOUND_UNAVAILABLE
            ));

        $provider = $this->provider(dispatcher: $dispatcher, feedback: $feedback, bounds: $bounds);

        $provider->handleAction(
            'capture',
            ['capture_amount' => '50.00'],
            $this->context(paymentType: StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, provider: 'stripe'),
        );
    }

    private function provider(
        array $withViewData = [],
        ?AdminActionDispatcherInterface $dispatcher = null,
        ?UserDataValidatorInterface $validator = null,
        ?AdminValidationFeedbackInterface $feedback = null,
        ?AdminActionBoundsInterface $bounds = null,
        bool $noOrder = false,
    ): StripePaymentPanelProvider {
        if ($bounds === null) {
            // Generous defaults so amount-agnostic tests pass the bound check.
            $bounds = $this->createMock(AdminActionBoundsInterface::class);
            $bounds->method('captureBound')->willReturn(999999.0);
            $bounds->method('refundBound')->willReturn(999999.0);
        }

        return new StripePaymentPanelProvider(
            actionDispatcher: $dispatcher ?? $this->createMock(AdminActionDispatcherInterface::class),
            viewDataBuilder: $this->viewDataBuilderStub($withViewData),
            orderLoader: $this->orderLoaderStub($noOrder ? null : $this->orderStub()),
            userDataValidator: $validator ?? $this->createMock(UserDataValidatorInterface::class),
            validationFeedback: $feedback ?? $this->createMock(AdminValidationFeedbackInterface::class),
            amountValidator: new AdminAmountValidator(),
            actionBounds: $bounds,
        );
    }

    private function captureReasonFailure(string $char): FieldValidationFailure
    {
        return new FieldValidationFailure(
            field: 'captureReason',
            addressKind: 'admin',
            code: FieldValidationResult::CODE_BLOCKED_CHARACTER,
            offendingChar: $char,
            oxidColumn: null,
        );
    }

    private function viewDataBuilderStub(array $data): StripePanelViewDataBuilder
    {
        return new class ($data) extends StripePanelViewDataBuilder {
            /** @phpstan-ignore-next-line constructor.dependency */
            public function __construct(private readonly array $stubData)
            {
            }
            public function build(Order $order): array
            {
                return $this->stubData;
            }
        };
    }

    private function orderLoaderStub(?Order $order): StripePanelOrderLoader
    {
        return new class ($order) extends StripePanelOrderLoader {
            public function __construct(private readonly ?Order $stubOrder)
            {
            }
            public function loadById(string $orderId): ?Order
            {
                return $this->stubOrder;
            }
        };
    }

    private function context(string $paymentType, ?string $provider): PaymentPanelContext
    {
        $contract = null;
        if ($provider !== null) {
            $contract = $this->createMock(PaymentContractInterface::class);
            $contract->method('getProvider')->willReturn($provider);
        }

        return new PaymentPanelContext(
            orderId: 'order_1',
            paymentType: $paymentType,
            contract: $contract,
        );
    }

    private function orderStub(): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        return $order;
    }

}
