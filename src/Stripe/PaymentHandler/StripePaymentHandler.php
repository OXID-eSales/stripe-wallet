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

            $this->logger->info('[StripePaymentHandler] Processing Stripe payment', [
                'paymentMethodId' => $context->getPaymentMethodId(),
                'basketTotal' => $basket->getPrice()->getBruttoPrice(),
            ]);

            // Create Stripe PaymentIntent
            $paymentIntent = $this->createPaymentIntent($basket, $user);

            // Return success with clientSecret for frontend confirmation
            // Note: Contract creation will be handled by CheckoutService
            return PaymentHandlerResult::success(
                contractId: null, // Will be set by CheckoutService after contract creation
                clientSecret: $paymentIntent->client_secret ?? '',
                metadata: [
                    'provider' => self::HANDLER_ID,
                    'paymentIntentId' => $paymentIntent->id,
                    'amount' => $paymentIntent->amount,
                    'currency' => $paymentIntent->currency,
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
     * Note: ContractId will be added to PaymentIntent metadata later,
     * after the contract is created by CheckoutService.
     */
    private function createPaymentIntent(Basket $basket, User $user): PaymentIntent
    {
        $adapter = $this->getAdapter();

        // Get basket total in cents
        $amount = (int) round($basket->getPrice()->getBruttoPrice() * 100);
        $currency = strtolower($basket->getBasketCurrency()->name);

        // Get customer email
        /** @phpstan-ignore-next-line OXID core: magic property */
        $customerEmail = $user->oxuser__oxusername->value;

        // Create PaymentIntent via Stripe SDK
        $paymentIntent = \Stripe\PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => [
                'module' => Module::MODULE_ID,
                'paymentMethodId' => 'oxidstripe',
            ],
            'receipt_email' => $customerEmail ?: null,
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
        ]);

        $this->logger->info('[StripePaymentHandler] PaymentIntent created', [
            'paymentIntentId' => $paymentIntent->id,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        return $paymentIntent;
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