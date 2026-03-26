<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\PaymentHandler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\OnePageCheckout\Contract\PaymentContext;
use OxidEsales\OnePageCheckout\Contract\PaymentHandlerInterface;
use OxidEsales\OnePageCheckout\Contract\PaymentHandlerResult;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Module;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\PaymentComponent\Service\TokenServiceInterface;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;

/**
 * Payment handler for Stripe integration with One-Page Checkout.
 *
 * Implements the PaymentHandlerInterface to enable Stripe payments
 * in the one-page checkout flow.
 *
 * @since 1.1.0
 */
final class StripePaymentHandler implements PaymentHandlerInterface
{
    private const HANDLER_ID = 'stripe';
    private const HANDLER_NAME = 'Stripe';

    /**
     * Stripe payment method IDs that this handler supports
     */
    private const SUPPORTED_PAYMENT_METHODS = [
        'oe_payments_stripe_wallet'
    ];

    private ?StripeAdapterInterface $adapter = null;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly CheckoutSessionServiceInterface $checkoutSessionService,
        private readonly TokenServiceInterface $tokenService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return self::HANDLER_ID;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return self::HANDLER_NAME;
    }

    /**
     * @inheritDoc
     */
    public function supports(string $paymentMethodId): bool
    {
        return in_array($paymentMethodId, self::SUPPORTED_PAYMENT_METHODS, true);
    }

    /**
     * @inheritDoc
     */
    public function processPayment(PaymentContext $context): PaymentHandlerResult
    {
        try {
            /** @var Basket $basket */
            $basket = $context->getBasket();

            /** @var User $user */
            $user = $context->getUser();

            $paymentMethodId = $context->getPaymentMethodId();

            $this->logger->info('[StripePaymentHandler] Processing Stripe payment', [
                'paymentMethodId' => $paymentMethodId,
                'basketTotal' => $basket->getPrice()->getBruttoPrice(),
            ]);

            // 1. Create BasketSnapshot from OXID basket
            $basketPrice = $basket->getPrice();
            $currency = Registry::getConfig()->getActShopCurrencyObject();

            $basketSnapshot = BasketSnapshot::fromArray([
                'items' => $this->getBasketItems($basket),
                'discounts' => $this->getBasketDiscounts($basket),
                'totalGross' => $basketPrice->getBruttoPrice(),
                'totalNet' => $basketPrice->getNettoPrice(),
                'totalVat' => $basketPrice->getVatValue(),
                'currency' => $currency->name ?? 'EUR',
            ]);

            // 2. Create PaymentContract (using constructor, not repository->create())
            $shopId = (int) Registry::getConfig()->getShopId();
            $userId = $user->getId() ?? '';

            $contract = new PaymentContract(
                shopId: $shopId,
                userId: $userId,
                basketSnapshot: $basketSnapshot
            );

            // 3. Add payment condition (required for state transitions)
            $paymentCondition = new ContractCondition(
                ContractCondition::TYPE_PAYMENT_AUTHORIZED
            );
            $contract->addCondition($paymentCondition);

            // 4. Save contract in DRAFT state BEFORE creating Checkout Session
            $this->contractRepository->save($contract);

            // 5. Create Stripe Checkout Session (redirect URL)
            $checkoutSession = $this->createCheckoutSession($basket, $user, $contract);

            // 6. Set provider information with session ID and redirect URL
            $contract->setProvider(self::HANDLER_ID, $checkoutSession['sessionId'], $checkoutSession['checkoutUrl']);

            // 7. Store metadata
            $contract->setMetadata('payment_method_id', $paymentMethodId);
            $contract->setMetadata('handler', self::HANDLER_ID);
            $contract->setMetadata('checkout_session_id', $checkoutSession['sessionId']);
            $contract->setMetadata('stripe_amount', $basketSnapshot->getTotalGross());
            $contract->setMetadata('stripe_currency', $currency->name ?? 'EUR');

            // 8. Update contract with session info
            $this->contractRepository->save($contract);

            $this->logger->info('[StripePaymentHandler] Checkout Session created successfully', [
                'contractId' => $contract->getId(),
                'sessionId' => $checkoutSession['sessionId'],
                'checkoutUrl' => $checkoutSession['checkoutUrl'],
                'state' => 'draft',
            ]);

            // 9. Return success with contractId AND redirectUrl
            return PaymentHandlerResult::success(
                contractId: $contract->getId(),
                clientSecret: '', // Not needed for Checkout Session
                metadata: [
                    'provider' => self::HANDLER_ID,
                    'sessionId' => $checkoutSession['sessionId'],
                    'checkoutUrl' => $checkoutSession['checkoutUrl'],
                    'requiresRedirect' => true,
                    'redirectUrl' => $checkoutSession['checkoutUrl'],
                    'state' => 'draft',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('[StripePaymentHandler] Error processing payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return PaymentHandlerResult::error(
                errorMessage: $e->getMessage(),
                errorCode: 'STRIPE_ERROR'
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function confirmPayment(string $transactionId): PaymentHandlerResult
    {
        try {
            $this->logger->info('[StripePaymentHandler] Confirming payment', [
                'transactionId' => $transactionId,
            ]);

            // Retrieve PaymentIntent from Stripe
            $paymentIntent = $this->getAdapter()->retrievePaymentIntent($transactionId);

            // Check if payment is succeeded
            if ($paymentIntent->status === 'succeeded') {
                return PaymentHandlerResult::success(
                    contractId: $paymentIntent->metadata['contractId'] ?? '',
                    metadata: [
                        'provider' => self::HANDLER_ID,
                        'paymentIntentId' => $paymentIntent->id,
                        'status' => $paymentIntent->status,
                        'amount' => $paymentIntent->amount,
                        'currency' => $paymentIntent->currency,
                    ]
                );
            }

            // Payment not succeeded yet
            return PaymentHandlerResult::error(
                errorMessage: "Payment not confirmed yet. Status: {$paymentIntent->status}",
                errorCode: 'PAYMENT_NOT_CONFIRMED'
            );
        } catch (\Exception $e) {
            $this->logger->error('[StripePaymentHandler] Error confirming payment', [
                'transactionId' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return PaymentHandlerResult::error(
                errorMessage: $e->getMessage(),
                errorCode: 'CONFIRMATION_ERROR'
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getFrontendConfig(): array
    {
        $config = Registry::getConfig();
        $mode = $config->getConfigParam('sStripeMode') ?: 'test';

        $publishableKey = $mode === 'live'
            ? $config->getConfigParam('sStripeLivePk')
            : $config->getConfigParam('sStripeTestPk');

        return [
            'provider' => self::HANDLER_ID,
            'publishableKey' => $publishableKey ?: '',
            'mode' => $mode,
            'supportedMethods' => self::SUPPORTED_PAYMENT_METHODS,
        ];
    }

    /**
     * Create Stripe Checkout Session for redirect-based payment.
     *
     * @return array{sessionId: string, checkoutUrl: string}
     */
    private function createCheckoutSession(Basket $basket, User $user, PaymentContract $contract): array
    {
        $config = Registry::getConfig();
        $shopUrl = $config->getSslShopUrl();
        $sessionId = Registry::getSession()->getId();

        // Build URLs - use simpler success URL for one-page checkout
        // Frontend will handle placeOrder() call after return
        $contractId = $contract->getId();
        $successUrl = $shopUrl . 'index.php?cl=OeCheckoutApi&fnc=stripeSuccess&contractId=' . urlencode($contractId);
        $cancelUrl = $shopUrl . 'index.php?cl=basket';

        $this->logger->info('[StripePaymentHandler] Creating Checkout Session', [
            'contractId' => $contract->getId(),
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
        ]);

        // Create Checkout Session via service
        $result = $this->checkoutSessionService->createSession(
            contractId: $contract->getId(),
            basketSnapshot: $contract->getBasketSnapshot(),
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            shopId: (string) $contract->getShopId(),
            captureMode: 'automatic', // Auto-capture for one-page checkout
            orderId: null, // No order yet
            orderNumber: null, // No order number yet
            stripeCustomerId: null // TODO: Support saved cards
        );

        if (!$result->isSuccessful()) {
            throw new \RuntimeException(
                'Failed to create Stripe Checkout Session: ' . ($result->getErrorMessage() ?? 'Unknown error')
            );
        }

        $this->logger->info('[StripePaymentHandler] Checkout Session created', [
            'sessionId' => $result->getSessionId(),
            'checkoutUrl' => $result->getCheckoutUrl(),
        ]);

        return [
            'sessionId' => $result->getSessionId() ?? '',
            'checkoutUrl' => $result->getCheckoutUrl() ?? '',
        ];
    }

    /**
     * Extract basket items for BasketSnapshot.
     *
     * @param Basket $basket OXID basket
     * @return array<int, array{articleId: string, title: string, amount: float, price: float, totalPrice: float}>
     */
    private function getBasketItems(Basket $basket): array
    {
        $items = [];

        foreach ($basket->getContents() as $basketItem) {
            $items[] = [
                'articleId' => $basketItem->getProductId(),
                'title' => $basketItem->getTitle(),
                'amount' => $basketItem->getAmount(),
                'price' => $basketItem->getUnitPrice()->getBruttoPrice(),
                'totalPrice' => $basketItem->getPrice()->getBruttoPrice(),
            ];
        }

        return $items;
    }

    /**
     * Extract basket discounts for BasketSnapshot.
     *
     * @param Basket $basket OXID basket
     * @return array<int, array{name: string, amount: float}>
     */
    private function getBasketDiscounts(Basket $basket): array
    {
        $discounts = [];

        // Get voucher discounts
        $vouchers = $basket->getVouchers();
        if ($vouchers) {
            foreach ($vouchers as $voucherId => $voucher) {
                $discounts[] = [
                    'name' => $voucher->sVoucherNr ?? 'Voucher',
                    'amount' => (float) ($voucher->dVoucherdiscount ?? 0),
                ];
            }
        }

        // Get other discounts (e.g., basket discounts)
        $basketDiscounts = $basket->getDiscounts();
        if ($basketDiscounts) {
            foreach ($basketDiscounts as $discount) {
                $discounts[] = [
                    'name' => $discount->sOXID ?? 'Discount',
                    'amount' => (float) ($discount->dDiscount ?? 0),
                ];
            }
        }

        return $discounts;
    }

    /**
     * Get Stripe adapter instance
     */
    private function getAdapter(): StripeAdapterInterface
    {
        if ($this->adapter === null) {
            $this->adapter = $this->adapterFactory->getStripeAdapter();
        }
        return $this->adapter;
    }
}