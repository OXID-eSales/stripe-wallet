<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\PaymentHandler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerResult;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\OxidLanguageResolver;
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
    private readonly LanguageResolverInterface $languageResolver;

    public function __construct(
        private readonly ContractServiceInterface $contractService,
        private readonly CheckoutSessionServiceInterface $checkoutSessionService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly ShopOrderServiceInterface $shopOrderService,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly TokenServiceInterface $tokenService,
        private readonly ?LoggerInterface $logger = null,
        ?LanguageResolverInterface $languageResolver = null,
        private readonly ?IframeCheckoutSettingsInterface $iframeSettings = null,
        private readonly ?StripeAdapterFactoryInterface $adapterFactory = null
    ) {
        $this->languageResolver = $languageResolver ?? new OxidLanguageResolver();
    }

    public function getId(): string
    {
        return StripeDefinitions::PROVIDER;
    }

    public function getName(): string
    {
        return 'Stripe Payment';
    }

    public function supports(string $paymentMethodId): bool
    {
        return StripeDefinitions::isStripePaymentMethod($paymentMethodId);
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
            $contract->setProvider(StripeDefinitions::PROVIDER, $sessionResult->getSessionId() ?? '');
            $this->contractRepository->save($contract);

            $this->logger?->info('[StripePaymentHandler] Checkout session created', [
                'contractId' => $contractId,
                'sessionId' => $sessionResult->getSessionId(),
                'state' => $contract->getStateValue(),
            ]);

            $embedded = $sessionResult->isEmbedded();

            return PaymentHandlerResult::success(
                contractId: $contractId,
                clientSecret: $embedded ? $sessionResult->getClientSecret() : null,
                metadata: [
                    'handler' => StripeDefinitions::PROVIDER,
                    'renderMode' => $embedded ? 'iframe' : 'redirect',
                    'requiresRedirect' => !$embedded,
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

    /**
     * Confirm a payment by asking Stripe what state the PaymentIntent is in.
     *
     * Sprint 133 · Story 6 (F6): this returned success() unconditionally, with
     * no provider call, while PaymentHandlerInterface documents it as "Confirm
     * payment with provider / Result with confirmation status" — so any caller
     * written to the interface received a confirmation that was structurally
     * indistinguishable from a real one. Nothing called it yet, which is the
     * only reason it was not already an incident.
     *
     * Reuses the normalized status mapping that StripePaymentCaptureStatusQuery
     * already owns rather than deriving a second one.
     */
    public function confirmPayment(string $transactionId): PaymentHandlerResult
    {
        if ($this->adapterFactory === null) {
            return PaymentHandlerResult::error(
                'Stripe confirmation unavailable: no payment adapter configured',
                'STRIPE_CONFIRM_UNAVAILABLE'
            );
        }

        try {
            $details = $this->adapterFactory->getStripeAdapter()->getPaymentDetails($transactionId);
        } catch (\Throwable $e) {
            $this->logger?->error('[StripePaymentHandler] confirmPayment failed', [
                'transactionId' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return PaymentHandlerResult::error(
                'Stripe confirmation failed: ' . $e->getMessage(),
                'STRIPE_CONFIRM_FAILED'
            );
        }

        $confirmed = in_array(
            $details->status,
            [StripeStatusMapper::STATUS_CAPTURED, StripeStatusMapper::STATUS_AUTHORIZED],
            true
        );

        if (!$confirmed) {
            return PaymentHandlerResult::error(
                sprintf('Stripe payment not confirmed (status: %s)', $details->status),
                'STRIPE_NOT_CONFIRMED'
            );
        }

        return PaymentHandlerResult::success(
            contractId: $transactionId,
            metadata: [
                'handler' => StripeDefinitions::PROVIDER,
                'providerStatus' => $details->status,
                'captured' => $details->status === StripeStatusMapper::STATUS_CAPTURED,
            ]
        );
    }

    public function getFrontendConfig(): array
    {
        $embedded = $this->isIframeMode();

        return [
            'type' => StripeDefinitions::PROVIDER,
            'publishableKey' => $this->config->getPublishableKey(),
            'renderMode' => $embedded ? 'iframe' : 'redirect',
            'requiresRedirect' => !$embedded,
            'footerWidget' => 'stripecheckoutfooter',
        ];
    }

    /**
     * True when the merchant enabled inline iframe checkout (payment-base flag).
     */
    private function isIframeMode(): bool
    {
        return $this->iframeSettings?->isEnabled() ?? false;
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
        $contract->setMetadata('handler', StripeDefinitions::PROVIDER);

        return $contract;
    }

    /**
     * Create early order and transition contract through required states.
     *
     * Mirrors the standard flow's EarlyOrderCreationHandler:
     * DRAFT → NOT_FINISHED (with orderId) → PENDING
     */
    protected function createEarlyOrderAndTransition(
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

        // OPC parity with StripeOrderController::createCheckoutSession: the OPC
        // address is entered + saved via AJAX, so no sDeliveryAddressMD5 form
        // param reaches finalizeOrder() and OXID's validateDeliveryAddress would
        // reject the order (state 7, invalid_delivery_address). Stripe owns the
        // address validation, so set the skip flag before finalizeOrder runs.
        $session->setVariable(ControllerRequestHelper::SESSION_SKIP_ADDR_CHECK, true);

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

        // State-machine transitions are declared on the concrete PaymentContract,
        // not the interface (intentional: payment-base keeps the abstract surface
        // narrow). Narrow the type here so the calls are statically checkable.
        if (!$contract instanceof PaymentContract) {
            throw new \LogicException(
                'createEarlyOrderAndTransition requires a concrete PaymentContract; got '
                . $contract::class
            );
        }

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
        $languageId = $this->languageResolver->getActiveLanguageId();
        $shopIdInt = is_numeric($shopId) ? (int) $shopId : 1;

        $orderId = $contract->getOrderId();
        $rawOrderNumber = $contract->getMetadata('order_number');
        $orderNumber = is_string($rawOrderNumber) ? $rawOrderNumber : null;

        $contractToken = $this->tokenService->generateToken($contractId);
        $successUrl = $this->checkoutSessionService->buildSuccessUrl(
            $shopUrl,
            $contractId,
            $contractToken,
            $sessionId,
            $languageId,
            $shopIdInt
        );
        $cancelUrl = $this->checkoutSessionService->buildCancelUrl(
            $shopUrl . 'index.php?cl=payment&lang=' . $languageId . '&shp=' . $shopIdInt
        );

        return $this->checkoutSessionService->createSession(
            contractId: $contractId,
            basketSnapshot: $snapshot,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            shopId: $shopId,
            captureMode: $captureMode,
            orderId: $orderId,
            orderNumber: $orderNumber,
            embedded: $this->isIframeMode(),
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
