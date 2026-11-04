<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\LateMoney\LateMoneyResolverInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\Commit\ContractCommitServiceInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use Psr\Log\LoggerInterface;

/**
 * Handles payment_intent.succeeded webhook events.
 *
 * The fulfilment looks the contract up by providerOrderId (= the PaymentIntent id) only: an intent no contract is
 * indexed by is skipped with a logged reason, never matched by metadata. What counts as paid is `amount_received`
 * alone; an authorised-only intent (0) books nothing.
 *
 * @since Sprint 114.4
 */
class PaymentIntentSucceededWebhookHandler extends AbstractCommittingStripeWebhookHandler
{
    private const EVENT_TYPE = 'payment_intent.succeeded';

    public function __construct(
        StripeWebhookEventParser $parser,
        WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        ContractRepositoryInterface $contractRepository,
        LoggerInterface $logger,
        // VR-23 T5: money on a contract that can no longer take it goes back to the customer.
        private readonly LateMoneyResolverInterface $lateMoney,
        ContractCommitServiceInterface $contractCommit
    ) {
        parent::__construct($parser, $fulfillmentHandler, $contractRepository, $logger, $contractCommit);
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::EVENT_TYPE;
    }

    public function handle(WebhookEvent $event): StripeWebhookOutcome
    {
        $paymentIntentId = $this->parser->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return StripeWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing payment intent ID'));
        }

        $lateOutcome = $this->resolveLateMoney($event, $paymentIntentId, $this->lateMoney);
        if ($lateOutcome !== null) {
            return $lateOutcome;
        }

        $this->logger->info('Processing payment_intent.succeeded', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        $amountReceived = $this->parser->extractAmountInCurrencyUnits($event, 'amount_received');
        if ($amountReceived === null || $amountReceived <= 0.0) {
            $this->logger->info('payment_intent.succeeded carries no received amount; nothing booked', [
                'payment_intent_id' => $paymentIntentId,
            ]);

            return StripeWebhookOutcome::of(WebhookResult::skipped('No amount received'));
        }

        // GRAPH-QL / PS3: a PENDING contract is committed from the paid intent
        // before the fulfilment; the return leg is no longer the only way.
        $ended = $this->commitOpenContract(
            $this->contractOfPaymentIntent($event, $paymentIntentId),
            $paymentIntentId,
            $paymentIntentId,
            $amountReceived,
            $this->currencyOf($event),
            ['paymentIntentId' => $paymentIntentId]
        );
        if ($ended !== null) {
            return $ended;
        }

        $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId, $amountReceived);

        if ($result === null) {
            $this->logger->warning('payment_intent.succeeded: no contract is indexed by this PaymentIntent id', [
                'payment_intent_id' => $paymentIntentId,
            ]);

            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
        }

        return $this->mapHandlerResult(
            $result,
            $paymentIntentId,
            'contract_fulfilled',
            'Contract already fulfilled or not in COMMITTED state'
        );
    }

    private function currencyOf(WebhookEvent $event): string
    {
        $currency = $event->getObject()['currency'] ?? '';

        return is_string($currency) ? strtoupper($currency) : '';
    }
}
