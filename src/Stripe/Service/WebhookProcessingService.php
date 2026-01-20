<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\UtilsObject;
use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\WebhookReceivedEvent;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentComponent\Service\OrderPaymentStateServiceInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookLog;
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandlerInterface;

/**
 * Webhook processing service
 *
 * This service handles incoming webhook events from Stripe, processes them,
 * and updates order states accordingly. It integrates with the Component EventSystem
 * to enable event-driven architecture.
 *
 * Responsibilities:
 * - Processes webhook events sent by Stripe
 * - Routes events to specific handlers based on event type
 * - Updates order payment states in database
 * - Logs all webhook activity for audit trail
 * - Dispatches Component EventSystem events
 *
 * Handled Stripe Events:
 * - payment_intent.succeeded - Payment confirmed successfully
 * - payment_intent.payment_failed - Payment failed
 * - payment_intent.canceled - Payment canceled
 * - charge.captured - Payment captured (manual capture mode)
 * - charge.refunded - Refund processed
 * - charge.dispute.created - Chargeback/dispute opened
 *
 * Why it's needed:
 * - Stripe requires webhooks for asynchronous payment updates
 * - Orders need to be updated when payment state changes outside checkout flow
 * - Handles edge cases like delayed 3D Secure authentication
 * - Critical for handling refunds and disputes
 * - Enables event-driven architecture via Component EventSystem
 *
 * @package OxidEsales\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 * @SuppressWarnings(PHPMD)
 */
class WebhookProcessingService
{
    private ?EventDispatcherInterface $eventDispatcher;
    private ?WebhookLogRepositoryInterface $webhookLogRepository;
    private ?ContractRepositoryInterface $contractRepository;
    private ?OrderPaymentStateServiceInterface $orderPaymentStateService;
    private ?ContractFulfillmentServiceInterface $contractFulfillmentService;
    private WebhookContractFulfillmentHandlerInterface $contractFulfillmentHandler;

    public function __construct(
        WebhookContractFulfillmentHandlerInterface $contractFulfillmentHandler,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?WebhookLogRepositoryInterface $webhookLogRepository = null,
        ?ContractRepositoryInterface $contractRepository = null,
        ?OrderPaymentStateServiceInterface $orderPaymentStateService = null,
        ?ContractFulfillmentServiceInterface $contractFulfillmentService = null
    ) {
        $this->contractFulfillmentHandler = $contractFulfillmentHandler;
        $this->eventDispatcher = $eventDispatcher;
        $this->webhookLogRepository = $webhookLogRepository;
        $this->contractRepository = $contractRepository;
        $this->orderPaymentStateService = $orderPaymentStateService;
        $this->contractFulfillmentService = $contractFulfillmentService;
    }

    /**
     * Process webhook event
     * Routes event to appropriate handler based on event type
     *
     * @param \Stripe\Event $event Stripe webhook event
     * @return void
     */
    public function processEvent(\Stripe\Event $event): void
    {
        // Check idempotency - skip if already processed
        if ($this->webhookLogRepository !== null && $this->webhookLogRepository->existsByEventId($event->id)) {
            Registry::getLogger()->info('Webhook event already processed (idempotency check)', [
                'event_id' => $event->id,
            ]);
            return;
        }

        // Log webhook event to database
        $this->logWebhookEvent($event);

        // Dispatch WebhookReceivedEvent for listeners
        $this->dispatchWebhookReceivedEvent($event);

        Registry::getLogger()->info('Processing webhook event', [
            'event_id' => $event->id,
            'event_type' => $event->type,
        ]);

        // Route to specific handler based on event type
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($event);
                break;

            case 'payment_intent.payment_failed':
                $this->handlePaymentIntentFailed($event);
                break;

            case 'payment_intent.canceled':
                $this->handlePaymentIntentCanceled($event);
                break;

            case 'charge.captured':
                $this->handleChargeCaptured($event);
                break;

            case 'charge.refunded':
                $this->handleChargeRefunded($event);
                break;

            case 'charge.dispute.created':
                $this->handleDisputeCreated($event);
                break;

            case 'checkout.session.completed':
                $this->handleCheckoutSessionCompleted($event);
                break;

            case 'checkout.session.expired':
                $this->handleCheckoutSessionExpired($event);
                break;

            default:
                Registry::getLogger()->debug('Unhandled webhook event type', [
                    'event_type' => $event->type,
                ]);
        }

        // Look up contract ID for linking webhook log to contract
        $contractId = $this->findContractIdFromEvent($event);

