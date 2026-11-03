<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use Psr\Log\LoggerInterface;

/**
 * Books what Stripe captured for a contract when a webhook (or the backfill command) reports it, through
 * payment-base's ProviderCaptureLedger.
 *
 * The capture id is the PaymentIntent id: Stripe makes at most one capture per PaymentIntent (a partial capture
 * releases the rest), and the id is in every event that reports a payment, which a charge id is not (the Checkout
 * Session event carries none). The admin's capture is booked under the same id by payment-base's runner, so
 * whichever path reports first books it and the other is a duplicate.
 *
 * A contract that already carries a captured amount was booked before: by the ledger, or by a version of this module
 * that booked without capture ids. Its redelivered webhook must not add the amount again.
 *
 * Sprint 137 · Story 2: extracted from WebhookContractFulfillmentHandler once the backfill command became the second
 * caller. VR-22 A5: books through the ledger instead of writing its own rows.
 */
final class ContractCaptureRecorder
{
    private const SOURCE = 'webhook';

    public function __construct(
        private readonly ProviderCaptureLedgerInterface $captureLedger,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param float|null $amountReceived What Stripe collected, in currency units; null when unknown
     */
    public function record(PaymentContractInterface $contract, ?float $amountReceived, string $paymentIntentId): void
    {
        if ($contract->getCapturedAmount() !== null) {
            return;
        }

        if ($amountReceived === null || $amountReceived <= 0.0) {
            // Sprint 133 · Story 9 (F9): never book a fictitious 0.00 capture; a loud log line is the honest outcome.
            $this->logger->error('Capture not booked: Stripe reported no amount collected', [
                'contract_id' => $contract->getId(),
                'payment_intent_id' => $paymentIntentId,
            ]);

            return;
        }

        $this->captureLedger->book((string) $contract->getId(), $paymentIntentId, $amountReceived, $contract->getCurrency(), self::SOURCE);
    }
}
