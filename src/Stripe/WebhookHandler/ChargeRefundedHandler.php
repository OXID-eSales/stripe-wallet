<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\WebhookHandler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use Psr\Log\LoggerInterface;

/**
 * Handler for charge.refunded webhook events.
 *
 * Updates contract with refund information when charges are refunded.
 *
 * @since Sprint 13
 */
final class ChargeRefundedHandler implements WebhookEventHandlerInterface
{
    private const EVENT_TYPE = 'charge.refunded';

    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
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
        $paymentIntentId = $this->extractPaymentIntentId($event);

        if ($paymentIntentId === null) {
            return WebhookResult::failure('error', 'Missing payment intent ID in charge data');
        }

        $this->logger->info('Handling charge.refunded', [
            'event_id' => $event->id,
            'payment_intent_id' => $paymentIntentId,
        ]);

        $contract = $this->contractRepository->findByProviderOrderId($paymentIntentId);

        if ($contract === null) {
            $this->logger->info('No contract found for refund', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return WebhookResult::skipped('No contract found for payment intent');
        }

        $refundAmount = $this->extractRefundAmount($event);
        $contract->addRefundedAmount($refundAmount);
        $contract->setRefundedAt(new \DateTimeImmutable());
        $this->contractRepository->save($contract);

        $this->logger->info('charge.refunded handled successfully', [
            'payment_intent_id' => $paymentIntentId,
            'refund_amount' => $refundAmount,
        ]);

        return WebhookResult::success('refund_recorded');
    }

    /**
     * Extract payment intent ID from the charge event.
     */
    private function extractPaymentIntentId(WebhookEvent $event): ?string
    {
        $object = $event->getObject();

        // Charge events have payment_intent field
        $paymentIntentId = $object['payment_intent'] ?? null;

        return is_string($paymentIntentId) ? $paymentIntentId : null;
    }

    /**
     * Extract refund amount from the charge event.
     *
     * @return float Amount in currency units (e.g., EUR, not cents)
     */
    private function extractRefundAmount(WebhookEvent $event): float
    {
        $object = $event->getObject();

        $amountRefundedCents = $object['amount_refunded'] ?? 0;

        if (!is_numeric($amountRefundedCents)) {
            return 0.0;
        }

        // Convert from cents to currency units
        return (float) $amountRefundedCents / 100;
    }
}
