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
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles payment_intent.canceled webhook events.
 *
 * @since Sprint 114.4
 */
final class PaymentIntentCanceledWebhookHandler extends AbstractStripeWebhookHandler
{
    private const EVENT_TYPE = 'payment_intent.canceled';

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
        $paymentIntentId = $this->parser->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return StripeWebhookOutcome::of(WebhookResult::failure('invalid_event', 'Missing payment intent ID'));
        }

        $this->logger->info('Processing payment_intent.canceled', [
            'payment_intent_id' => $paymentIntentId,
            'reason' => $this->parser->extractCancellationReason($event),
        ]);

        $result = $this->fulfillmentHandler->handlePaymentCanceled(
            $paymentIntentId,
            $this->parser->extractCancellationReason($event)
        );

        return $this->mapHandlerResult(
            $result,
            $paymentIntentId,
            'contract_cancelled',
            'Contract already in terminal state'
        );
    }
}
