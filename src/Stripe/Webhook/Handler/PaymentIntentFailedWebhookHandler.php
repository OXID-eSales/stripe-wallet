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
 * Handles payment_intent.payment_failed webhook events.
 *
 * @since Sprint 114.4
 */
final class PaymentIntentFailedWebhookHandler extends AbstractStripeWebhookHandler
{
    private const EVENT_TYPE = 'payment_intent.payment_failed';

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

        $this->logger->warning('Processing payment_intent.payment_failed', [
            'payment_intent_id' => $paymentIntentId,
            'reason' => $this->parser->extractFailureReason($event),
        ]);

        $result = $this->fulfillmentHandler->handlePaymentFailed(
            $paymentIntentId,
            $this->parser->extractFailureReason($event)
        );

        return $this->mapHandlerResult(
            $result,
            $paymentIntentId,
            'contract_failed',
            'Contract already in terminal state'
        );
    }
}
