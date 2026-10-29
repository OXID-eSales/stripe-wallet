<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\ReplacedAttempts;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * OPC-175 / S2g — money on a Stripe attempt the customer left for another
 * payment method.
 *
 * The contract continues with the new attempt, so this money belongs to no
 * order: a payment is refunded in full, an authorization (manual capture) is
 * released. The outcome is recorded on the replaced attempt in the contract's
 * history, once - Stripe sends more than one event for one payment. Logged as
 * an error: the merchant must see money that came and went back.
 *
 * Not final: the webhook handler tests replace it.
 *
 * @since OPC-175
 */
class StripeLatePaymentRefunder
{
    private const PAID = 'succeeded';
    private const AUTHORIZED = 'requires_capture';
    private const REFUND_REASON = 'duplicate';

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ContractRepositoryInterface $contracts,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $attempt the replaced attempt the money arrived on (index in its history)
     */
    public function settle(PaymentContractInterface $contract, int $attempt, string $paymentIntentId): void
    {
        $history = $contract->getMetadata(ReplacedAttempts::METADATA_KEY);
        if (ReplacedAttempts::lateMoneyIn($history, $attempt) !== null) {
            return;
        }

        $outcome = $this->giveBack($paymentIntentId);
        if ($outcome === null) {
            // Not money (yet): an asynchronous method completes the session first and
            // succeeds later. Nothing is recorded, so the later event is handled.
            return;
        }

        $contract->setMetadata(ReplacedAttempts::METADATA_KEY, ReplacedAttempts::withLateMoney($history, $attempt, $outcome));
        $this->contracts->save($contract);

        $this->logger->error('Money on a replaced Stripe checkout attempt', [
            'contract_id' => $contract->getId(),
            'order_id' => $contract->getOrderId(),
            'attempt' => $attempt,
            'payment_intent_id' => $paymentIntentId,
            'outcome' => $outcome,
        ]);
    }

    private function giveBack(string $paymentIntentId): ?string
    {
        $adapter = $this->adapterFactory->getStripeAdapter();
        $status = $adapter->retrievePaymentIntent($paymentIntentId)->status;

        if ($status === self::AUTHORIZED) {
            $adapter->cancelPaymentIntent($paymentIntentId, 'abandoned');

            return 'released';
        }

        if ($status !== self::PAID) {
            return null;
        }

        $refund = $adapter->refundPayment(new RefundPaymentRequest(
            providerPaymentId: $paymentIntentId,
            reason: self::REFUND_REASON,
        ));

        return $refund->successful
            ? 'refunded:' . (string) $refund->refundId
            : 'refund_failed:' . (string) $refund->errorMessage;
    }
}
