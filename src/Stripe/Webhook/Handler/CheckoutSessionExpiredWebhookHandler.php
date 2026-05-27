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
 * Handles checkout.session.expired webhook events.
 *
 * Transitions the contract to EXPIRED state when a Checkout Session expires
 * without payment. The contract ID is extracted from session metadata.
 *
 * @since Sprint 114.4
 */
final class CheckoutSessionExpiredWebhookHandler extends AbstractStripeWebhookHandler
{
    private const EVENT_TYPE = 'checkout.session.expired';

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
        $contractId = $this->parser->extractContractIdFromMetadata($event);

        if ($contractId === null) {
            $this->logger->debug('No contract ID in expired session metadata');
            return StripeWebhookOutcome::of(WebhookResult::skipped('No contract ID in session metadata'));
        }

        $this->logger->info('Processing checkout.session.expired', [
            'contract_id' => $contractId,
        ]);

        $result = $this->fulfillmentHandler->handleSessionExpired($contractId);

        if ($result === true) {
            return StripeWebhookOutcome::of(WebhookResult::success('session_expired'), $contractId);
        }

        if ($result === false) {
            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract already in terminal state'));
        }

        return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not found'));
    }
}
