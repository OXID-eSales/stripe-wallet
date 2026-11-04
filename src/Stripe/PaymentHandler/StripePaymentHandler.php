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
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\Contract\ContractDraftCompletedEvent;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutInFlightGuard;
use OxidEsales\PaymentBase\Checkout\Return\ContractReturnTokenServiceInterface;
use OxidEsales\PaymentBase\Checkout\Return\ReturnAccessGuard;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Adapter\StripeStatusMapper;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Bridges PaymentHandlerInterface to Stripe Checkout Sessions
 * for use with one-page-checkout's redirect flow.
 *
 * Flow:
 * 1. Create contract (DRAFT)
 * 2. Dispatch ContractDraftCompletedEvent: payment-base's EarlyOrderCreationHandler
 *    retires the attempt this session left open, creates the early order
 *    (NOT_FINISHED) and moves the contract DRAFT → NOT_FINISHED → PENDING —
 *    the same chain as Mollie, PayPal and Stripe's own `cl=order` checkout (OPC-175)
 * 3. Check that the order exists before Stripe is called
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


    public function __construct(
        private readonly ContractServiceInterface $contractService,
        private readonly CheckoutSessionServiceInterface $checkoutSessionService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly ContractReturnTokenServiceInterface $returnTokens,
        // OPC-175: the shared chain that creates the early order.
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly LanguageResolverInterface $languageResolver,
        private readonly IframeCheckoutSettingsInterface $iframeSettings,
        private readonly CheckoutInFlightGuard $inFlightGuard
    ) {
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
        $request = new HeadlessCheckoutContext($context);
        $headless = $request->isHeadless();
        if ($headless && !in_array($request->uiMode(), self::HEADLESS_UI_MODES, true)) {
            return PaymentHandlerResult::error(
                sprintf(
                    'Stripe does not support uiMode "%s" yet (supported: %s)',
                    $request->uiMode(),
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

        $contractId = '';
        try {
            // 1. Create contract in DRAFT state
            $contract = $this->createContract($context, $headless);
            $contractId = $contract->getId() ?? '';

            // 2. Early order through the shared chain: DRAFT → NOT_FINISHED → PENDING
            $this->createEarlyOrderAndTransition($contract, $context);

            // 3. Create Stripe Checkout Session
            $sessionResult = $this->createCheckoutSession($contract, $context);

            if (!$sessionResult->isSuccessful()) {
                // The provider's text may hold session ids and URLs and the result reaches
                // the GraphQL error and so the shop log: the text stays at debug level.
                $this->logger->debug('[StripePaymentHandler] Stripe refused the checkout session', [
                    'contractId' => $contractId,
                    'providerCode' => $sessionResult->getErrorCode(),
                    'providerMessage' => $sessionResult->getErrorMessage(),
                ]);

                return PaymentHandlerResult::error('Failed to create Stripe checkout session', 'STRIPE_SESSION_FAILED');
            }

            // 4. Store session ID on contract
            $contract->setProvider(StripeDefinitions::PROVIDER, $sessionResult->getSessionId() ?? '');
            $this->contractRepository->save($contract);

            $this->logger->info('[StripePaymentHandler] Checkout session created', [
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
            // Exception text can carry provider ids and URLs: class at error level, text at debug.
            $this->logger->error('[StripePaymentHandler] processPayment failed', [
                'contractId' => $contractId,
                'exception' => $e::class,
            ]);
            $this->logger->debug('[StripePaymentHandler] processPayment failure detail', [
                'contractId' => $contractId,
                'providerMessage' => $e->getMessage(),
            ]);

            return PaymentHandlerResult::error('Stripe payment processing failed', 'STRIPE_PAYMENT_FAILED');
        }
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
        return $this->iframeSettings->isEnabled();
    }

    private function createContract(PaymentContextInterface $context, bool $headless): PaymentContractInterface
    {
        $userId = $this->resolveUserId($context->getUser());

        $contract = $this->contractService->createContract(
            $userId,
            $context->getBasket(),
            ['payment_authorized']
        );

        $contract->setMetadata('payment_method_id', $context->getPaymentMethodId());
        $contract->setMetadata('handler', StripeDefinitions::PROVIDER);
        if (!$headless) {
            // VR-20 R5: the shop session the payment starts in; payment-base's ReturnAccessGuard lets the contract's
            // shopper back in through it. A headless client has no shop session: its return is payment-base's own.
            $contract->setMetadata(ReturnAccessGuard::METADATA_INITIATING_SESSION, $this->sessionId());
        }

        return $contract;
    }

    /**
     * Create the early order through payment-base's shared chain, as Mollie,
     * PayPal and Stripe's own `cl=order` checkout do.
     *
     * OPC-175: ContractDraftCompletedEvent → EarlyOrderCreationHandler retires
     * the attempt this session (headless: this basket) left open, creates the
     * NOT_FINISHED order, moves the contract DRAFT → NOT_FINISHED → PENDING and
     * remembers it as the open attempt. Until OPC-175 this method created the
     * order itself and never retired anything, so a switch between providers
     * left the previous order open - holding the customer's voucher, which core
     * then dropped from the basket and refused in the next finalizeOrder().
     *
     * Protected: a unit-test seam (see StripePaymentHandlerLanguageTest).
     */
    protected function createEarlyOrderAndTransition(
        PaymentContractInterface $contract,
        PaymentContextInterface $context
    ): void {
        $request = new HeadlessCheckoutContext($context);
        $headless = $request->isHeadless();

        if (!$headless) {
            // The OPC checkout lives in the PHP session: the session basket gets
            // the payment and the address check is skipped (Stripe owns it).
            $this->prepareSessionForOrder($context->getPaymentMethodId());
        }

        $this->eventDispatcher->dispatch(new ContractDraftCompletedEvent(
            $contract,
            $this->draftCompletedContext($contract, $context, $headless)
        ));

        $this->assertEarlyOrderCreated($contract);

        $this->logger->info('[StripePaymentHandler] Early order created, contract in PENDING', [
            'contractId' => $contract->getId(),
            'orderId' => $contract->getOrderId(),
            'orderNumber' => $contract->getMetadata('order_number'),
            'state' => $contract->getStateValue(),
        ]);
    }

    /**
     * What EarlyOrderCreationHandler reads: the payment, the user, the session
     * the order belongs to and - headless only - the persisted basket, from
     * which payment-base builds the shop basket without a PHP session.
     */
    private function draftCompletedContext(
        PaymentContractInterface $contract,
        PaymentContextInterface $context,
        bool $headless
    ): EventContext {
        $request = new HeadlessCheckoutContext($context);
        $basketId = $headless ? $request->basketId() : null;
        $data = [
            'providerName' => StripeDefinitions::PROVIDER,
            'paymentId' => $context->getPaymentMethodId(),
            'userId' => $contract->getUserId(),
            'sessionId' => $headless ? $request->sessionId() : $this->sessionId(),
        ];

        if ($basketId !== null) {
            $data['basketId'] = $basketId;
        }

        return new EventContext($data);
    }

    /**
     * A contract without an order must never reach Stripe: the customer would
     * pay for nothing the shop can fulfil. EarlyOrderCreationHandler rethrows
     * its own failures; this catches a chain on which nobody created the order.
     */
    private function assertEarlyOrderCreated(PaymentContractInterface $contract): void
    {
        $orderId = $contract->getOrderId();
        if ($orderId === null || $orderId === '' || !$contract->getState()->isPending()) {
            throw new \RuntimeException('No early order was created for contract ' . (string) $contract->getId());
        }
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

        $this->logger->info('[StripePaymentHandler] Reusing checkout session', [
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
        return $this->inFlightGuard;
    }

    private function createCheckoutSession(
        PaymentContractInterface $contract,
        PaymentContextInterface $context
    ): \OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult {
        $contractId = $contract->getId() ?? '';
        $request = new HeadlessCheckoutContext($context);
        $headless = $request->isHeadless();

        $rawOrderNumber = $contract->getMetadata('order_number');
        $orderNumber = is_string($rawOrderNumber) ? $rawOrderNumber : null;

        [$successUrl, $cancelUrl] = $headless
            ? $request->returnUrls()
            : $this->shopUrls($contract);

        return $this->checkoutSessionService->createSession(
            contractId: $contractId,
            basketSnapshot: $contract->getBasketSnapshot(),
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            shopId: $this->shopAdapter->getShopId(),
            captureMode: $this->config->getCaptureMode(),
            orderId: $contract->getOrderId(),
            orderNumber: $orderNumber,
            embedded: $headless ? $request->isEmbedded() : $this->isIframeMode(),
        );
    }

    /**
     * The Twig / OPC targets: the shop's own return controller (with the
     * contract token and the shop session id) and its payment step.
     *
     * @return array{0: string, 1: string}
     */
    private function shopUrls(PaymentContractInterface $contract): array
    {
        $contractId = $contract->getId() ?? '';
        $shopUrl = $this->shopAdapter->getShopUrl();
        $languageId = $this->languageResolver->getActiveLanguageId();
        // Sprint 133 (F14): no silent fallback to shop 1 on EE multishop.
        $shopIdInt = ShopId::of($this->shopAdapter->getShopId(), 'checkout session creation');

        $successUrl = $this->checkoutSessionService->buildSuccessUrl(
            $shopUrl,
            $contractId,
            // VR-20 R5: payment-base's shared return token, bound to the shopper and expiring.
            $this->returnTokens->generateToken($contractId, $contract->getUserId()),
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