        // Update webhook log status with contract ID if found
        $this->updateWebhookStatus($event->id, 'processed', $contractId);
    }

    /**
     * Extract provider order ID (payment intent ID) from Stripe event.
     *
     * @param \Stripe\Event $event
     * @return string|null Payment intent ID or null if not found
     */
    private function extractProviderOrderIdFromEvent(\Stripe\Event $event): ?string
    {
        $object = $event->data->object;

        // Direct payment intent events
        if (str_starts_with($event->type, 'payment_intent.')) {
            return $object->id ?? null;
        }

        // Charge events - payment intent is a property
        if (str_starts_with($event->type, 'charge.')) {
            return $object->payment_intent ?? null;
        }

        // Checkout session events
        if ($event->type === 'checkout.session.completed') {
            return $object->payment_intent ?? null;
        }

        return null;
    }

    /**
     * Find contract ID from event by looking up via provider order ID or metadata.
     *
     * Sprint 14: Enhanced lookup strategy:
     * 1. Try by PaymentIntent ID (fast path for updated contracts)
     * 2. Try by contract_id from metadata (reliable - set at session creation)
     * 3. Try by Checkout Session ID (for checkout.session.completed events)
     *
     * @param \Stripe\Event $event
     * @return string|null Contract ID or null if not found
     */
    private function findContractIdFromEvent(\Stripe\Event $event): ?string
    {
        if ($this->contractRepository === null) {
            return null;
        }

        // Strategy 1: Try by PaymentIntent ID
        $providerOrderId = $this->extractProviderOrderIdFromEvent($event);
        if ($providerOrderId !== null) {
            try {
                $contract = $this->contractRepository->findByProviderOrderId($providerOrderId);
                if ($contract !== null) {
                    return $contract->getId();
                }
            } catch (\Exception $e) {
                Registry::getLogger()->debug('Could not look up contract by provider order ID', [
                    'provider_order_id' => $providerOrderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Strategy 2: Try by contract_id from metadata
        $contractIdFromMetadata = $this->extractContractIdFromMetadata($event);
        if ($contractIdFromMetadata !== null) {
            try {
                $contract = $this->contractRepository->findById($contractIdFromMetadata);
                if ($contract !== null) {
                    return $contract->getId();
                }
            } catch (\Exception $e) {
                Registry::getLogger()->debug('Could not look up contract by metadata contract_id', [
                    'contract_id' => $contractIdFromMetadata,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Strategy 3: For checkout.session.completed, try by session ID
        if ($event->type === 'checkout.session.completed') {
            $sessionId = $event->data->object->id ?? null;
            if ($sessionId !== null) {
                try {
                    $contract = $this->contractRepository->findByProviderOrderId($sessionId);
                    if ($contract !== null) {
                        return $contract->getId();
                    }
                } catch (\Exception $e) {
                    Registry::getLogger()->debug('Could not look up contract by session ID', [
                        'session_id' => $sessionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * Extract contract_id from event metadata.
     *
     * Contract ID is stored in metadata during Stripe Checkout Session creation.
     * This provides a reliable way to find contracts even before paymentIntentId
     * is stored on the contract.
     *
     * @param \Stripe\Event $event
     * @return string|null Contract ID from metadata or null
     */
    private function extractContractIdFromMetadata(\Stripe\Event $event): ?string
    {
        $object = $event->data->object;

        // Try direct metadata on the object
        // @phpstan-ignore-next-line - Stripe metadata is dynamic
        $metadata = $object->metadata ?? null;
        if ($metadata !== null) {
            // @phpstan-ignore-next-line - Stripe metadata is dynamic
            $contractId = $metadata['contract_id'] ?? null;
            if (is_string($contractId) && $contractId !== '') {
                return $contractId;
            }
        }

        return null;
    }

    /**
     * Update contract's providerOrderId with PaymentIntent ID.
     *
     * Sprint 14: Contracts are initially stored with Checkout Session ID.
     * When checkout.session.completed webhook arrives, we update the contract
     * with the PaymentIntent ID so future webhooks (payment_intent.succeeded,
     * charge.refunded, etc.) can find the contract.
     *
     * Sprint 14 Fix: Also fulfills the contract if it's in COMMITTED state,
     * to avoid database lookup issues when delegating to the fulfillment handler.
     *
     * @param \Stripe\Event $event
     * @param string $paymentIntentId
     * @return bool|null true if fulfilled, false if skipped/not ready, null if not found
     */
    private function updateContractProviderOrderId(\Stripe\Event $event, string $paymentIntentId): ?bool
    {
        if ($this->contractRepository === null) {
            return null;
        }

        // Find contract using enhanced lookup (metadata or session ID)
        $contractId = $this->findContractIdFromEvent($event);
        if ($contractId === null) {
            Registry::getLogger()->debug('No contract found to update providerOrderId', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return null;
        }

        try {
            $contract = $this->contractRepository->findById($contractId);
            if ($contract === null) {
                return null;
            }

            // Update providerOrderId if needed
            $currentProviderOrderId = $contract->getProviderOrderId();
            if ($currentProviderOrderId !== $paymentIntentId) {
                $contract->setProvider('stripe', $paymentIntentId);
                Registry::getLogger()->info('Contract providerOrderId updated with PaymentIntent ID', [
                    'contract_id' => $contractId,
                    'old_provider_order_id' => $currentProviderOrderId,
                    'new_provider_order_id' => $paymentIntentId,
                ]);
            }

            // Idempotency check - already fulfilled
            if ($contract->getState()->isFulfilled()) {
                $this->contractRepository->save($contract);
                return false;
            }

            // Sprint 14: If contract not yet committed, save providerOrderId update and skip fulfillment
            // The order creation flow will handle OXPAID update since webhook might arrive before
            // the user's browser completes the return flow
            if (!$contract->getState()->isCommitted()) {
                Registry::getLogger()->debug('Contract not in COMMITTED state, saving providerOrderId only', [
                    'contract_id' => $contractId,
                    'state' => $contract->getStateValue(),
                ]);
                $this->contractRepository->save($contract);
                return false;
            }

            // Sprint 18: Use ContractFulfillmentService for DRY fulfillment
            if ($this->contractFulfillmentService !== null) {
                $result = $this->contractFulfillmentService->fulfill($contract);
                Registry::getLogger()->info('Contract fulfilled via updateContractProviderOrderId', [
                    'contract_id' => $contractId,
                    'payment_intent_id' => $paymentIntentId,
                    'fulfilled' => $result,
                ]);
                return $result;
            }

            // Legacy fallback - direct fulfillment if service not available
            $contract->fulfill();
            $this->contractRepository->save($contract);

            Registry::getLogger()->info('Contract fulfilled via updateContractProviderOrderId (legacy)', [
                'contract_id' => $contractId,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return true;
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update/fulfill contract', [
                'contract_id' => $contractId,
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Handle payment_intent.succeeded event
     * Payment has been successfully confirmed
     *
     * Sprint 6: Contract-Aware Webhook Processing
     * Sprint 14: Enhanced contract lookup via metadata when regular lookup fails
     * First tries to fulfill via contract, falls back to legacy DB update.
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handlePaymentIntentSucceeded(\Stripe\Event $event): void
    {
        $paymentIntent = $event->data->object;

        // Extract PaymentIntent ID with proper type handling
        /** @var string|null $paymentIntentId */
        $paymentIntentId = $paymentIntent->id ?? null;
        /** @var int|null $amount */
        $amount = $paymentIntent->amount ?? null;
        /** @var string|null $currency */
        $currency = $paymentIntent->currency ?? null;

        Registry::getLogger()->info('Payment intent succeeded', [
            'payment_intent_id' => $paymentIntentId,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        if ($paymentIntentId === null || $paymentIntentId === '') {
            Registry::getLogger()->warning('Payment intent succeeded without valid ID');
            return;
        }

        // Sprint 6: Try contract-aware fulfillment first
        $contractResult = $this->contractFulfillmentHandler->handlePaymentSucceeded($paymentIntentId);

        if ($contractResult === true) {
            Registry::getLogger()->info('Contract fulfilled via webhook', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            // Contract fulfilled - order update handled via ContractFulfilledEvent
            // Still update direct order fields for backward compatibility
            $this->updateOrderFieldsAfterContractFulfillment($paymentIntentId);
            return;
        }

        if ($contractResult === false) {
            Registry::getLogger()->info('Contract already fulfilled (idempotent) or not in COMMITTED state', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            // Already processed or not ready - skip
            return;
        }

        // Sprint 14: Contract not found by payment intent ID, try via metadata
        $contractResult = $this->tryFulfillContractViaMetadata($event, $paymentIntentId);
        if ($contractResult === true) {
            Registry::getLogger()->info('Contract fulfilled via metadata lookup', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            $this->updateOrderFieldsAfterContractFulfillment($paymentIntentId);
            return;
        }

        if ($contractResult === false) {
            // Found but not in right state
            return;
        }

        // Contract not found (null) - use legacy fallback for orders without contracts
        Registry::getLogger()->debug('No contract found, using legacy fallback', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        $this->processLegacyPaymentSucceeded($paymentIntent);
    }

    /**
     * Try to fulfill contract found via metadata.
     *
     * Sprint 14: When contract isn't found by payment intent ID (because it's
     * stored with session ID), try to find it via contract_id in metadata.
     * Directly fulfills the contract in memory to avoid database lookup issues.
     *
     * @param \Stripe\Event $event
     * @param string $paymentIntentId
     * @return bool|null true if fulfilled, false if skipped, null if not found
     */
    private function tryFulfillContractViaMetadata(\Stripe\Event $event, string $paymentIntentId): ?bool
    {
        if ($this->contractRepository === null) {
            return null;
        }

        // Try to find contract via metadata
        $contractId = $this->extractContractIdFromMetadata($event);
        if ($contractId === null) {
            return null;
        }

        try {
            $contract = $this->contractRepository->findById($contractId);
            if ($contract === null) {
                return null;
            }

            // Update contract's providerOrderId for future webhooks
            if ($contract->getProviderOrderId() !== $paymentIntentId) {
                $contract->setProvider('stripe', $paymentIntentId);
            }

            // Idempotency check - already fulfilled
            if ($contract->getState()->isFulfilled()) {
                // Still save provider ID update if needed
                $this->contractRepository->save($contract);
                return false;
            }

            // Validation - must be COMMITTED to fulfill
            if (!$contract->getState()->isCommitted()) {
                Registry::getLogger()->debug('Contract not in COMMITTED state for metadata fulfillment', [
                    'contract_id' => $contractId,
                    'state' => $contract->getStateValue(),
                ]);
                $this->contractRepository->save($contract);
                return false;
            }

            // Sprint 18: Use ContractFulfillmentService for DRY fulfillment
            if ($this->contractFulfillmentService !== null) {
                $result = $this->contractFulfillmentService->fulfill($contract);
                Registry::getLogger()->info('Contract fulfilled via metadata lookup', [
                    'contract_id' => $contractId,
                    'payment_intent_id' => $paymentIntentId,
                    'fulfilled' => $result,
                ]);
                return $result;
            }

            // Legacy fallback - direct fulfillment if service not available
            $contract->fulfill();
            $this->contractRepository->save($contract);

            Registry::getLogger()->info('Contract fulfilled via metadata lookup (legacy)', [
                'contract_id' => $contractId,
                'payment_intent_id' => $paymentIntentId,
            ]);

            return true;
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to fulfill contract via metadata', [
                'contract_id' => $contractId,
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update order fields after contract fulfillment.
     *
     * Updates OXPAID, OXTRANSSTATUS, OXTRANSID for backward compatibility
     * even when contract-aware processing is used.
     *
     * Sprint 16: Uses OrderPaymentStateService for DRY compliance.
     */
    private function updateOrderFieldsAfterContractFulfillment(string $paymentIntentId): void
    {
        $order = $this->findOrderByPaymentIntentId($paymentIntentId);

        if ($order && $this->orderPaymentStateService !== null) {
            $this->orderPaymentStateService->markOrderAsPaid(
                $order->getId(),
                $paymentIntentId
            );
        } elseif ($order) {
            // Legacy fallback if service not available
            $this->updateOrderPaidTimestampLegacy($order->getId());
            $this->updateOrderTransStatusLegacy($order->getId(), 'OK');
            $this->updateOrderTransIdLegacy($order->getId(), $paymentIntentId);
        }
    }

    /**
     * Legacy payment processing for orders created without contracts.
     *
     * Sprint 16: Uses OrderPaymentStateService when available for DRY compliance.
     *
     * @param \Stripe\StripeObject $paymentIntent Stripe PaymentIntent object
     */
    private function processLegacyPaymentSucceeded(\Stripe\StripeObject $paymentIntent): void
    {
        /** @var string|null $paymentIntentId */
        $paymentIntentId = $paymentIntent->id ?? null;

        if ($paymentIntentId === null) {
            Registry::getLogger()->warning('Payment intent has no ID');
            return;
        }

        $order = $this->findOrderByPaymentIntentId($paymentIntentId);

        if ($order) {
            // Sprint 16: Use OrderPaymentStateService if available (DRY)
            if ($this->orderPaymentStateService !== null) {
                $this->orderPaymentStateService->markOrderAsPaid(
                    $order->getId(),
                    $paymentIntentId
                );
            } else {
                // Sprint 8: Legacy fallback - only update oxorder fields
                $this->updateOrderPaidTimestampLegacy($order->getId());
                $this->updateOrderTransStatusLegacy($order->getId(), 'OK');
                $this->updateOrderTransIdLegacy($order->getId(), $paymentIntentId);
            }

            Registry::getLogger()->info('Order payment state updated (legacy path)', [
                'order_id' => $order->getId(),
                'order_number' => $order->getFieldData('oxordernr'),
            ]);
        } else {
            Registry::getLogger()->warning('Order not found for payment intent', [
                'payment_intent_id' => $paymentIntentId,
            ]);
        }
    }

    /**
     * Handle payment_intent.payment_failed event
     * Payment has failed
     *
     * Sprint 6: Contract-Aware Webhook Processing
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handlePaymentIntentFailed(\Stripe\Event $event): void
    {
        $paymentIntent = $event->data->object;
        /** @var string $paymentIntentId */
        $paymentIntentId = $paymentIntent->id ?? '';
        // @phpstan-ignore-next-line - Stripe object properties are dynamic
        $lastPaymentError = $paymentIntent->last_payment_error ?? null;
        /** @var string $failureReason */
        $failureReason = is_object($lastPaymentError) && isset($lastPaymentError->message)
            ? (string) $lastPaymentError->message
            : 'Unknown error';

        Registry::getLogger()->warning('Payment intent failed', [
            'payment_intent_id' => $paymentIntentId,
            'error' => $failureReason,
        ]);

        if ($paymentIntentId === '') {
            return;
        }

        // Sprint 6: Try contract-aware failure handling first
        $contractResult = $this->contractFulfillmentHandler->handlePaymentFailed(
            $paymentIntentId,
            $failureReason
        );

        if ($contractResult !== null) {
            Registry::getLogger()->info('Contract failure handled via webhook', [
                'payment_intent_id' => $paymentIntentId,
                'result' => $contractResult ? 'failed' : 'skipped',
            ]);
        }

        // Sprint 8: order_state table removed - no legacy update needed
        // Contract failure is tracked via contract.OXSTATE = 'failed'
    }

    /**
     * Handle payment_intent.canceled event
     * Payment has been canceled
     *
     * Sprint 1 Bug Fix: Previously only logged - now updates contract state.
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handlePaymentIntentCanceled(\Stripe\Event $event): void
    {
        $paymentIntent = $event->data->object;
        /** @var string $paymentIntentId */
        $paymentIntentId = $paymentIntent->id ?? '';
        // @phpstan-ignore-next-line - Stripe object properties are dynamic
        $cancellationReason = $paymentIntent->cancellation_reason ?? 'user_requested';

        Registry::getLogger()->info('Payment intent canceled', [
            'payment_intent_id' => $paymentIntentId,
            'cancellation_reason' => $cancellationReason,
        ]);

        if ($paymentIntentId === '') {
            return;
        }

        // Sprint 1: Contract-aware cancellation handling
        $contractResult = $this->contractFulfillmentHandler->handlePaymentCanceled(
            $paymentIntentId,
            (string) $cancellationReason
        );

        if ($contractResult !== null) {
            Registry::getLogger()->info('Contract cancellation handled via webhook', [
                'payment_intent_id' => $paymentIntentId,
                'result' => $contractResult ? 'cancelled' : 'skipped',
            ]);
        }

        // Sprint 8: order_state table removed - no legacy update needed
        // Cancellation is tracked via contract.OXSTATE = 'cancelled'
    }

    /**
     * Handle charge.captured event
     * Payment has been captured (for manual capture mode)
     *
     * Sprint 6: Contract-Aware Webhook Processing
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleChargeCaptured(\Stripe\Event $event): void
    {
        $charge = $event->data->object;
        /** @var string|null $paymentIntentId */
        $paymentIntentId = $charge->payment_intent ?? null;
        /** @var int $amount */
        $amount = $charge->amount ?? 0;

        Registry::getLogger()->info('Charge captured', [
            'charge_id' => $charge->id ?? '',
            'amount' => $amount,
            'payment_intent' => $paymentIntentId,
        ]);

        if ($paymentIntentId === null || $paymentIntentId === '') {
            Registry::getLogger()->warning('No payment intent ID in charge.captured event');
            return;
        }

        // Sprint 8: Pass captured amount to handler for contract tracking
        $capturedAmount = $amount / 100;

        // Sprint 6: Try contract-aware capture handling first
        $contractResult = $this->contractFulfillmentHandler->handleChargeCaptured($paymentIntentId, $capturedAmount);

        if ($contractResult === true) {
            Registry::getLogger()->info('Contract fulfilled via charge.captured webhook', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            $this->updateOrderFieldsAfterContractFulfillment($paymentIntentId);
            return;
        }

        if ($contractResult === false) {
            Registry::getLogger()->info('Contract already fulfilled (idempotent) for charge.captured', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return;
        }

        // Sprint 8: Legacy fallback - only update oxorder fields, no order_state
        $order = $this->findOrderByPaymentIntentId($paymentIntentId);

        if ($order) {
            // Sprint 16: Use OrderPaymentStateService if available (DRY)
            if ($this->orderPaymentStateService !== null) {
                $this->orderPaymentStateService->markOrderAsPaid(
                    $order->getId(),
                    $paymentIntentId
                );
            } else {
                $this->updateOrderPaidTimestampLegacy($order->getId());
                $this->updateOrderTransStatusLegacy($order->getId(), 'OK');
            }

            Registry::getLogger()->info('Payment captured for order (legacy path)', [
                'order_id' => $order->getId(),
                'captured_amount' => $capturedAmount,
            ]);
        }
    }

    /**
     * Handle charge.refunded event
     * Payment has been refunded
     *
     * Sprint 6: Contract-Aware Webhook Processing
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleChargeRefunded(\Stripe\Event $event): void
    {
        $charge = $event->data->object;
        /** @var string|null $paymentIntentId */
        $paymentIntentId = $charge->payment_intent ?? null;
        /** @var int $amountRefunded */
        $amountRefunded = $charge->amount_refunded ?? 0;
        $refundedAmount = $amountRefunded / 100;

        Registry::getLogger()->info('Charge refunded', [
            'charge_id' => $charge->id ?? '',
            'amount_refunded' => $refundedAmount,
            'payment_intent' => $paymentIntentId,
        ]);

        if ($paymentIntentId === null || $paymentIntentId === '') {
            Registry::getLogger()->warning('No payment intent ID in charge.refunded event');
            return;
        }

        // Sprint 6: Try contract-aware refund handling first
        $contractResult = $this->contractFulfillmentHandler->handleChargeRefunded(
            $paymentIntentId,
            $refundedAmount
        );

        if ($contractResult !== null) {
            Registry::getLogger()->info('Contract refund processed via webhook', [
                'payment_intent_id' => $paymentIntentId,
                'result' => $contractResult ? 'processed' : 'skipped',
            ]);
        }

        // Sprint 8: Refund tracking now handled by contract.OXREFUNDEDAMOUNT
        // No legacy order_state update needed
    }

    /**
     * Handle charge.dispute.created event
     * A dispute (chargeback) has been created
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleDisputeCreated(\Stripe\Event $event): void
    {
        $dispute = $event->data->object;

        // @phpstan-ignore-next-line - Stripe Dispute properties are dynamic
        Registry::getLogger()->warning('Dispute created', [
            'dispute_id' => $dispute->id ?? null,
            'amount' => $dispute->amount ?? null,
            'reason' => $dispute->reason ?? null,
            'charge' => $dispute->charge ?? null,
        ]);

        // This would typically trigger an email notification to admin
        // and potentially update order status
    }

    /**
     * Handle checkout.session.completed event
     * Checkout session has been completed (used by Stripe Wallet)
     *
     * Sprint 6: Contract-Aware Webhook Processing
     * Sprint 14: Update contract's providerOrderId with PaymentIntent ID for future webhooks
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleCheckoutSessionCompleted(\Stripe\Event $event): void
    {
        $session = $event->data->object;

        Registry::getLogger()->info('Checkout session completed', [
            'session_id' => $session->id,
            'payment_intent' => $session->payment_intent ?? null,
            'payment_status' => $session->payment_status ?? null,
        ]);

        // Only process if payment was successful
        $paymentStatus = $session->payment_status ?? '';
        if ($paymentStatus !== 'paid') {
            Registry::getLogger()->debug('Checkout session not paid, skipping', [
                'payment_status' => $paymentStatus,
            ]);
            return;
        }

        // Find order by payment intent ID
        /** @var string|null $paymentIntentId */
        $paymentIntentId = $session->payment_intent ?? null;
        if ($paymentIntentId === null || $paymentIntentId === '') {
            Registry::getLogger()->warning('No payment intent ID in checkout session', [
                'session_id' => $session->id,
            ]);
            return;
        }

        // Sprint 14: Update contract's providerOrderId and fulfill if ready
        // This method now directly fulfills the contract to avoid database lookup issues
        $contractResult = $this->updateContractProviderOrderId($event, $paymentIntentId);

        if ($contractResult === true) {
            Registry::getLogger()->info('Contract fulfilled via checkout.session.completed webhook', [
                'payment_intent_id' => $paymentIntentId,
                'session_id' => $session->id,
            ]);
            $this->updateOrderFieldsAfterContractFulfillment($paymentIntentId);
            return;
        }

        if ($contractResult === false) {
            Registry::getLogger()->info('Contract already fulfilled (idempotent) for checkout session', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return;
        }

        // Sprint 8: Legacy fallback - only update oxorder fields, no order_state
        $order = $this->findOrderByPaymentIntentId($paymentIntentId);

        if ($order) {
            // Sprint 16: Use OrderPaymentStateService if available (DRY)
            if ($this->orderPaymentStateService !== null) {
                $this->orderPaymentStateService->markOrderAsPaid(
                    $order->getId(),
                    $paymentIntentId
                );
            } else {
                $this->updateOrderPaidTimestampLegacy($order->getId());
                $this->updateOrderTransStatusLegacy($order->getId(), 'OK');
                $this->updateOrderTransIdLegacy($order->getId(), $paymentIntentId);
            }

            Registry::getLogger()->info('Order updated from checkout session (legacy path)', [
                'order_id' => $order->getId(),
                'order_number' => $order->getFieldData('oxordernr'),
            ]);
        } else {
            Registry::getLogger()->warning('Order not found for checkout session payment intent', [
                'payment_intent_id' => $paymentIntentId,
                'session_id' => $session->id,
            ]);
        }
    }

    /**
     * Handle checkout.session.expired event
     * Checkout session has expired without completing payment
     *
     * Sprint 1 Bug Fix: Expired sessions were not updating contract state.
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function handleCheckoutSessionExpired(\Stripe\Event $event): void
    {
        $session = $event->data->object;

        Registry::getLogger()->info('Checkout session expired', [
            'session_id' => $session->id,
        ]);

        // Find contract by session metadata or lookup
        $contractId = $this->extractContractIdFromMetadata($event);
        if ($contractId === null) {
            Registry::getLogger()->debug('No contract ID in expired session metadata', [
                'session_id' => $session->id,
            ]);
            return;
        }

        // Sprint 1: Contract-aware expiration handling
        $contractResult = $this->contractFulfillmentHandler->handleSessionExpired($contractId);

        if ($contractResult !== null) {
            Registry::getLogger()->info('Contract expiration handled via webhook', [
                'contract_id' => $contractId,
                'result' => $contractResult ? 'expired' : 'skipped',
            ]);
        }
    }

    /**
     * Find order by Stripe PaymentIntent ID
     *
     * Searches for order in two places:
     * 1. oe_payments_transaction.OXPROVIDERORDERID (preferred - Component transaction records)
     * 2. oxorder.OXTRANSID (fallback - direct OXID order field)
     *
     * @param string $paymentIntentId
     * @return Order|null
     */
    private function findOrderByPaymentIntentId(string $paymentIntentId): ?Order
    {
        $db = DatabaseProvider::getDb();

        // First try: Look in oe_payments_transaction table
        $orderId = $db->getOne(
            "SELECT OXORDERID FROM oe_payments_transaction WHERE OXPROVIDERORDERID = ? LIMIT 1",
            [$paymentIntentId]
        );

        // Fallback: Look directly in oxorder.OXTRANSID
        if (!$orderId) {
            $orderId = $db->getOne(
                "SELECT OXID FROM oxorder WHERE OXTRANSID = ? LIMIT 1",
                [$paymentIntentId]
            );
        }

        if (!$orderId) {
            Registry::getLogger()->debug('Order not found for PaymentIntent ID', [
                'payment_intent_id' => $paymentIntentId,
            ]);
            return null;
        }

        $order = oxNew(Order::class);
        if (!$order->load($orderId)) {
            Registry::getLogger()->error('Failed to load order', [
                'order_id' => $orderId,
                'payment_intent_id' => $paymentIntentId,
            ]);
            return null;
        }

        return $order;
    }

    // Sprint 8: Removed updateOrderPaymentState(), updateOrderCaptureState(), updateOrderRefundState()
    // These methods updated the now-dropped oe_payments_order_state table.
    // Capture/refund tracking is now handled by oe_payments_contract fields.

    /**
     * Update order OXPAID timestamp (legacy fallback).
     *
     * Sets the OXPAID field in oxorder table to current timestamp.
     * This field stores "Time when order was paid".
     *
     * @deprecated Use OrderPaymentStateService::updatePaidTimestamp() instead
     * @param string $orderId
     * @return void
     */
    private function updateOrderPaidTimestampLegacy(string $orderId): void
    {
        try {
            $db = DatabaseProvider::getDb();

            $sql = "UPDATE oxorder SET OXPAID = NOW() WHERE OXID = ?";
            $db->execute($sql, [$orderId]);

            Registry::getLogger()->debug('OXPAID timestamp updated (legacy)', [
                'order_id' => $orderId,
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update OXPAID (legacy)', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update order OXTRANSSTATUS (legacy fallback).
     *
     * Sets the OXTRANSSTATUS field in oxorder table.
     * Valid values: NOT_FINISHED, OK, ERROR
     *
     * @deprecated Use OrderPaymentStateService::updateTransactionStatus() instead
     * @param string $orderId
     * @param string $status
     * @return void
     */
    private function updateOrderTransStatusLegacy(string $orderId, string $status): void
    {
        try {
            $db = DatabaseProvider::getDb();

            $sql = "UPDATE oxorder SET OXTRANSSTATUS = ? WHERE OXID = ?";
            $db->execute($sql, [$status, $orderId]);

            Registry::getLogger()->debug('OXTRANSSTATUS updated (legacy)', [
                'order_id' => $orderId,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update OXTRANSSTATUS (legacy)', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update order OXTRANSID (legacy fallback).
     *
     * Sets the OXTRANSID field in oxorder table to the PaymentIntent ID.
     *
     * @deprecated Use OrderPaymentStateService::updateTransactionId() instead
     * @param string $orderId
     * @param string $transactionId
     * @return void
     */
    private function updateOrderTransIdLegacy(string $orderId, string $transactionId): void
    {
        try {
            $db = DatabaseProvider::getDb();

            // Only update if OXTRANSID is currently empty
            $sql = "UPDATE oxorder SET OXTRANSID = ? WHERE OXID = ? AND (OXTRANSID IS NULL OR OXTRANSID = '')";
            $db->execute($sql, [$transactionId, $orderId]);

            Registry::getLogger()->debug('OXTRANSID updated (legacy)', [
                'order_id' => $orderId,
                'transaction_id' => $transactionId,
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update OXTRANSID (legacy)', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log webhook event to database
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function logWebhookEvent(\Stripe\Event $event): void
    {
        // Use repository if available (preferred - LSP compliant)
        if ($this->webhookLogRepository !== null) {
            try {
                $log = new WebhookLog(
                    $event->id,
                    new DateTimeImmutable(),
                    'received'
                );
                $log->setEventType($event->type);
                $log->setProvider('stripe');
                /** @var array<string, mixed> $payload */
                $payload = $event->data->object->toArray();
                $log->setPayload($payload);

                $this->webhookLogRepository->save($log);
            } catch (\Exception $e) {
                Registry::getLogger()->error('Failed to log webhook via repository', [
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // Fallback to raw SQL (legacy - for backward compatibility)
        try {
            $db = DatabaseProvider::getDb();

            $sql = "INSERT INTO oe_payments_webhooklogs
                    (OXID, OXEVENTID, OXEVENTTYPE, OXPROVIDER, OXPAYLOAD, OXSTATUS, OXRECEIVEDAT)
                    VALUES (?, ?, ?, 'stripe', ?, 'received', NOW())
                    ON DUPLICATE KEY UPDATE
                    OXSTATUS = 'duplicate'";

            $db->execute($sql, [
                UtilsObject::getInstance()->generateUId(),
                $event->id,
                $event->type,
                json_encode($event->data->object->toArray()),
            ]);
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to log webhook', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update webhook processing status
     *
     * Sprint 7 Phase 4: Uses WebhookLogRepository for proper layering.
     * Falls back to direct SQL if repository not available.
     *
     * @param string $eventId
     * @param string $status
     * @param string|null $contractId Optional contract ID to link the webhook to
     * @return void
     */
    private function updateWebhookStatus(string $eventId, string $status, ?string $contractId = null): void
    {
        // Sprint 7 Phase 4: Use repository if available
        if ($this->webhookLogRepository !== null) {
            try {
                $this->webhookLogRepository->updateStatus($eventId, $status, null, $contractId);
                return;
            } catch (\Exception $e) {
                Registry::getLogger()->error('Failed to update webhook status via repository', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to direct SQL (legacy)
        try {
            $db = DatabaseProvider::getDb();

            if ($contractId !== null) {
                $sql = "UPDATE oe_payments_webhooklogs
                        SET OXSTATUS = ?, OXPROCESSEDAT = NOW(), OXCONTRACTID = ?
                        WHERE OXEVENTID = ?";
                $db->execute($sql, [$status, $contractId, $eventId]);
            } else {
                $sql = "UPDATE oe_payments_webhooklogs
                        SET OXSTATUS = ?, OXPROCESSEDAT = NOW()
                        WHERE OXEVENTID = ?";
                $db->execute($sql, [$status, $eventId]);
            }
        } catch (\Exception $e) {
            Registry::getLogger()->error('Failed to update webhook status', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch WebhookReceivedEvent
     *
     * @param \Stripe\Event $event
     * @return void
     */
    private function dispatchWebhookReceivedEvent(\Stripe\Event $event): void
    {
        if (!$this->eventDispatcher) {
            return;
        }

        $context = new EventContext([
            'eventId' => $event->id,
            'eventType' => $event->type,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = $event->data->object->toArray();
        $webhookEvent = new WebhookReceivedEvent(
            context: $context,
            provider: 'stripe',
            eventType: $event->type,
            payload: $payload,
            signature: '' // Signature already verified by WebhookController
        );

        $this->eventDispatcher->dispatch($webhookEvent);
    }
}
