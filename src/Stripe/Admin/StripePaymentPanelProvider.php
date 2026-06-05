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
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;
use Throwable;

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
class StripePaymentPanelProvider implements PaymentPanelProviderInterface
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
        private readonly UserDataValidatorInterface $userDataValidator,
        private readonly AdminValidationFeedbackInterface $validationFeedback,
        private readonly AdminAmountValidator $amountValidator,
        private readonly AdminActionBoundsInterface $actionBounds,
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
        $description = $this->parseString($request['refund_description'] ?? null);

        $failures = $this->collectTextFailures('refundDescription', $description);
        $amountResult = $this->validateAmount($order, 'refund', $request['refund_amount'] ?? null);
        if (!$amountResult->isOk()) {
            $failures[] = $this->amountFailure('refundAmount', (string) $amountResult->code);
        }

        if ($failures !== []) {
            $this->validationFeedback->store((string) $order->getId(), 'refund', $failures);
            return;
        }

        $this->actionDispatcher->refund(
            $order,
            $amountResult->amount,
            $this->parseString($request['refund_reason'] ?? null),
            ['description' => $description],
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function handleCapture(Order $order, array $request): void
    {
        $reason = $this->parseString($request['capture_reason'] ?? null);

        // Sprint 120/121 (STRP-129): pre-dispatch gate — on invalid input the
        // capture event never fires (no contract transition, no Stripe call).
        $failures = $this->collectTextFailures('captureReason', $reason);
        $amountResult = $this->validateAmount($order, 'capture', $request['capture_amount'] ?? null);
        if (!$amountResult->isOk()) {
            $failures[] = $this->amountFailure('captureAmount', (string) $amountResult->code);
        }

        if ($failures !== []) {
            $this->validationFeedback->store((string) $order->getId(), 'capture', $failures);
            return;
        }

        $this->actionDispatcher->capture(
            $order,
            $amountResult->amount,
            $reason,
            ['paymentIntentId' => $this->parseString($request['payment_intent_id'] ?? null)],
        );
    }

    /**
     * Character-level validation of an optional free-text field via the
     * shared Sprint 119 chain (UserDataValidator -> ValidationBase ->
     * validation-rules.php). Null (absent) input is valid.
     *
     * @return FieldValidationFailure[]
     */
    private function collectTextFailures(string $logicalField, ?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return $this->userDataValidator->validateFieldMap([$logicalField => $value], 'admin');
    }

    /**
     * Semantic amount validation (Sprint 121, STRP-129). Absent input
     * short-circuits to ok(null) = full action — no PSP bound lookup is
     * wasted on it. Bound resolution failures FAIL CLOSED: a partial
     * amount is never dispatched unchecked because the PSP was unreachable.
     */
    private function validateAmount(Order $order, string $action, mixed $raw): AmountValidationResult
    {
        if ($raw === null || $raw === '') {
            return AmountValidationResult::ok(null);
        }

        try {
            $bound = $action === 'capture'
                ? $this->actionBounds->captureBound($order)
                : $this->actionBounds->refundBound($order);
        } catch (Throwable) {
            return AmountValidationResult::failure(AmountValidationResult::CODE_BOUND_UNAVAILABLE);
        }

        return $this->amountValidator->validate($raw, $bound, $this->orderCurrency($order));
    }

    private function amountFailure(string $logicalField, string $code): FieldValidationFailure
    {
        return new FieldValidationFailure(
            field: $logicalField,
            addressKind: 'admin',
            code: $code,
            offendingChar: null,
            oxidColumn: null,
        );
    }

    /**
     * Order currency for precision validation; '' (= 2-decimal default)
     * when unavailable. Magic-wrapper read — getFieldData() warns on
     * partially loaded models.
     */
    private function orderCurrency(Order $order): string
    {
        /** @phpstan-ignore-next-line OXID core magic property */
        $wrapper = $order->oxorder__oxcurrency ?? null;
        if (is_object($wrapper) && isset($wrapper->value) && is_string($wrapper->value)) {
            return $wrapper->value;
        }
        return '';
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

    private function parseString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
