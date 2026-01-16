<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentComponent\Service\OrderPaymentStateServiceInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
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

        $this->logger->info('payment_intent.succeeded handled successfully', [
            'payment_intent_id' => $paymentIntentId,
            'contract_fulfilled' => true,
        ]);

        return WebhookResult::success('contract_fulfilled');
    }

    /**
     * Extract captured timestamp from event data.
     */
    private function extractCapturedAt(WebhookEvent $event): \DateTimeImmutable
    {
        $object = $event->getObject();

        // Try to get timestamp from charges
        $charges = $object['charges'] ?? [];
        if (is_array($charges) && isset($charges['data']) && is_array($charges['data'])) {
            foreach ($charges['data'] as $charge) {
                if (is_array($charge) && ($charge['paid'] ?? false) && isset($charge['created'])) {
                    $timestamp = (int) $charge['created'];
                    return new \DateTimeImmutable('@' . $timestamp);
                }
            }
        }

        // Fallback to event created time
        if ($event->created > 0) {
            return new \DateTimeImmutable('@' . $event->created);
        }

        // Last resort: current time
        return new \DateTimeImmutable();
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
