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
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use Psr\Log\LoggerInterface;

/**
 * Handles checkout.session.completed webhook events.
 *
 * Updates the contract's providerOrderId from session ID to payment intent ID,
 * then attempts fulfillment if the contract is already committed.
 *
 * @since Sprint 114.4
 */
class CheckoutSessionCompletedWebhookHandler extends AbstractStripeWebhookHandler
{
    private const EVENT_TYPE = 'checkout.session.completed';

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
        $paymentStatus = $object['payment_status'] ?? '';

        if ($paymentStatus !== StripeStatusMapper::CHECKOUT_PAYMENT_STATUS_PAID) {
            return StripeWebhookOutcome::of(WebhookResult::skipped('Checkout session not paid'));
        }

        $paymentIntentId = $object['payment_intent'] ?? null;
        if (!is_string($paymentIntentId) || $paymentIntentId === '') {
            return StripeWebhookOutcome::of(WebhookResult::skipped('No payment intent ID in checkout session'));
        }

        $this->logger->info('Processing checkout.session.completed', [
            'session_id' => $object['id'] ?? null,
            'payment_intent_id' => $paymentIntentId,
        ]);

        $contractId = $this->parser->extractContractIdFromMetadata($event);
        if ($contractId === null) {
            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not found for checkout session'));
        }

        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return StripeWebhookOutcome::of(WebhookResult::skipped('Contract not found for checkout session'));
        }

        // Update provider order ID from session ID to payment intent ID
        $contract->setProvider(StripeDefinitions::PROVIDER, $paymentIntentId);
        $this->contractRepository->save($contract);

        // Attempt fulfillment if in correct state
        if ($contract->getState()->isCommitted()) {
            $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId);
            if ($result === true) {
                return StripeWebhookOutcome::of(WebhookResult::success('contract_fulfilled'), $contractId);
            }
        }

        return StripeWebhookOutcome::of(WebhookResult::success('contract_updated'), $contractId);
    }
}
