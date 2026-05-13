<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Webhook;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\AbstractWebhookProcessor;
use OxidEsales\PaymentBase\Webhook\Exception\WebhookSignatureException;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
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
 * Inherits from AbstractWebhookProcessor (payment-base) which contributes ~10 ECC.
 * Each webhook event type requires a dedicated handler method. Data extraction delegated to StripeWebhookEventParser.
 *
 * @since Sprint 5
 */
class StripeWebhookProcessor extends AbstractWebhookProcessor
{
    private ?string $lastContractId = null;

    private readonly StripeWebhookEventParser $parser;

    public function __construct(
        WebhookLogRepositoryInterface $logRepository,
        LoggerInterface $logger,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly WebhookContractFulfillmentHandlerInterface $fulfillmentHandler,
        private readonly ContractRepositoryInterface $contractRepository,
        ?StripeWebhookEventParser $parser = null
    ) {
        parent::__construct($logRepository, $logger);
        $this->parser = $parser ?? new StripeWebhookEventParser();
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
        $paymentIntentId = $this->parser->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID');
        }

        // STRP-AUTOCAP-REFUND: extract amount_received so the fulfillment handler
        // can persist OXCAPTUREDAMOUNT. Required for opalreturns refunds on
        // auto-captured orders (Stripe sends payment_intent.succeeded only,
        // not a separate charge.captured event, so this is our one chance).
        $capturedAmount = $this->parser->extractAmountInCurrencyUnits($event, 'amount_received');

        $this->logger->info('Processing payment_intent.succeeded', [
            'payment_intent_id' => $paymentIntentId,
            'amount_received'   => $capturedAmount,
        ]);

        $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId, $capturedAmount);

        if ($result === null) {
            return $this->tryMetadataLookupOrLegacy($event, $paymentIntentId, $capturedAmount);
        }

        return $this->mapHandlerResult($result, $paymentIntentId, 'contract_fulfilled', 'Contract already fulfilled or not in COMMITTED state');
    }

    private function handlePaymentIntentFailed(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->parser->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID');
        }

        $this->logger->warning('Processing payment_intent.payment_failed', [
            'payment_intent_id' => $paymentIntentId,
            'reason' => $this->parser->extractFailureReason($event),
        ]);

        $result = $this->fulfillmentHandler->handlePaymentFailed($paymentIntentId, $this->parser->extractFailureReason($event));

        return $this->mapHandlerResult($result, $paymentIntentId, 'contract_failed', 'Contract already in terminal state');
    }

    private function handlePaymentIntentCanceled(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->parser->extractPaymentIntentId($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID');
        }

        $this->logger->info('Processing payment_intent.canceled', [
            'payment_intent_id' => $paymentIntentId,
            'reason' => $this->parser->extractCancellationReason($event),
        ]);

        $result = $this->fulfillmentHandler->handlePaymentCanceled($paymentIntentId, $this->parser->extractCancellationReason($event));

        return $this->mapHandlerResult($result, $paymentIntentId, 'contract_cancelled', 'Contract already in terminal state');
    }

    private function handleChargeCaptured(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->parser->extractPaymentIntentIdFromCharge($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID in charge');
        }

        $amount = $this->parser->extractAmountInCurrencyUnits($event, 'amount');
        $this->logger->info('Processing charge.captured', ['payment_intent_id' => $paymentIntentId, 'amount' => $amount]);

        $result = $this->fulfillmentHandler->handleChargeCaptured($paymentIntentId, $amount);

        return $this->mapHandlerResult($result, $paymentIntentId, 'charge_captured', 'Contract already fulfilled');
    }

    private function handleChargeRefunded(WebhookEvent $event): WebhookResult
    {
        $paymentIntentId = $this->parser->extractPaymentIntentIdFromCharge($event);
        if ($paymentIntentId === null) {
            return WebhookResult::failure('invalid_event', 'Missing payment intent ID in charge');
        }

        $refundedAmount = $this->parser->extractAmountInCurrencyUnits($event, 'amount_refunded');
        $this->logger->info('Processing charge.refunded', ['payment_intent_id' => $paymentIntentId, 'refunded_amount' => $refundedAmount]);

        $result = $this->fulfillmentHandler->handleChargeRefunded($paymentIntentId, $refundedAmount);

        return $this->mapHandlerResult($result, $paymentIntentId, 'charge_refunded', 'Contract not in FULFILLED state');
    }

    /**
     * Map tri-state handler result (true/false/null) to WebhookResult.
     */
    private function mapHandlerResult(?bool $result, string $paymentIntentId, string $successAction, string $skipReason): WebhookResult
    {
        if ($result === true) {
            $this->setContractIdFromProviderOrderId($paymentIntentId);
            return WebhookResult::success($successAction);
        }

        return $result === false
            ? WebhookResult::skipped($skipReason)
            : WebhookResult::skipped('Contract not found');
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
        $contractId = $this->parser->extractContractIdFromMetadata($event);
        if ($contractId !== null) {
            $contract = $this->contractRepository->findById($contractId);
            if ($contract !== null) {
                // Update provider order ID from session ID to payment intent ID
                $contract->setProvider('stripe', $paymentIntentId);
                $this->contractRepository->save($contract);
                $this->lastContractId = $contractId;

                // Attempt fulfillment if in correct state
                if ($contract->getState()->isCommitted()) {
                    // STRP-AUTOCAP-REFUND: propagate amount_received so OXCAPTUREDAMOUNT
                    // is persisted on the contract for downstream refund dispatch.
                    $sessionAmount = $this->parser->extractAmountInCurrencyUnits($event, 'amount_total');
                    $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId, $sessionAmount);
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
        $contractId = $this->parser->extractContractIdFromMetadata($event);

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
    private function tryMetadataLookupOrLegacy(WebhookEvent $event, string $paymentIntentId, float $capturedAmount = 0.0): WebhookResult
    {
        $contractId = $this->parser->extractContractIdFromMetadata($event);
        $contract = $contractId !== null ? $this->contractRepository->findById($contractId) : null;

        if ($contract === null) {
            $this->logger->debug('No contract found, webhook processed without contract update', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return WebhookResult::skipped('Contract not found');
        }

        $contract->setProvider('stripe', $paymentIntentId);
        $this->lastContractId = $contractId;

        if ($contract->getState()->isFulfilled()) {
            $this->contractRepository->save($contract);
            return WebhookResult::skipped('Contract already fulfilled');
        }

        if ($contract->getState()->isCommitted()) {
            $result = $this->fulfillmentHandler->handlePaymentSucceeded($paymentIntentId, $capturedAmount);
            return $result === true
                ? WebhookResult::success('contract_fulfilled')
                : WebhookResult::skipped('Fulfillment skipped');
        }

        $this->contractRepository->save($contract);
        return WebhookResult::skipped('Contract not in COMMITTED state');
    }
}
