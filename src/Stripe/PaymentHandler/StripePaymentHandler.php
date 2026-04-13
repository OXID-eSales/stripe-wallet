<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\PaymentHandler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Adapter\PaymentContextInterface;
use OxidEsales\PaymentComponent\Adapter\PaymentHandlerInterface;
use OxidEsales\PaymentComponent\Adapter\PaymentHandlerResult;
use OxidEsales\PaymentComponent\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\PaymentComponent\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Bridges PaymentHandlerInterface to Stripe Checkout Sessions
 * for use with one-page-checkout's redirect flow.
 *
 * Flow:
 * 1. Create contract (DRAFT)
 * 2. Create early order (NOT_FINISHED) — same as standard flow
 * 3. Transition contract: DRAFT → NOT_FINISHED → PENDING
 * 4. Create Stripe Checkout Session
 * 5. Return redirect URL to Stripe hosted page
 * 6. After payment, Stripe redirects to checkoutSuccess (existing handler)
 *
 * @since Sprint 80
 */
class StripePaymentHandler implements PaymentHandlerInterface
{
    private const STRIPE_PAYMENT_PREFIX = 'oe_payments_stripe_';

    public function __construct(
        private readonly ContractServiceInterface $contractService,
        private readonly CheckoutSessionServiceInterface $checkoutSessionService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly ShopOrderServiceInterface $shopOrderService,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly TokenServiceInterface $tokenService,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function getId(): string
    {
        return 'stripe';
    }

    public function getName(): string
    {
        return 'Stripe Payment';
    }

    public function supports(string $paymentMethodId): bool
    {
        return str_starts_with($paymentMethodId, self::STRIPE_PAYMENT_PREFIX);
    }

    public function processPayment(PaymentContextInterface $context): PaymentHandlerResult
    {
        try {
            // 1. Create contract in DRAFT state
            $contract = $this->createContract($context);
            $contractId = $contract->getId() ?? '';

            // 2. Create early order and transition DRAFT → NOT_FINISHED → PENDING
            $this->createEarlyOrderAndTransition($contract, $context);

            // 3. Create Stripe Checkout Session
            $sessionResult = $this->createCheckoutSession($contract);

            if (!$sessionResult->isSuccessful()) {
                return PaymentHandlerResult::error(
                    'Failed to create Stripe checkout session: ' . $sessionResult->getErrorMessage(),
                    'STRIPE_SESSION_FAILED'
                );
            }

            // 4. Store session ID on contract
            $contract->setProvider('stripe', $sessionResult->getSessionId());
            $this->contractRepository->save($contract);

            $this->logger?->info('[StripePaymentHandler] Checkout session created', [
                'contractId' => $contractId,
                'sessionId' => $sessionResult->getSessionId(),
                'state' => $contract->getStateValue(),
            ]);

            return PaymentHandlerResult::success(
                contractId: $contractId,
                clientSecret: null,
                metadata: [
                    'handler' => 'stripe',
                    'requiresRedirect' => true,
                    'redirectUrl' => $sessionResult->getCheckoutUrl(),
                    'sessionId' => $sessionResult->getSessionId(),
                ]
            );
        } catch (\Throwable $e) {
            $this->logger?->error('[StripePaymentHandler] processPayment failed', [
                'error' => $e->getMessage(),
            ]);

            return PaymentHandlerResult::error(
                'Stripe payment processing failed: ' . $e->getMessage(),
                'STRIPE_PAYMENT_FAILED'
            );
        }
    }

    public function confirmPayment(string $transactionId): PaymentHandlerResult
    {
        return PaymentHandlerResult::success(
            contractId: $transactionId,
            metadata: ['note' => 'Stripe confirms via redirect return + webhooks']
        );
    }

    public function getFrontendConfig(): array
    {
        return [
            'type' => 'stripe',
            'publishableKey' => $this->config->getPublishableKey(),
            'requiresRedirect' => true,
            'footerWidget' => 'stripecheckoutfooter',
        ];
    }

    private function createContract(PaymentContextInterface $context): PaymentContractInterface
    {
        $userId = $this->resolveUserId($context->getUser());

        $contract = $this->contractService->createContract(
            $userId,
            $context->getBasket(),
            ['payment_authorized']
        );

        $contract->setMetadata('payment_method_id', $context->getPaymentMethodId());
        $contract->setMetadata('handler', 'stripe');

        return $contract;
    }

    /**
     * Create early order and transition contract through required states.
     *
     * Mirrors the standard flow's EarlyOrderCreationHandler:
     * DRAFT → NOT_FINISHED (with orderId) → PENDING
     */
    private function createEarlyOrderAndTransition(
        PaymentContractInterface $contract,
        PaymentContextInterface $context
    ): void {
        $paymentMethodId = $context->getPaymentMethodId();
        $session = Registry::getSession();
        $sessionId = $session->getId();

        // CRITICAL: Set payment method in basket and session before finalizeOrder
        // OXID validates payment method during order creation (ORDER_STATE_INVALIDPAYMENT = 5)
        $basket = $session->getBasket();
        $basket->setPayment($paymentMethodId);
        $session->setVariable('paymentid', $paymentMethodId);

        $request = new CreateOrderRequest(
            sessionId: $sessionId,
            userId: $contract->getUserId(),
            paymentId: $paymentMethodId,
            paymentTransactionId: null,
            orderRemark: null,
            metadata: ['contract_id' => $contract->getId()],
            initialStatus: 'NOT_FINISHED'
        );

        $orderResponse = $this->shopOrderService->createOrder($request);
        $orderId = $orderResponse->orderId;

        $contract->setMetadata('order_number', (string) $orderResponse->orderNumber);

        // DRAFT → NOT_FINISHED
        $contract->transitionToNotFinished($orderId);
        $this->contractRepository->save($contract);

        // NOT_FINISHED → PENDING
        $contract->transitionToPending();
        $this->contractRepository->save($contract);

        $this->logger?->info('[StripePaymentHandler] Early order created, contract in PENDING', [
            'contractId' => $contract->getId(),
            'orderId' => $orderId,
            'orderNumber' => $orderResponse->orderNumber,
            'state' => $contract->getStateValue(),
        ]);
    }

    private function createCheckoutSession(
        PaymentContractInterface $contract
    ): \OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult {
        $contractId = $contract->getId() ?? '';
        $snapshot = $contract->getBasketSnapshot();
        $shopUrl = $this->shopAdapter->getShopUrl();
        $shopId = $this->shopAdapter->getShopId();
        $captureMode = $this->config->getCaptureMode();
        $sessionId = Registry::getSession()->getId();

        $orderId = $contract->getOrderId();
        $orderNumber = $contract->getMetadata('order_number');

        $contractToken = $this->tokenService->generateToken($contractId);
        $successUrl = $this->checkoutSessionService->buildSuccessUrl(
            $shopUrl,
            $contractId,
            $contractToken,
            $sessionId
        );
        $cancelUrl = $this->checkoutSessionService->buildCancelUrl($shopUrl . 'index.php?cl=payment');

        return $this->checkoutSessionService->createSession(
            contractId: $contractId,
            basketSnapshot: $snapshot,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            shopId: $shopId,
            captureMode: $captureMode,
            orderId: $orderId,
            orderNumber: $orderNumber,
        );
    }

    private function resolveUserId(object $user): string
    {
        if (!method_exists($user, 'getId')) {
            return '';
        }

        $id = $user->getId();

        return is_string($id) ? $id : '';
    }
}
