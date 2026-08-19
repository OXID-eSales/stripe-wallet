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
use OxidEsales\PaymentBase\Service\ReturnSecurityValidatorInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutReturnServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    private readonly LoggerInterface $logger;

    /**
     * @param ReturnSecurityValidatorInterface $securityValidator Scores the returning
     *        session (IP / timing / user-agent). Sprint 133 · Story 5 (F5): this
     *        validator existed, was DI-bound and unit-tested, and had NO production
     *        caller — a session-hijack defence that never ran, invisible in CI
     *        because its own tests were green.
     * @param bool $enforceSecurityCheck Advisory by default. Rejecting a return
     *        happens *after* Stripe authorised the payment, so a false positive
     *        strands a paying customer with no order; the score is therefore
     *        recorded and logged, and only blocks when a merchant opts in.
     */
    public function __construct(
        private readonly CheckoutReturnServiceInterface $checkoutReturnService,
        private readonly ReturnSecurityValidatorInterface $securityValidator,
        private readonly bool $enforceSecurityCheck = false,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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

        $securityFailure = $this->applyReturnSecurity($contract, $context);
        if ($securityFailure !== null) {
            return $securityFailure;
        }

        return $result->isRequiresCapture()
            ? ReturnResolution::authorized($paymentIntentId, $paymentIntentId, $amount, $currency)
            : ReturnResolution::readyToCommit($paymentIntentId, $paymentIntentId, $amount, $currency);
    }

    /**
     * Score the returning session, record the outcome on the contract, and only
     * reject when the merchant enabled enforcement.
     */
    private function applyReturnSecurity(
        PaymentContractInterface $contract,
        EventContextInterface $context,
    ): ?ReturnResolution {
        $result = $this->securityValidator->validateReturn($contract, $this->buildSecurityContext($context));

        $contract->setMetadata('return_security_score', $result->getScore());
        $contract->setMetadata('return_security_warnings', $result->getWarnings());

        if ($result->isAllowed()) {
            return null;
        }

        $this->logger->warning('Suspicious Stripe return session', [
            'contract_id' => $contract->getId(),
            'score' => $result->getScore(),
            'warnings' => $result->getWarnings(),
            'enforced' => $this->enforceSecurityCheck,
        ]);

        if (!$this->enforceSecurityCheck) {
            return null;
        }

        return ReturnResolution::failed(
            'security_check_failed',
            sprintf('Return session rejected (score %d)', $result->getScore())
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSecurityContext(EventContextInterface $context): array
    {
        return [
            'ip' => $this->stringFrom($context, 'ip') ?? $this->serverValue('REMOTE_ADDR'),
            'user_agent' => $this->stringFrom($context, 'user_agent') ?? $this->serverValue('HTTP_USER_AGENT'),
            'country' => $this->stringFrom($context, 'country'),
        ];
    }

    private function stringFrom(EventContextInterface $context, string $key): ?string
    {
        $value = $context->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function serverValue(string $key): ?string
    {
        $value = $_SERVER[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
