<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\PaymentHandler;

use OxidEsales\Payments\Stripe\Core\ShopId;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentBase\Adapter\ContractFirstPaymentHandlerInterface;
use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\PaymentHandlerResult;
use OxidEsales\PaymentBase\Adapter\Request\CreateOrderRequest;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard;
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
class StripePaymentHandler implements ContractFirstPaymentHandlerInterface
{
    /** GRAPH-QL / PS1: the render modes a headless client may ask for; `custom` is phase 4 of the epic. */
    private const HEADLESS_UI_MODES = ['hosted', 'embedded'];

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
        private readonly ?StripeAdapterFactoryInterface $adapterFactory = null,
        private readonly ?CheckoutInFlightGuard $inFlightGuard = null
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
        // GRAPH-QL / PS1: a headless context (payment-base's HeadlessCheckoutService)
        // has no PHP session and names its render mode up front.
        $headless = $this->isHeadless($context);
        if ($headless && !in_array($this->uiModeOf($context), self::HEADLESS_UI_MODES, true)) {
            return PaymentHandlerResult::error(
                sprintf(
                    'Stripe does not support uiMode "%s" yet (supported: %s)',
                    $this->uiModeOf($context),
                    implode(', ', self::HEADLESS_UI_MODES)
                ),
                'STRIPE_UI_MODE_UNSUPPORTED'
            );
        }

        // The OPC checkout API calls this repeatedly while the customer works
        // through the accordion. Preparing a whole new checkout each time leaves
        // several Stripe sessions, contracts and early orders behind for one
        // basket, and lets the customer pay in a sheet the shop has moved on
        // from. Hand back the one already in flight when it still fits. A
        // headless start is one call per basket; payment-base's attempt guard
        // and retire-by-basket own the duplicates there.
        $reused = $headless ? null : $this->reuseCheckoutInFlight($context);
        if ($reused !== null) {
            return $reused;
        }

