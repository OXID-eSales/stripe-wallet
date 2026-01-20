<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\AbstractWebhookProcessor;
use OxidEsales\PaymentComponent\Webhook\Exception\WebhookSignatureException;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookRequest;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationService;
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandlerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Stripe webhook processor.
 *
 * Extends AbstractWebhookProcessor to handle Stripe-specific webhook processing:
 * - Signature verification using Stripe SDK
 * - Event routing to contract fulfillment handler
 * - Support for payment_intent, charge, and checkout.session events
 *
 * @since Sprint 5
 */
class StripeWebhookProcessor extends AbstractWebhookProcessor
{
    private ?string $lastContractId = null;

    public function __construct(
        WebhookLogRepositoryInterface $logRepository,
        LoggerInterface $logger,
        private readonly ModuleConfigurationService $config,
        private readonly WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        private readonly ContractRepositoryInterface $contractRepository
    ) {
        parent::__construct($logRepository, $logger);
    }

    protected function getProviderName(): string
    {
        return 'stripe';
    }

    /**
     * @throws WebhookSignatureException
     */
    protected function parseAndValidateRequest(WebhookRequest $request): WebhookEvent
    {
        try {
            $stripeEvent = Webhook::constructEvent(
                $request->payload,
                $request->signature,
                $this->config->getWebhookSecret()
            );

            /** @var array<string, mixed> $eventData */
            $eventData = $stripeEvent->data->toArray();

            return new WebhookEvent(
                id: $stripeEvent->id,
                type: $stripeEvent->type,
                data: $eventData,
                created: $stripeEvent->created
            );
        } catch (SignatureVerificationException $e) {
            throw new WebhookSignatureException($e->getMessage(), $e->getCode(), $e);
        }
    }

