<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\OrderPaymentStateServiceInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use Psr\Log\LoggerInterface;

/**
 * Handler for payment_intent.succeeded webhook events.
 *
 * Updates OXPAID timestamp on orders and fulfills contracts
 * when payments are successfully captured.
 *
 * Sprint 16: Uses OrderPaymentStateService for DRY compliance.
 * Sprint 18: Uses ContractFulfillmentService for DRY fulfillment.
 *
 * @since Sprint 13
 */
final class PaymentIntentSucceededHandler implements WebhookEventHandlerInterface
{
    private const EVENT_TYPE = 'payment_intent.succeeded';

    public function __construct(
        private readonly OrderPaymentStateServiceInterface $orderPaymentStateService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractFulfillmentServiceInterface $contractFulfillmentService,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT_TYPE;
    }

    /**
     * @inheritDoc
     */
    public function handle(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $event->getObjectId();

        if ($paymentIntentId === null) {
            return WebhookResult::failure('error', 'Missing payment intent ID in event data');
        }

        $this->logger->info('Handling payment_intent.succeeded', [
            'event_id' => $event->id,
            'payment_intent_id' => $paymentIntentId,
        ]);

        // Sprint 15: Contract is REQUIRED - no backward compatibility
        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);

        if ($contract === null) {
            // Log error but return success (200) so Stripe doesn't retry
            $this->logger->error('NO_CONTRACT: Payment without contract is invalid', [
                'payment_intent_id' => $paymentIntentId,
                'event_id' => $event->id,
            ]);
            return WebhookResult::success('no_contract_logged');
        }

        // Update OXPAID timestamp
        $capturedAt = $this->extractCapturedAt($event);
        $this->updateOrderPaidTimestamp($paymentIntentId, $capturedAt);

        // Sprint 18: Use ContractFulfillmentService for DRY fulfillment
        $fulfilled = $this->contractFulfillmentService->fulfill($contract);

        if (!$fulfilled) {
            $this->logger->info('Contract not fulfilled (guard failed)', [
                'payment_intent_id' => $paymentIntentId,
                'contract_state' => $contract->getState()->getValue(),
            ]);
            return WebhookResult::success('contract_not_fulfilled');
        }

        $this->recordCaptureTransaction($contract, $event);

        $this->logger->info('payment_intent.succeeded handled successfully', [
            'payment_intent_id' => $paymentIntentId,
            'contract_fulfilled' => true,
        ]);

        return WebhookResult::success('contract_fulfilled');
    }

    private function recordCaptureTransaction(
        \OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract,
        WebhookEvent $event
    ): void {
        $object = $event->getObject();
        $rawAmount = $object['amount_received'] ?? 0;
        $amount = is_numeric($rawAmount) ? (int) $rawAmount / 100 : 0;
        $rawCurrency = $object['currency'] ?? 'EUR';
        $currency = is_string($rawCurrency) ? $rawCurrency : 'EUR';

        $transaction = new Transaction(
            id: 'wh_cap_' . bin2hex(random_bytes(16)),
            shopId: 1,
            orderId: $contract->getOrderId() ?? '',
            contractId: $contract->getId(),
            provider: 'stripe',
            type: 'capture',
            status: 'completed',
            amount: (float) $amount,
            currency: $currency
        );
        $transaction->setTransactionId($event->getObjectId());
        $transaction->setProviderOrderId($contract->getProviderOrderId());

        $this->transactionRepository->save($transaction);
    }

    /**
     * Extract captured timestamp from event data.
     */
    private function extractCapturedAt(WebhookEvent $event): \DateTimeImmutable
    {
        return $this->extractChargeTimestamp($event->getObject())
            ?? ($event->created > 0 ? new \DateTimeImmutable('@' . $event->created) : new \DateTimeImmutable());
    }

    /**
     * Extract timestamp from the first paid charge in event data.
     *
     * @param array<string, mixed> $object
     */
    private function extractChargeTimestamp(array $object): ?\DateTimeImmutable
    {
        $chargesData = $this->getChargesData($object);

        foreach ($chargesData as $charge) {
            if (!is_array($charge) || empty($charge['paid']) || !isset($charge['created'])) {
                continue;
            }
            $timestamp = is_numeric($charge['created']) ? (int) $charge['created'] : 0;
            return new \DateTimeImmutable('@' . $timestamp);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $object
     * @return array<mixed>
     */
    private function getChargesData(array $object): array
    {
        $charges = $object['charges'] ?? [];
        if (!is_array($charges) || !isset($charges['data']) || !is_array($charges['data'])) {
            return [];
        }
        return $charges['data'];
    }

    /**
     * Update OXPAID timestamp on the order.
     *
     * Sprint 16: Uses OrderPaymentStateService for DRY compliance.
     */
    private function updateOrderPaidTimestamp(string $paymentIntentId, \DateTimeImmutable $capturedAt): void
    {
        $this->orderPaymentStateService->updatePaidTimestampByTransactionId(
            $paymentIntentId,
            $capturedAt
        );
    }
}