        try {
            // 1. Create contract in DRAFT state
            $contract = $this->createContract($context);
            $contractId = $contract->getId() ?? '';

            // 2. Create early order and transition DRAFT → NOT_FINISHED → PENDING
            $this->createEarlyOrderAndTransition($contract, $context);

            // 3. Create Stripe Checkout Session
            $sessionResult = $this->createCheckoutSession($contract, $context);

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
                    // OPC's footer widget knows 'iframe'; a headless client gets the
                    // name payment-base's result types use.
                    'renderMode' => $embedded ? ($headless ? 'embedded' : 'iframe') : 'redirect',
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
        $headless = $this->isHeadless($context);
        $basketId = $headless ? $this->basketIdOf($context) : null;

        if (!$headless) {
            // The OPC checkout lives in the PHP session: the session basket gets
            // the payment and the address check is skipped (Stripe owns it).
            $this->prepareSessionForOrder($paymentMethodId);
        }

        // GRAPH-QL / PS1: a headless checkout names the persisted basket it pays
        // for; payment-base's order service then builds the shop basket from
        // that row (no session) and the address hash is restored by it.
        $request = new CreateOrderRequest(
            sessionId: $headless ? $this->headlessSessionId($context, $basketId) : $this->sessionId(),
            userId: $contract->getUserId(),
            paymentId: $paymentMethodId,
            paymentTransactionId: null,
            orderRemark: null,
            metadata: ['contract_id' => $contract->getId()],
            initialStatus: 'NOT_FINISHED',
            basketId: $basketId
        );

        $orderResponse = $this->shopOrderService->createOrder($request);
        $orderId = $orderResponse->orderId;

        $contract->setMetadata('order_number', (string) $orderResponse->orderNumber);
        if ($basketId !== null) {
            // So the next start for this basket retires this attempt, and the
            // basket is removed on commit (payment-base S3 / S6).
            $contract->setMetadata('basket_id', $basketId);
        }

        // Sprint 133 (F15): transitionToNotFinished() is now on
        // PaymentContractInterface alongside transitionToPending(), so this no
        // longer has to narrow to the concrete PaymentContract. The old comment
        // claimed the narrow abstract surface was intentional, but every other
        // transition this module uses was already on the interface.

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

    /**
     * The checkout this shopper already has in flight, if it can still be used.
     *
     * Returns the same result shape processPayment() would have produced, so the
     * caller cannot tell a reused checkout from a fresh one.
     */
    private function reuseCheckoutInFlight(PaymentContextInterface $context): ?PaymentHandlerResult
    {
        $contract = $this->findContractInFlight($context);
        $session = $this->checkoutInFlightGuard()->inspect($contract);
        if ($contract === null || $session === null) {
            return null;
        }

        $this->logger?->info('[StripePaymentHandler] Reusing checkout session', [
            'contractId' => $contract->getId(),
            'sessionId' => $session->id,
        ]);

        $embedded = $this->isIframeMode();

        return PaymentHandlerResult::success(
            contractId: (string) $contract->getId(),
            clientSecret: $embedded ? $session->clientSecret : null,
            metadata: [
                'handler' => StripeDefinitions::PROVIDER,
                'renderMode' => $embedded ? 'iframe' : 'redirect',
                'requiresRedirect' => !$embedded,
                'redirectUrl' => $session->url,
                'sessionId' => $session->id,
                'reused' => true,
            ]
        );
    }

    protected function findContractInFlight(PaymentContextInterface $context): ?PaymentContractInterface
    {
        $userId = $this->resolveUserId($context->getUser());

        return $userId === '' ? null : $this->contractRepository->findActiveByUserId($userId);
    }

    protected function checkoutInFlightGuard(): CheckoutInFlightGuard
    {
        return $this->inFlightGuard ?? new CheckoutInFlightGuard($this->adapterFactory);
    }

    private function createCheckoutSession(
        PaymentContractInterface $contract,
        PaymentContextInterface $context
    ): \OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult {
        $contractId = $contract->getId() ?? '';
        $headless = $this->isHeadless($context);

        $rawOrderNumber = $contract->getMetadata('order_number');
        $orderNumber = is_string($rawOrderNumber) ? $rawOrderNumber : null;

        [$successUrl, $cancelUrl] = $headless
            ? $this->headlessUrls($context)
            : $this->shopUrls($contractId);

        return $this->checkoutSessionService->createSession(
            contractId: $contractId,
            basketSnapshot: $contract->getBasketSnapshot(),
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            shopId: $this->shopAdapter->getShopId(),
            captureMode: $this->config->getCaptureMode(),
            orderId: $contract->getOrderId(),
            orderNumber: $orderNumber,
            embedded: $headless ? $this->uiModeOf($context) === 'embedded' : $this->isIframeMode(),
        );
    }

    /**
     * The Twig / OPC targets: the shop's own return controller (with the
     * contract token and the shop session id) and its payment step.
     *
     * @return array{0: string, 1: string}
     */
    private function shopUrls(string $contractId): array
    {
        $shopUrl = $this->shopAdapter->getShopUrl();
        $languageId = $this->languageResolver->getActiveLanguageId();
        // Sprint 133 (F14): no silent fallback to shop 1 on EE multishop.
        $shopIdInt = ShopId::of($this->shopAdapter->getShopId(), 'checkout session creation');

        $successUrl = $this->checkoutSessionService->buildSuccessUrl(
            $shopUrl,
            $contractId,
            $this->tokenService->generateToken($contractId),
            $this->sessionId(),
            $languageId,
            $shopIdInt
        );
        $cancelUrl = $this->checkoutSessionService->buildCancelUrl(
            $shopUrl . 'index.php?cl=payment&lang=' . $languageId . '&shp=' . $shopIdInt
        );

        return [$successUrl, $cancelUrl];
    }

    /**
     * GRAPH-QL / PS1: the client's URLs, already validated by payment-base's
     * return-URL policy. Stripe appends the Checkout Session id to the return
     * URL so the client can hand it to `stripeCheckoutReturn`. Hosted mode uses
     * success/cancel URLs, embedded mode a single return URL - the same string
     * serves both. Without a cancel URL the shopper comes back to the return
     * URL and the client reads the contract state.
     *
     * @return array{0: string, 1: string}
     */
    private function headlessUrls(PaymentContextInterface $context): array
    {
        $returnUrl = (string) $context->getReturnUrl();
        $successUrl = $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl = $context->getCancelUrl();

        return [$successUrl, is_string($cancelUrl) && $cancelUrl !== '' ? $cancelUrl : $returnUrl];
    }

    private function isHeadless(PaymentContextInterface $context): bool
    {
        return $context->getMetadataValue('headless') === true;
    }

    private function uiModeOf(PaymentContextInterface $context): string
    {
        $uiMode = $context->getMetadataValue('uiMode', 'hosted');

        return is_string($uiMode) && $uiMode !== '' ? $uiMode : 'hosted';
    }

    private function basketIdOf(PaymentContextInterface $context): ?string
    {
        $basketId = $context->getMetadataValue('basketId');

        return is_string($basketId) && $basketId !== '' ? $basketId : null;
    }

    private function headlessSessionId(PaymentContextInterface $context, ?string $basketId): string
    {
        $sessionId = $context->getMetadataValue('sessionId');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : 'headless:' . (string) $basketId;
    }

    /**
     * Seam: the shop session id (Twig / OPC only).
     */
    protected function sessionId(): string
    {
        return (string) Registry::getSession()->getId();
    }

    /**
     * Seam: what the OPC checkout needs in the session before finalizeOrder().
     * OXID validates the payment during order creation (ORDER_STATE_INVALIDPAYMENT),
     * and the OPC address is saved via AJAX, so no sDeliveryAddressMD5 reaches
     * finalizeOrder() - Stripe owns the address validation, hence the skip flag.
     */
    protected function prepareSessionForOrder(string $paymentMethodId): void
    {
        $session = Registry::getSession();
        $basket = $session->getBasket();
        $basket->setPayment($paymentMethodId);
        $session->setVariable('paymentid', $paymentMethodId);
        $session->setVariable(ControllerRequestHelper::SESSION_SKIP_ADDR_CHECK, true);
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
