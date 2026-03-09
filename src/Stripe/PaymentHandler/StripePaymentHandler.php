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

            // 3. Create Stripe PaymentIntent BEFORE saving contract
            // We need the contract temporarily to link PaymentIntent metadata
            $paymentIntent = $this->createPaymentIntent($basket, $user, $contract);

            // 4. Set provider information and store PaymentIntent ID
            $contract->setProvider(self::HANDLER_ID, $paymentMethodId);
            $contract->setProviderOrderId($paymentIntent->id);

            // 5. Store metadata
            $contract->setMetadata('payment_method_id', $paymentMethodId);
            $contract->setMetadata('handler', self::HANDLER_ID);
            $contract->setMetadata('payment_intent_id', $paymentIntent->id);
            $contract->setMetadata('stripe_amount', $paymentIntent->amount);
            $contract->setMetadata('stripe_currency', $paymentIntent->currency);

            // 6. Add payment condition (required for state transitions)
            // Note: Condition will be fulfilled AFTER Stripe confirms payment (webhook or confirmPayment)
            $paymentCondition = new ContractCondition(
                ContractCondition::TYPE_PAYMENT_AUTHORIZED
            );
            $contract->addCondition($paymentCondition);

            // 7. Save contract in DRAFT state
            // IMPORTANT: State transitions will happen later:
            // - Frontend confirms payment with Stripe
            // - Webhook or confirmPayment() fulfills payment condition
            // - placeOrder() performs early order creation and state transitions
            $this->contractRepository->save($contract);

            $this->logger->info('[StripePaymentHandler] Contract created successfully', [
                'contractId' => $contract->getId(),
                'paymentIntentId' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'state' => 'draft',
            ]);

            // 8. Return success with contractId AND clientSecret
            return PaymentHandlerResult::success(
                contractId: $contract->getId(),
                clientSecret: $paymentIntent->client_secret ?? '',
                metadata: [
                    'provider' => self::HANDLER_ID,
                    'paymentIntentId' => $paymentIntent->id,
                    'amount' => $paymentIntent->amount,
                    'currency' => $paymentIntent->currency,
                    'requiresConfirmation' => true,
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
     * Create Stripe PaymentIntent for the basket.
     *
     * Links PaymentIntent with PaymentContract for tracking.
     */
    private function createPaymentIntent(Basket $basket, User $user, PaymentContract $contract): PaymentIntent
    {
        $adapter = $this->getAdapter();

        // Get basket total in cents
        $amount = (int) round($basket->getPrice()->getBruttoPrice() * 100);
        $currency = strtolower($basket->getBasketCurrency()->name);

        // Get customer email
        /** @phpstan-ignore-next-line OXID core: magic property */
        $customerEmail = $user->oxuser__oxusername->value;

        // Create PaymentIntent via Stripe SDK
        // Note: We link contractId in metadata so webhooks can find the contract
        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => [
                'module' => Module::MODULE_ID,
                'paymentMethodId' => 'oe_payments_stripe_wallet',
                'contractId' => $contract->getId(),
                'shopId' => $contract->getShopId(),
                'userId' => $contract->getUserId(),
            ],
            'receipt_email' => $customerEmail ?: null,
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ]);

        $this->logger->info('[StripePaymentHandler] PaymentIntent created', [
            'paymentIntentId' => $paymentIntent->id,
            'contractId' => $contract->getId(),
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return $paymentIntent;
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