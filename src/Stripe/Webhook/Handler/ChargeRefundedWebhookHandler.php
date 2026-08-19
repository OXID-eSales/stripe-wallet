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
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use Psr\Log\LoggerInterface;

/**
 * Handles charge.refunded webhook events.
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
        LoggerInterface $logger
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

        $refundedAmount = $this->parser->extractAmountInCurrencyUnits($event, 'amount_refunded');
        if ($refundedAmount === null) {
            // Sprint 133 (F9): recording a 0.00 refund for an event whose amount
            // we could not read would be a fiction; fail so Stripe retries.
            $this->logger->error('charge.refunded carries no readable amount_refunded', [
                'payment_intent_id' => $paymentIntentId,
                'event_id' => $event->id,
            ]);

            return StripeWebhookOutcome::of(
                WebhookResult::failure('invalid_event', 'Missing or malformed amount_refunded in charge')
            );
        }

        $this->logger->info('Processing charge.refunded', [
            'payment_intent_id' => $paymentIntentId,
            'refunded_amount' => $refundedAmount,
        ]);

        $result = $this->fulfillmentHandler->handleChargeRefunded($paymentIntentId, $refundedAmount);

        return $this->mapHandlerResult(
            $result,
            $paymentIntentId,
            'charge_refunded',
            'Contract not in FULFILLED state'
        );
    }
}