    protected function processEvent(WebhookEvent $event): WebhookResult
    {
        $this->lastContractId = null;

        return match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
            'payment_intent.canceled' => $this->handlePaymentIntentCanceled($event),
            'charge.captured' => $this->handleChargeCaptured($event),
            'charge.refunded' => $this->handleChargeRefunded($event),
            'charge.dispute.created' => $this->handleDisputeCreated($event),
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event),
            'checkout.session.expired' => $this->handleCheckoutSessionExpired($event),
            default => WebhookResult::skipped("Unhandled event type: {$event->type}"),
        };
    }

    protected function getContractIdFromResult(WebhookResult $result): ?string
    {
        return $this->lastContractId;
    }

    private function handlePaymentIntentSucceeded(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID');
        }

        $this->logger->info('Processing payment_intent.succeeded', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId);

        if ($result === true) {
            $this->setContractIdFromProviderOrderId($paymentIntentId);
            return WebhookResult::success('contract_fulfilled');
        }

        if ($result === false) {
            return WebhookResult::skipped('Contract already fulfilled or not in COMMITTED state');
        }

        // null = contract not found, try metadata lookup or legacy fallback
        return $this->tryMetadataLookupOrLegacy($event, $paymentIntentId);
    }

    private function handlePaymentIntentFailed(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID');
        }

        $failureReason = $this->extractFailureReason($event);

        $this->logger->warning('Processing payment_intent.payment_failed', [
            'payment_intent_id' => $paymentIntentId,
            'reason' => $failureReason,
        ]);

        $result = $this->fulfillmentHandler->handlePaymentFailed($paymentIntentId, $failureReason);

        if ($result === true) {
            $this->setContractIdFromProviderOrderId($paymentIntentId);
            return WebhookResult::success('contract_failed');
        }

        if ($result === false) {
            return WebhookResult::skipped('Contract already in terminal state');
        }

        return WebhookResult::skipped('Contract not found');
    }

    private function handlePaymentIntentCanceled(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID');
        }

        $cancellationReason = $this->extractCancellationReason($event);

        $this->logger->info('Processing payment_intent.canceled', [
            'payment_intent_id' => $paymentIntentId,
            'reason' => $cancellationReason,
        ]);

        $result = $this->fulfillmentHandler->handlePaymentCanceled($paymentIntentId, $cancellationReason);

        if ($result === true) {
            $this->setContractIdFromProviderOrderId($paymentIntentId);
            return WebhookResult::success('contract_cancelled');
        }

        if ($result === false) {
            return WebhookResult::skipped('Contract already in terminal state');
        }

        return WebhookResult::skipped('Contract not found');
    }

    private function handleChargeCaptured(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->extractPaymentIntentIdFromCharge($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID in charge');
        }

        $amount = $this->extractAmountInCurrencyUnits($event, 'amount');

        $this->logger->info('Processing charge.captured', [
            'payment_intent_id' => $paymentIntentId,
            'amount' => $amount,
        ]);

        $result = $this->fulfillmentHandler->handleChargeCaptured($paymentIntentId, $amount);

        if ($result === true) {
            $this->setContractIdFromProviderOrderId($paymentIntentId);
            return WebhookResult::success('charge_captured');
        }

        if ($result === false) {
            return WebhookResult::skipped('Contract already fulfilled');
        }

        return WebhookResult::skipped('Contract not found');
    }

    private function handleChargeRefunded(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->extractPaymentIntentIdFromCharge($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID in charge');
        }

        $refundedAmount = $this->extractAmountInCurrencyUnits($event, 'amount_refunded');

        $this->logger->info('Processing charge.refunded', [
            'payment_intent_id' => $paymentIntentId,
            'refunded_amount' => $refundedAmount,
        ]);

        $result = $this->fulfillmentHandler->handleChargeRefunded($paymentIntentId, $refundedAmount);

        if ($result === true) {
            $this->setContractIdFromProviderOrderId($paymentIntentId);
            return WebhookResult::success('charge_refunded');
        }

        if ($result === false) {
            return WebhookResult::skipped('Contract not in FULFILLED state');
        }

        return WebhookResult::skipped('Contract not found');
    }

    private function handleDisputeCreated(WebhookEvent $event): WebhookResult
    {
        $object = $event->getObject();

        $this->logger->warning('Dispute created', [
            'dispute_id' => $object['id'] ?? null,
            'amount' => $object['amount'] ?? null,
            'reason' => $object['reason'] ?? null,
            'charge' => $object['charge'] ?? null,
        ]);

        // Disputes don't directly affect contracts - just log for now
        return WebhookResult::success('dispute_logged');
    }

    private function handleCheckoutSessionCompleted(WebhookEvent $event): WebhookResult
    {
        $object = $event->getObject();
        $paymentStatus = $object['payment_status'] ?? '';

        if ($paymentStatus !== 'paid') {
            return WebhookResult::skipped('Checkout session not paid');
        }

        $paymentIntentId = $object['payment_intent'] ?? null;
        if (!is_string($paymentIntentId) || $paymentIntentId === '') {
            return WebhookResult::skipped('No payment intent ID in checkout session');
        }

        $this->logger->info('Processing checkout.session.completed', [
            'session_id' => $object['id'] ?? null,
            'payment_intent_id' => $paymentIntentId,
        ]);

        // Update contract's providerOrderId and attempt fulfillment
        $contractId = $this->extractContractIdFromMetadata($event);
        if ($contractId !== null) {
            $contract = $this->contractRepository->findById($contractId);
            if ($contract !== null) {
                // Update provider order ID from session ID to payment intent ID
                $contract->setProvider('stripe', $paymentIntentId);
                $this->contractRepository->save($contract);
                $this->lastContractId = $contractId;

                // Attempt fulfillment if in correct state
                if ($contract->getState()->isCommitted()) {
                    $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId);
                    if ($result === true) {
                        return WebhookResult::success('contract_fulfilled');
                    }
                }

                return WebhookResult::success('contract_updated');
            }
        }

        return WebhookResult::skipped('Contract not found for checkout session');
    }

    private function handleCheckoutSessionExpired(WebhookEvent $event): WebhookResult
    {
        $contractId = $this->extractContractIdFromMetadata($event);

        if ($contractId === null) {
            $this->logger->debug('No contract ID in expired session metadata');
            return WebhookResult::skipped('No contract ID in session metadata');
        }

        $this->logger->info('Processing checkout.session.expired', [
            'contract_id' => $contractId,
        ]);

        $result = $this->fulfillmentHandler->handleSessionExpired($contractId);

        if ($result === true) {
            $this->lastContractId = $contractId;
            return WebhookResult::success('session_expired');
        }

        if ($result === false) {
            return WebhookResult::skipped('Contract already in terminal state');
        }

        return WebhookResult::skipped('Contract not found');
    }

    /**
     * Extract payment intent ID from event data.
     */
    private function extractPaymentIntentId(WebhookEvent $event): ?string
    {
        $object = $event->getObject();
        $id = $object['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * Extract payment intent ID from charge event data.
     */
    private function extractPaymentIntentIdFromCharge(WebhookEvent $event): ?string
    {
        $object = $event->getObject();
        $paymentIntent = $object['payment_intent'] ?? null;

        return is_string($paymentIntent) ? $paymentIntent : null;
    }

    /**
     * Extract failure reason from payment_intent.payment_failed event.
     */
    private function extractFailureReason(WebhookEvent $event): string
    {
        $object = $event->getObject();
        $lastError = $object['last_payment_error'] ?? null;

        if (is_array($lastError)) {
            $message = $lastError['message'] ?? null;
            if (is_string($message)) {
                return $message;
            }
        }

        return 'Unknown error';
    }

    /**
     * Extract cancellation reason from payment_intent.canceled event.
     */
    private function extractCancellationReason(WebhookEvent $event): string
    {
        $object = $event->getObject();
        $reason = $object['cancellation_reason'] ?? null;

        return is_string($reason) ? $reason : 'user_requested';
    }

    /**
     * Extract amount and convert from cents to currency units.
     */
    private function extractAmountInCurrencyUnits(WebhookEvent $event, string $field): float
    {
        $object = $event->getObject();
        $amount = $object[$field] ?? 0;

        return is_int($amount) ? $amount / 100 : 0.0;
    }

    /**
     * Extract contract_id from event metadata.
     */
    private function extractContractIdFromMetadata(WebhookEvent $event): ?string
    {
        $object = $event->getObject();
        $metadata = $object['metadata'] ?? null;

        if (is_array($metadata)) {
            $contractId = $metadata['contract_id'] ?? null;
            if (is_string($contractId) && $contractId !== '') {
                return $contractId;
            }
        }

        return null;
    }

    /**
     * Set contract ID by looking up from provider order ID.
     */
    private function setContractIdFromProviderOrderId(string $providerOrderId): void
    {
        $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
        if ($contract !== null) {
            $this->lastContractId = $contract->getId();
        }
    }

    /**
     * Try metadata lookup or legacy fallback for orders without contracts.
     */
    private function tryMetadataLookupOrLegacy(WebhookEvent $event, string $paymentIntentId): WebhookResult
    {
        // Try metadata lookup
        $contractId = $this->extractContractIdFromMetadata($event);
        if ($contractId !== null) {
            $contract = $this->contractRepository->findById($contractId);
            if ($contract !== null) {
                // Update provider order ID
                $contract->setProvider('stripe', $paymentIntentId);

                if ($contract->getState()->isFulfilled()) {
                    $this->contractRepository->save($contract);
                    $this->lastContractId = $contractId;
                    return WebhookResult::skipped('Contract already fulfilled');
                }

                if ($contract->getState()->isCommitted()) {
                    $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId);
                    $this->lastContractId = $contractId;
                    return $result === true
                        ? WebhookResult::success('contract_fulfilled')
                        : WebhookResult::skipped('Fulfillment skipped');
                }

                $this->contractRepository->save($contract);
                $this->lastContractId = $contractId;
                return WebhookResult::skipped('Contract not in COMMITTED state');
            }
        }

        // No contract found - this is expected for legacy orders
        $this->logger->debug('No contract found, webhook processed without contract update', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        return WebhookResult::skipped('Contract not found');
    }
}
