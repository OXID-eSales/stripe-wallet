<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;
use OxidEsales\PaymentBase\Return\ReturnResolution;
use OxidEsales\PaymentBase\Return\ReturnResolverInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutReturnServiceInterface;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult;
use Throwable;

/**
 * Wraps Stripe's {@see CheckoutReturnServiceInterface::validateReturn} and
 * maps the Stripe-specific {@see CheckoutReturnResult} onto the
 * provider-neutral {@see ReturnResolution}.
 *
 * Pure data producer — no state transitions, no saves, no dispatches.
 * Shared handlers own that work.
 *
 * Expected context keys:
 *   - `checkoutSessionId` (string, required) — Stripe session id from URL.
 *   - `contract_token`    (string, required) — CSRF token for return.
 */
class StripeReturnResolver implements ReturnResolverInterface
{
    public function __construct(
        private readonly CheckoutReturnServiceInterface $checkoutReturnService,
    ) {
    }

    public function resolve(
        PaymentContractInterface $contract,
        EventContextInterface $context,
    ): ReturnResolution {
        $sessionId = $context->get('checkoutSessionId');
        $contractToken = $context->get('contract_token');
        if (!is_string($sessionId) || $sessionId === '') {
            return ReturnResolution::failed('missing_checkout_session_id', 'Stripe session id missing on return');
        }
        if (!is_string($contractToken) || $contractToken === '') {
            return ReturnResolution::failed('missing_contract_token', 'Contract token missing on return');
        }

        $contractId = $contract->getId();
        if ($contractId === null || $contractId === '') {
            return ReturnResolution::failed('missing_contract_id', 'Contract id missing on return');
        }

        try {
            $result = $this->checkoutReturnService->validateReturn($sessionId, $contractId, $contractToken);
        } catch (Throwable $e) {
            return ReturnResolution::failed('validate_return_threw', $e->getMessage());
        }

        if (!$result->isSuccessful()) {
            return ReturnResolution::failed(
                $result->getErrorCode() ?? 'validate_return_failed',
                $result->getErrorMessage() ?? 'Stripe validation failed',
            );
        }

        $paymentIntentId = (string) ($result->getPaymentIntentId() ?? '');
        if ($paymentIntentId === '') {
            return ReturnResolution::failed('missing_payment_intent', 'Stripe returned no payment_intent_id');
        }

        // Sprint 133 (F7): a successful CheckoutReturnResult always carries an
        // amount and a currency, so the previous `?? 0.0` / `?? 'EUR'` were
        // unreachable — they documented an impossible state and would have
        // masked a real regression by booking a 0.00 EUR authorisation.
        $amount = $result->getAmount();
        $currency = $result->getCurrency();
        if ($amount === null || $currency === null || $currency === '') {
            return ReturnResolution::failed(
                'missing_amount_or_currency',
                'Stripe returned no amount or currency for a completed payment'
            );
        }

        return $result->isRequiresCapture()
            ? ReturnResolution::authorized($paymentIntentId, $paymentIntentId, $amount, $currency)
            : ReturnResolution::readyToCommit($paymentIntentId, $paymentIntentId, $amount, $currency);
    }
}
