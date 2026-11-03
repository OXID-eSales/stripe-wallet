<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\PaymentBase\Admin\Contract\AdminActionBounds;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionBoundsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptions;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionOptionsProviderInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\PaymentBase\Admin\Panel\UnsupportedPaymentActionException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Sprint I — Stripe's panel for payment-base's shared "Payment" tab.
 *
 * Thin shim: delegates view-data assembly to the existing
 * {@see \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider}.
 * Money actions are not the panel's: payment-base's MoneyOperationRunner runs them against {@see StripeMoneyGateway}.
 *
 * The panel body template (`@oe_payments_stripe_wallet/admin/panel/stripe_panel`)
 * is body-only — the shared wrapper in payment-base owns head / transfer
 * form / admin layout closes.
 */
class StripePaymentPanelProvider implements
    PaymentPanelProviderInterface,
    AdminActionOptionsProviderInterface,
    AdminActionBoundsProviderInterface
{
    /** What the classic panel's selects offer. */
    private const REFUND_REASONS = ['duplicate', 'requested_by_customer', 'fraudulent'];
    private const VOID_REASONS = ['duplicate', 'requested_by_customer', 'fraudulent', 'abandoned'];
    /** The `maxlength` of the classic panel's reason field. */
    private const REASON_MAX_LENGTH = 140;

    /**
     * Backwards-compat alias. New code should reference
     * {@see StripeDefinitions::PROVIDER} directly.
     */
    public const PROVIDER_KEY = StripeDefinitions::PROVIDER;

    private const PANEL_TEMPLATE = '@oe_payments_stripe_wallet/admin/panel/stripe_panel.html.twig';

    public function __construct(
        private readonly StripePanelViewDataBuilder $viewDataBuilder,
        private readonly StripePanelOrderLoader $orderLoader,
        private readonly AdminActionBoundsInterface $actionBounds,
    ) {
    }

    /**
     * PAY-3: what the admin API may offer and accept as reasons and description (the classic panel's choices; the
     * description is forwarded to the refund like the classic form's `refund_description`).
     */
    public function actionOptions(PaymentContractInterface $contract): AdminActionOptions
    {
        return new AdminActionOptions(self::REFUND_REASONS, self::VOID_REASONS, true, self::REASON_MAX_LENGTH);
    }

    /**
     * PAY-3: the live bounds of the intent behind the contract's order. Null (payment-base's own numbers) when the
     * contract has no order yet; unavailable when the order or Stripe cannot be read.
     */
    public function boundsFor(PaymentContractInterface $contract): ?AdminActionBounds
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null) {
            return null;
        }
        $order = $this->orderLoader->loadById($orderId);

        return $order === null ? AdminActionBounds::unavailable() : $this->actionBounds->readBounds($order);
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_KEY;
    }

    public function supports(PaymentPanelContext $context): bool
    {
        if (StripeDefinitions::isStripePayment($context->paymentType)) {
            return true;
        }
        return $context->getProviderName() === self::PROVIDER_KEY;
    }

    public function build(PaymentPanelContext $context): PaymentPanelRenderable
    {
        $order = $this->orderLoader->loadById($context->orderId);
        $viewData = $order !== null ? $this->viewDataBuilder->build($order) : [];

        return new PaymentPanelRenderable(
            templatePath: self::PANEL_TEMPLATE,
            viewData: $viewData,
            providerKey: self::PROVIDER_KEY,
        );
    }

    /**
     * VR-22 A3/A5: capture, refund and cancel never reach a provider's panel - the classic admin hands them to
     * payment-base's money runner, which ends in {@see StripeMoneyGateway}. The panel has no other action.
     *
     * @param array<string, mixed> $request
     */
    public function handleAction(string $action, array $request, PaymentPanelContext $context): void
    {
        throw new UnsupportedPaymentActionException(sprintf('The Stripe panel has no action "%s".', $action));
    }
}
