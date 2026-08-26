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
        private readonly ?IframeCheckoutSettingsInterface $iframeSettings = null
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
            // 1. Reuse the open contract for this basket if there is one,
            //    otherwise create a fresh one in DRAFT.
            //
            //    OPC-175 / OPC-176: this method used to create a NEW contract on
            //    EVERY call, and step 2 finalises a shop order — number, stock,
            //    confirmation mail. `processCheckout` is reachable from the OPC
            //    footer widget's eager mount, which fires more than once, so one
            //    shopper produced one contract and one complete order PER CALL.
            //
            //    Traced on pay1 2026-08-27 with a backtrace on
            //    Order::finalizeOrder(): two finalisations 21s apart from an
            //    identical stack, both through this method. The database showed
            //    14 contracts in one hour, every one `pending`, each with its own
            //    OXORDERID, and 495 orders with OXTRANSSTATUS=OK and no OXPAID.
            $contract = $this->findReusableContract($context) ?? $this->createContract($context);
            $contractId = $contract->getId() ?? '';

            // 2. Create the early order only when this contract has none yet.
            //    A reused contract is already past DRAFT, so transitioning it
            //    again would throw — and creating a second order for it is the
            //    defect above.
            if (($contract->getOrderId() ?? '') === '') {
                $this->createEarlyOrderAndTransition($contract, $context);
            } else {
                $this->logger?->info('[StripePaymentHandler] Reusing contract and its order', [
                    'contractId' => $contractId,
                    'orderId' => $contract->getOrderId(),
                    'state' => $contract->getStateValue(),
                ]);
            }

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

    public function confirmPayment(string $transactionId): PaymentHandlerResult
    {
        return PaymentHandlerResult::success(
            contractId: $transactionId,
            metadata: ['note' => 'Stripe confirms via redirect return + webhooks']
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

    /**
     * The still-open contract for this shopper and this basket, if any.
     *
     * Deliberately narrower than `findActiveByUserId()`, which also returns
     * `ready_to_commit` and `committed` contracts. Reusing one of those would
     * attach a second purchase to a contract whose payment is already in flight
     * or done, so only DRAFT and PENDING qualify.
     *
     * The basket must still match, too: a shopper who changed the cart is
     * legitimately starting a new contract, and reusing the old order would
     * charge them for the wrong thing. The comparison is on the snapshot the
     * contract captured — item count, per-item article and amount, and the gross
     * total — because that is what the order was created from.
     *
     * @since STRP idempotency fix (OPC-175 / OPC-176)
     */
    private function findReusableContract(PaymentContextInterface $context): ?PaymentContractInterface
    {
        $userId = $this->resolveUserId($context->getUser());
        if ($userId === '') {
            return null;
        }

        $candidate = $this->contractRepository->findActiveByUserId($userId);
        if ($candidate === null) {
            return null;
        }

        $state = $candidate->getState();
        if (!$state->isDraft() && !$state->isPending()) {
            return null;
        }

        if (!$this->basketMatchesSnapshot($context, $candidate)) {
            return null;
        }

        return $candidate;
    }

    /**
     * Does the contract's captured basket still describe what is in the basket
     * now?
     *
     * Both sides are compared through the shape each one actually has: the
     * snapshot stores items as arrays with `productId` and `quantity` (see
     * ContractService::extractItems), while the live basket is an OXID Basket
     * whose contents are BasketItem objects. `PaymentContextInterface::getBasket()`
     * is typed `object`, so every call is guarded the same way ContractService
     * guards its own.
     */
    private function basketMatchesSnapshot(
        PaymentContextInterface $context,
        PaymentContractInterface $contract
    ): bool {
        try {
            $snapshot = $contract->getBasketSnapshot();
        } catch (\Throwable) {
            return false;
        }

        $basket = $context->getBasket();

        $liveTotal = 0.0;
        if (method_exists($basket, 'getPrice')) {
            $price = $basket->getPrice();
            if (is_object($price) && method_exists($price, 'getBruttoPrice')) {
                $liveTotal = (float) $price->getBruttoPrice();
            }
        }

        if (abs($snapshot->getTotalGross() - $liveTotal) > 0.001) {
            return false;
        }

        return $this->snapshotFingerprint($snapshot->getItems()) === $this->basketFingerprint($basket);
    }

    /**
     * Order-independent fingerprint of the captured items.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function snapshotFingerprint(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = (string) ($item['productId'] ?? '') . 'x' . (string) ($item['quantity'] ?? '');
        }

        return $this->joinFingerprint($parts);
    }

    /**
     * The same fingerprint, taken from a live OXID basket.
     */
    private function basketFingerprint(object $basket): string
    {
        if (!method_exists($basket, 'getContents')) {
            return '';
        }

        $parts = [];
        foreach ((array) $basket->getContents() as $basketItem) {
            if (!is_object($basketItem)) {
                continue;
            }
            $productId = '';
            if (method_exists($basketItem, 'getProductId')) {
                $productId = (string) $basketItem->getProductId();
            }
            $amount = method_exists($basketItem, 'getAmount') ? (int) $basketItem->getAmount() : 1;
            $parts[] = $productId . 'x' . $amount;
        }

        return $this->joinFingerprint($parts);
    }

    /**
     * @param list<string> $parts
     */
    private function joinFingerprint(array $parts): string
    {
        sort($parts);

        return implode('|', $parts);
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
