<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefund;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefundListerInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use Psr\Log\LoggerInterface;

/**
 * Handles charge.refunded webhook events.
 *
 * VR-21 F5: the refunds are booked one by one by Stripe's refund id (through the fulfillment handler and
 * payment-base's ledger). The refunds come from the event's `refunds.data`; an event that does not carry the
 * list (newer API versions) or carries it cut short is completed from Stripe's own list of the charge's refunds.
 * Failing to ask Stripe propagates, so the delivery fails and Stripe retries it.
 *
 * @since Sprint 114.4
 */
class ChargeRefundedWebhookHandler extends AbstractStripeWebhookHandler
{
    private const EVENT_TYPE = 'charge.refunded';

    public function __construct(
        StripeWebhookEventParser $parser,
        WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        ContractRepositoryInterface $contractRepository,
        LoggerInterface $logger,
        private readonly ChargeRefundListerInterface $refundLister
    ) {
        parent::__construct($parser, $fulfillmentHandler, $contractRepository, $logger);
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT_TYPE;
    }

    public function handle(WebhookEvent $event): StripeWebhookOutcome
    {
        $paymentIntentId = $this->parser->extractPaymentIntentIdFromCharge($event);
        if ($paymentIntentId === null) {
            return StripeWebhookOutcome::of(
                WebhookResult::failure('invalid_event', 'Missing payment intent ID in charge')
            );
        }

        if ($event->getObjectId() === null) {
            return StripeWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing charge ID'));
        }

        $this->logger->info('Processing charge.refunded', [
            'payment_intent_id' => $paymentIntentId,
            'event_id' => $event->id,
        ]);

        $result = $this->fulfillmentHandler->handleChargeRefunded($paymentIntentId, $this->refundsOf($event));

        return $this->mapHandlerResult(
            $result,
            $paymentIntentId,
            'charge_refunded',
            'Contract not in FULFILLED state'
        );
    }

    /**
     * @return list<ChargeRefund>
     */
    private function refundsOf(WebhookEvent $event): array
    {
        if ($this->parser->listsEveryRefund($event)) {
            return $this->parser->extractRefunds($event);
        }

        return $this->refundLister->listFor((string) $event->getObjectId());
    }
}
