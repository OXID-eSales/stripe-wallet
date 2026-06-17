<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\OrderContractResolver;

/**
 * Sprint I — flat view-data shape for the Stripe panel body template.
 *
 * Reuses the existing {@see OrderRefundViewDataProvider} (Stripe API wrapper)
 * and {@see OrderContractResolver} (contract id lookup). No new logic —
 * pure projection onto a twig-consumable array so the shared admin
 * controller can hand a single `panel` array to the body template.
 */
class StripePanelViewDataBuilder
{
    /**
     * Per-code message keys for semantic amount failures (Sprint 121).
     * Char-level failures route through the message formatter instead.
     *
     * @var array<string, string>
     */
    private const AMOUNT_MESSAGE_KEYS = [
        AmountValidationResult::CODE_MALFORMED         => 'STRIPE_VALIDATION_AMOUNT_MALFORMED',
        AmountValidationResult::CODE_NOT_POSITIVE      => 'STRIPE_VALIDATION_AMOUNT_NOT_POSITIVE',
        AmountValidationResult::CODE_PRECISION         => 'STRIPE_VALIDATION_AMOUNT_PRECISION',
        AmountValidationResult::CODE_EXCEEDS_BOUND     => 'STRIPE_VALIDATION_AMOUNT_EXCEEDS_BOUND',
        AmountValidationResult::CODE_BOUND_UNAVAILABLE => 'STRIPE_VALIDATION_AMOUNT_BOUND_UNAVAILABLE',
    ];

    public function __construct(
        private readonly OrderRefundViewDataProvider $viewDataProvider,
        private readonly OrderContractResolver $contractResolver,
        private readonly ModuleConfigurationServiceInterface $moduleConfig,
        private readonly AdminValidationFeedbackInterface $validationFeedback,
        private readonly MessageFormatterInterface $messageFormatter,
        private readonly LanguageTranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        $provider = $this->viewDataProvider;
        $paymentIntent = $provider->getPaymentIntent($order);
        $isTestMode = $this->moduleConfig->isTestMode();

        return [
            // Identity
            'order'               => $order,
            'orderId'             => (string) $order->getId(),
            'orderNumber'         => $this->readField($order, 'oxorder__oxordernr'),
            'contractId'          => (string) $this->contractResolver->getContractIdFromOrder($order),
            'paymentType'         => $this->readField($order, 'oxorder__oxpaymenttype'),
            'transactionId'       => $this->readField($order, 'oxorder__oxtransid'),
            'externalTransId'     => $this->readField($order, 'oxorder__stripeexternaltransid'),
            'currency'            => $this->readField($order, 'oxorder__oxcurrency'),

            // Amounts
            'amount'              => $paymentIntent !== null
                ? number_format(
                    AmountConverter::toMajorUnits(
                        $paymentIntent->amount,
                        strtoupper($paymentIntent->currency)
                    ),
                    2,
                    '.',
                    ''
                )
                : '',
            'capturedAmount'      => $this->orderCapturedAmount($order),
            'refundedAmount'      => $this->orderRefundedAmount($order),
            'hasRefunds'          => $this->orderHasRefunds($order),
            'capturableAmount'    => $provider->getCaptureableAmount($order),
            'capturableRaw'       => $provider->getCaptureableRaw($order),
            'remainingRefundable' => $provider->getRemainingRefundableAmount($order),
            'remainingRefundableRaw' => $provider->getRemainingRefundableRaw($order),

            // Action eligibility
            'isCapturable'        => $provider->isOrderCapturable($order),
            'isRefundable'        => $provider->isOrderRefundable($order),
            'isCancellable'       => $provider->isOrderCapturable($order),

            // Dashboard link
            'dashboardPrefix'     => $isTestMode ? '/test' : '',
            'isTestMode'          => $isTestMode,

            // API state
            'hasApiError'         => $provider->hasApiError(),
            'apiError'            => $provider->getApiError(),

            // Transaction history
            'transactions'        => $provider->getStripeTransactionHistory($order),

            // Sprint 120 (STRP-129): admin validation failures from the
            // session-backed feedback channel, rendered as translated messages.
            'validationErrors'    => $this->buildValidationErrors((string) $order->getId()),
        ];
    }

    /**
     * Consumes (read-and-clear) the stored validation failures and formats
     * each via the Stripe message formatter — the same message shape the
     * storefront shows ("The {label} field is not valid. Allowed symbols
     * are: ..."), translated through the admin language files.
     *
     * @return list<string>
     */
    private function buildValidationErrors(string $orderId): array
    {
        $messages = [];

        foreach ($this->validationFeedback->consume($orderId) as $entry) {
            $messages[] = $this->formatEntryMessage($entry);
        }

        return $messages;
    }

    /**
     * Semantic amount codes (Sprint 121) get per-code messages; char-level
     * codes keep the Sprint 119/120 "allowed symbols" formatter. Numeric
     * interpolation is deliberately omitted — the panel already displays
     * the capturable/refundable figures next to the forms.
     *
     * @param array{field: string, code: string, char: ?string, action: string} $entry
     */
    private function formatEntryMessage(array $entry): string
    {
        $amountKey = self::AMOUNT_MESSAGE_KEYS[$entry['code']] ?? null;
        if ($amountKey !== null) {
            return $this->translator->translateString($amountKey);
        }

        return $this->messageFormatter->format(
            $entry['field'],
            $entry['code'],
            $entry['char'],
        );
    }

    /**
     * Invalidate the view-data provider's per-request API cache.
     *
     * Called by StripePaymentPanelProvider after each successful action dispatch
     * (refund / capture / cancel) so the subsequent render() in the same HTTP request
     * reads fresh post-action data from Stripe instead of the stale pre-action charge.
     *
     * Sprint 127 (STRP-15123): fixes the partial-refund amount prefill bug where
     * the refund Amount field showed the full captured amount after a partial refund.
     */
    public function resetViewCache(): void
    {
        $this->viewDataProvider->resetCache();
    }

    /**
     * Read a legacy OXID field (`oxorder__*`) via the magic field wrapper.
     * Returns '' on miss.
     */
    private function readField(Order $order, string $field): string
    {
        /** @phpstan-ignore-next-line OXID core magic property */
        $wrapper = $order->$field ?? null;
        if (is_object($wrapper) && isset($wrapper->value) && (is_string($wrapper->value) || is_numeric($wrapper->value))) {
            return (string) $wrapper->value;
        }
        return '';
    }

    private function orderCapturedAmount(Order $order): string
    {
        /** @phpstan-ignore-next-line OXID core: Stripe-extended order model method */
        return method_exists($order, 'getStripeCapturedAmount') ? (string) $order->getStripeCapturedAmount() : '';
    }

    private function orderRefundedAmount(Order $order): string
    {
        /** @phpstan-ignore-next-line OXID core: Stripe-extended order model method */
        return method_exists($order, 'getStripeRefundedAmount') ? (string) $order->getStripeRefundedAmount() : '';
    }

    private function orderHasRefunds(Order $order): bool
    {
        /** @phpstan-ignore-next-line OXID core: Stripe-extended order model method */
        return method_exists($order, 'hasStripeRefunds') && (bool) $order->hasStripeRefunds();
    }
}
