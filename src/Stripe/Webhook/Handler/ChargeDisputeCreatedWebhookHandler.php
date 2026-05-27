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
 * Handles charge.dispute.created webhook events.
 *
 * Disputes don't directly affect contract state — this handler logs the dispute
 * details for operator awareness and returns success so Stripe does not retry.
 *
 * @since Sprint 114.4
 */
final class ChargeDisputeCreatedWebhookHandler extends AbstractStripeWebhookHandler
{
    private const EVENT_TYPE = 'charge.dispute.created';

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
        $object = $event->getObject();

        $this->logger->warning('Dispute created', [
            'dispute_id' => $object['id'] ?? null,
            'amount' => $object['amount'] ?? null,
            'reason' => $object['reason'] ?? null,
            'charge' => $object['charge'] ?? null,
        ]);

        // Disputes don't directly affect contracts - just log for now
        return StripeWebhookOutcome::of(WebhookResult::success('dispute_logged'));
    }
}
