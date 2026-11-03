<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Payment\CancelGatewayInterface;
use OxidEsales\PaymentBase\Payment\CancelRequest;
use OxidEsales\PaymentBase\Payment\CaptureGatewayInterface;
use OxidEsales\PaymentBase\Payment\CaptureRequest;
use OxidEsales\PaymentBase\Payment\CaptureResult;
use OxidEsales\PaymentBase\Payment\RefundGatewayInterface;
use OxidEsales\PaymentBase\Payment\RefundRequest;
use OxidEsales\PaymentBase\Payment\RefundResult;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;

/**
 * VR-22 A5: Stripe's side of the one admin money path. payment-base's MoneyOperationRunner owns the rights, the
 * idempotency claim, the order lock, the amount policy and the booking (capture and refund ledgers); this class
 * only asks Stripe and turns a refusal into the sentence the admin reads. The sentence names the operation and
 * Stripe's error code, never Stripe's own text (it carries PaymentIntent and charge ids).
 *
 * Stripe makes at most one capture per PaymentIntent (a partial capture releases the rest), so the PaymentIntent id is
 * the capture id the booking is keyed by: the webhook books under the same id ({@see \OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder}).
 *
 * Void books nothing: the contract's state follows Stripe's `payment_intent.canceled` webhook.
 */
final class StripeMoneyGateway implements CaptureGatewayInterface, RefundGatewayInterface, CancelGatewayInterface
{
    private const PAYMENT_INTENT_PREFIX = 'pi_';
    private const ADMIN_INITIATOR = 'admin';

    public function __construct(
        private readonly CaptureServiceInterface $captures,
        private readonly RefundServiceInterface $refunds,
        private readonly CancelAuthorizationServiceInterface $cancellations,
    ) {
    }

    public function providerName(): string
    {
        return StripeDefinitions::PROVIDER;
    }

    public function capture(CaptureRequest $request): CaptureResult
    {
        $paymentIntentId = $this->paymentIntentOf($request->contract);
        $response = $this->captures->captureAtStripe($paymentIntentId, $request->amount, $request->currency, $this->captureMetadata($request));
        if (!$response->isSuccessful()) {
            throw $this->refusal('capture', $response->getErrorCode());
        }

        return new CaptureResult($paymentIntentId, (float) $response->amountCaptured);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $orderId = $request->contract->getOrderId();
        if ($orderId === null) {
            throw new AdminActionRefusedException('no_order', 'The payment has no order to refund.');
        }

        $response = $this->refunds->processRefund(
            $orderId,
            $this->paymentIntentOf($request->contract),
            $request->reason,
            $request->description,
            self::ADMIN_INITIATOR,
            $request->amount
        );
        if (!$response->successful) {
            throw $this->refusal('refund', $response->errorCode);
        }

        $status = $response->status === StripeStatusMapper::STRIPE_SUCCEEDED ? RefundResult::SUCCEEDED : RefundResult::PENDING;

        return new RefundResult((string) $response->refundId, (float) $response->amountRefunded, $status);
    }

    public function cancel(CancelRequest $request): void
    {
        $response = $this->cancellations->cancelAuthorization($this->paymentIntentOf($request->contract), $request->reason);
        if (!$response->isSuccessful()) {
            throw $this->refusal('cancel', $response->getErrorCode());
        }
    }

    private function paymentIntentOf(PaymentContractInterface $contract): string
    {
        $providerOrderId = (string) $contract->getProviderOrderId();
        if (!str_starts_with($providerOrderId, self::PAYMENT_INTENT_PREFIX)) {
            throw new AdminActionRefusedException('no_payment_intent', 'Stripe has not created a payment for this order yet.');
        }

        return $providerOrderId;
    }

    /**
     * @return array<string, string>
     */
    private function captureMetadata(CaptureRequest $request): array
    {
        $metadata = ['initiator' => self::ADMIN_INITIATOR, 'contract_id' => (string) $request->contract->getId()];
        if ($request->reason !== null) {
            $metadata['reason'] = $request->reason;
        }

        return $metadata;
    }

    private function refusal(string $operation, ?string $stripeErrorCode): AdminActionRefusedException
    {
        $code = $stripeErrorCode !== null && $stripeErrorCode !== '' ? $stripeErrorCode : 'refused';

        return new AdminActionRefusedException($code, sprintf('Stripe refused the %s (%s).', $operation, $code));
    }
}
