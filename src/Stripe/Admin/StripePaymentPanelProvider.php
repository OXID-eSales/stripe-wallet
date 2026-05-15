<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Admin\Contract\AdminActionDispatcherInterface;
use OxidEsales\PaymentBase\Admin\Contract\PaymentPanelProviderInterface;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelContext;
use OxidEsales\PaymentBase\Admin\Panel\PaymentPanelRenderable;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;

/**
 * Sprint I — Stripe's panel for payment-base's shared "Payment" tab.
 *
 * Thin shim: delegates view-data assembly to the existing
 * {@see \OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider}
 * (renamed target for dependency purposes only — see services.yaml for the
 * real binding) and dispatches refund / capture / cancel actions through
 * the existing {@see OrderActionDispatcher}. No new events; no new services;
 * no duplicated refund/capture logic.
 *
 * The panel body template (`@oe_payments_stripe_wallet/admin/panel/stripe_panel`)
 * is body-only — the shared wrapper in payment-base owns head / transfer
 * form / admin layout closes.
 */
final class StripePaymentPanelProvider implements PaymentPanelProviderInterface
{
    /**
     * Backwards-compat alias. New code should reference
     * {@see StripeDefinitions::PROVIDER} directly.
     */
    public const PROVIDER_KEY = StripeDefinitions::PROVIDER;

    private const PANEL_TEMPLATE = '@oe_payments_stripe_wallet/admin/panel/stripe_panel.html.twig';

    public function __construct(
        private readonly AdminActionDispatcherInterface $actionDispatcher,
        private readonly StripePanelViewDataBuilder $viewDataBuilder,
        private readonly StripePanelOrderLoader $orderLoader,
    ) {
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

    public function handleAction(string $action, array $request, PaymentPanelContext $context): void
    {
        $order = $this->orderLoader->loadById($context->orderId);
        if ($order === null) {
            return;
        }

        match ($action) {
            'refund'  => $this->handleRefund($order, $request),
            'capture' => $this->handleCapture($order, $request),
            'cancel'  => $this->handleCancel($order, $request),
            default   => null,
        };
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleRefund(Order $order, array $request): void
    {
        $this->actionDispatcher->refund(
            $order,
            $this->parseAmount($request['refund_amount'] ?? null),
            $this->parseString($request['refund_reason'] ?? null),
            ['description' => $this->parseString($request['refund_description'] ?? null)],
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleCapture(Order $order, array $request): void
    {
        $this->actionDispatcher->capture(
            $order,
            $this->parseAmount($request['capture_amount'] ?? null),
            $this->parseString($request['capture_reason'] ?? null),
            ['paymentIntentId' => $this->parseString($request['payment_intent_id'] ?? null)],
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleCancel(Order $order, array $request): void
    {
        $this->actionDispatcher->cancel(
            $order,
            $this->parseString($request['cancel_reason'] ?? null),
            ['paymentIntentId' => $this->parseString($request['payment_intent_id'] ?? null)],
        );
    }

    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private function parseString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
