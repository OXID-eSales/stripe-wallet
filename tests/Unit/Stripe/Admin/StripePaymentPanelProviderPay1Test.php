<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Admin\AdminActionBoundsInterface;
use OxidEsales\Payments\Stripe\Admin\AdminAmountValidator;
use OxidEsales\Payments\Stripe\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Stripe\Admin\StripePanelOrderLoader;
use OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder;
use OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider;
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;
use PHPUnit\Framework\TestCase;

/**
 * PAY-3 S5: the provider API of payment-base's admin payment API (options, bounds, typed refusals) and the guarantee
 * that the reasons and the description of the admin API reach the dispatcher like the classic form's do.
 */
final class StripePaymentPanelProviderPay1Test extends TestCase
{
    public function testItOffersTheOptionalProviderInterfaces(): void
    {
        $provider = $this->provider();

        self::assertInstanceOf(AdminActionOptionsProviderInterface::class, $provider);
        self::assertInstanceOf(AdminActionBoundsProviderInterface::class, $provider);
    }

    public function testActionOptions_AreTheClassicPanelsChoices(): void
    {
        $options = $this->provider()->actionOptions($this->contract());

        self::assertSame(['duplicate', 'requested_by_customer', 'fraudulent'], $options->refundReasons);
        self::assertSame(['duplicate', 'requested_by_customer', 'fraudulent', 'abandoned'], $options->voidReasons);
        self::assertTrue($options->refundDescription);
        self::assertSame(140, $options->reasonMaxLength);
    }

    public function testBoundsFor_AreTheLiveBoundsOfTheOrdersIntent(): void
    {
        $bounds = $this->createStub(AdminActionBoundsInterface::class);
        $bounds->method('readBounds')->willReturn(new AdminActionBounds(25.0, 5.0));

        $result = $this->provider(bounds: $bounds)->boundsFor($this->contract());

        self::assertSame(25.0, $result?->capturable);
        self::assertSame(5.0, $result->refundable);
    }

    public function testBoundsFor_AContractWithoutAnOrder_LeavesTheNumbersToPaymentBase(): void
    {
        self::assertNull($this->provider()->boundsFor($this->contract(orderId: null)));
    }

    public function testBoundsFor_AnOrderThatCannotBeLoaded_IsUnavailable(): void
    {
        $result = $this->provider(noOrder: true)->boundsFor($this->contract());

        self::assertFalse($result?->available);
    }

    public function testAnInvalidAmount_IsRefusedWithASentenceAndTheClassicFeedbackIsRecorded(): void
    {
        $feedback = $this->createMock(AdminValidationFeedbackInterface::class);
        $feedback->expects(self::once())->method('store');
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('refund');

        try {
            $this->provider(dispatcher: $dispatcher, feedback: $feedback)->handleAction(
                'refund',
                ['refund_amount' => 'abc'],
                $this->context(),
            );
            self::fail('the refusal must reach the caller');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('amount_malformed', $refusal->refusalCode());
            self::assertSame('The amount is not a valid number.', $refusal->getMessage());
            self::assertTrue($refusal->feedbackRecorded());
        }
    }

    public function testATextTheValidatorBlocks_IsRefusedWithoutQuotingIt(): void
    {
        $validator = $this->createStub(UserDataValidatorInterface::class);
        $validator->method('validateFieldMap')->willReturn([
            new FieldValidationFailure('captureReason', 'admin', 'blockedCharacter', '<', null),
        ]);

        try {
            $this->provider(validator: $validator)->handleAction('capture', ['capture_reason' => '<b>'], $this->context());
            self::fail('the refusal must reach the caller');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('The capture reason contains characters that are not allowed.', $refusal->getMessage());
            self::assertStringNotContainsString('<', $refusal->getMessage());
        }
    }

    public function testTheReasonsAndTheDescriptionOfTheAdminApiReachTheDispatcher(): void
    {
        $dispatcher = $this->createMock(AdminActionDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('capture')->with(self::anything(), null, 'shipped', self::anything());
        $dispatcher->expects(self::once())->method('cancel')->with(self::anything(), 'abandoned', self::anything());
        $dispatcher->expects(self::once())->method('refund')
            ->with(self::anything(), null, 'duplicate', ['description' => 'Order 7']);
        $provider = $this->provider(dispatcher: $dispatcher);

        $provider->handleAction('capture', ['capture_reason' => 'shipped'], $this->context());
        $provider->handleAction('cancel', ['cancel_reason' => 'abandoned'], $this->context());
        $provider->handleAction('refund', ['refund_reason' => 'duplicate', 'refund_description' => 'Order 7'], $this->context());
    }

    private function contract(?string $orderId = 'order-1'): PaymentContractInterface
    {
        $contract = $this->createStub(PaymentContractInterface::class);
        $contract->method('getOrderId')->willReturn($orderId);

        return $contract;
    }

    private function context(): PaymentPanelContext
    {
        return new PaymentPanelContext('order-1', 'oe_payments_stripe_wallet', $this->contract());
    }

    private function provider(
        ?AdminActionDispatcherInterface $dispatcher = null,
        ?UserDataValidatorInterface $validator = null,
        ?AdminValidationFeedbackInterface $feedback = null,
        ?AdminActionBoundsInterface $bounds = null,
        bool $noOrder = false,
    ): StripePaymentPanelProvider {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $loader = $this->createStub(StripePanelOrderLoader::class);
        $loader->method('loadById')->willReturn($noOrder ? null : $order);
        $bounds ??= $this->createStub(AdminActionBoundsInterface::class);
        $viewData = $this->createStub(StripePanelViewDataBuilder::class);

        return new StripePaymentPanelProvider(
            actionDispatcher: $dispatcher ?? $this->createStub(AdminActionDispatcherInterface::class),
            viewDataBuilder: $viewData,
            orderLoader: $loader,
            userDataValidator: $validator ?? $this->createStub(UserDataValidatorInterface::class),
            validationFeedback: $feedback ?? $this->createStub(AdminValidationFeedbackInterface::class),
            amountValidator: new AdminAmountValidator(),
            actionBounds: $bounds,
        );
    }
}
