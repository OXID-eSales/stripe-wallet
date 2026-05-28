<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
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
    public function __construct(
        private readonly OrderRefundViewDataProvider $viewDataProvider,
        private readonly OrderContractResolver $contractResolver,
        private readonly ModuleConfigurationServiceInterface $moduleConfig,
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
        ];
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
