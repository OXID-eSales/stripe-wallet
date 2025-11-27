<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\EventHandler;

use OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\Event\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\Event\PaymentCompletedEvent;
use OxidSolutionCatalysts\Payments\Stripe\Component\EventSystem\EventDispatcher;
use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreatePaymentRequest;
use OxidSolutionCatalysts\Payments\Component\Repository\TransactionRepository;
use Psr\Log\LoggerInterface;

/**
 * PaymentInitiatedEventHandler - Handles payment processing
 *
 * This handler subscribes to PaymentInitiatedEvent and:
 * 1. Creates payment via PaymentAdapter (Stripe)
 * 2. Tracks transaction in database
 * 3. Dispatches PaymentCompletedEvent
 *
 * Following the event-driven architecture pattern from the sequence diagram
 */
class PaymentInitiatedEventHandler
{
    public function __construct(
        private readonly PaymentAdapterInterface $paymentAdapter,
        private readonly TransactionRepository $transactionRepository,
        private readonly EventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle PaymentInitiatedEvent
     *
     * @param PaymentInitiatedEvent $event
     * @return void
     */
    public function handle(PaymentInitiatedEvent $event): void
    {
        $this->logger->info('Processing payment initiated event', [
            'contractId' => $event->getContractId(),
            'customerId' => $event->getCustomerId(),
            'amount' => $event->getAmount(),
        ]);

        try {
            // Create payment via Stripe API
            $paymentResponse = $this->createPayment($event);

            // Track transaction in database
            $this->trackTransaction($event, $paymentResponse);

            // Emit PaymentCompletedEvent
            $completedEvent = new PaymentCompletedEvent(
                contractId: $event->getContractId(),
                orderId: $this->generateOrderId($event->getContractId()),
                transactionId: $paymentResponse->getProviderPaymentId(),
                amount: $event->getAmount(),
                currency: $event->getCurrency(),
                status: $this->mapStatus($paymentResponse->getStatus()),
                metadata: [
                    'customerId' => $event->getCustomerId(),
                    'requiresAction' => $paymentResponse->requiresAction(),
                    'clientSecret' => $paymentResponse->getClientSecret(),
                ]
            );

            $this->eventDispatcher->dispatch($completedEvent);

            $this->logger->info('Payment processed successfully', [
                'contractId' => $event->getContractId(),
                'transactionId' => $paymentResponse->getProviderPaymentId(),
                'status' => $paymentResponse->getStatus(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Payment processing failed', [
                'contractId' => $event->getContractId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Emit failed payment event
            $failedEvent = new PaymentCompletedEvent(
                contractId: $event->getContractId(),
                orderId: '',
                transactionId: '',
                amount: $event->getAmount(),
                currency: $event->getCurrency(),
                status: 'failed',
                metadata: [
                    'error' => $e->getMessage(),
                ]
            );

            $this->eventDispatcher->dispatch($failedEvent);
        }
    }

    /**
     * Create payment via PaymentAdapter (Stripe)
     */
    private function createPayment(PaymentInitiatedEvent $event)
    {
        // Get card details from decrypted data
        $cardDetails = $event->getCardDetails();
        $paymentMethod = $event->getPaymentMethod();

        // Build CreatePaymentRequest
        $request = new CreatePaymentRequest(
            amount: $event->getAmount(),
            currency: $event->getCurrency(),
            description: "One-time checkout payment for contract {$event->getContractId()}",
            customerId: $event->getCustomerId(),
            metadata: [
                'contractId' => $event->getContractId(),
                'paymentType' => 'one_time_checkout',
            ]
        );

        // Add payment method or card details
        if ($paymentMethod) {
            $request->setPaymentMethod($paymentMethod);
        } elseif ($cardDetails) {
            $request->setCardDetails(
                number: $cardDetails['number'],
                expMonth: $cardDetails['exp_month'],
                expYear: $cardDetails['exp_year'],
                cvc: $cardDetails['cvc'],
                cardholderName: $cardDetails['name'] ?? null
            );
        }

        // Add return URL for 3D Secure
        if ($event->getReturnUrl()) {
            $request->setReturnUrl($event->getReturnUrl());
        }

        // Save card if requested
        if ($event->shouldSaveCard()) {
            $request->setSavePaymentMethod(true);
        }

        // Create payment via adapter (POST /v1/payment_intents to Stripe)
        return $this->paymentAdapter->createPayment($request);
    }

    /**
     * Track transaction in database
     */
    private function trackTransaction(PaymentInitiatedEvent $event, $paymentResponse): void
    {
        try {
            $this->transactionRepository->create([
                'contract_id' => $event->getContractId(),
                'transaction_id' => $paymentResponse->getProviderPaymentId(),
                'customer_id' => $event->getCustomerId(),
                'amount' => $event->getAmount(),
                'currency' => $event->getCurrency(),
                'status' => $paymentResponse->getStatus(),
                'provider' => $this->paymentAdapter->getProviderName(),
                'created_at' => date('Y-m-d H:i:s'),
                'metadata' => json_encode([
                    'requires_action' => $paymentResponse->requiresAction(),
                    'client_secret' => $paymentResponse->getClientSecret(),
                ]),
            ]);

            $this->logger->info('Transaction tracked successfully', [
                'transactionId' => $paymentResponse->getProviderPaymentId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to track transaction', [
                'error' => $e->getMessage(),
            ]);
            // Don't fail the payment if tracking fails
        }
    }

    /**
     * Map provider status to GraphQL PaymentStatus enum
     */
    private function mapStatus(string $providerStatus): string
    {
        return match (strtolower($providerStatus)) {
            'succeeded', 'success' => 'succeeded',
            'pending', 'processing' => 'pending',
            'requires_action', 'requires_payment_method' => 'requires_action',
            'canceled', 'cancelled' => 'canceled',
            default => 'failed',
        };
    }

    /**
     * Generate order ID from contract ID
     */
    private function generateOrderId(string $contractId): string
    {
        // TODO: Implement based on OXID order creation logic
        return 'order_' . substr($contractId, -12);
    }
}
