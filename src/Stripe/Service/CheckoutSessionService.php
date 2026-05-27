<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCancelUrlBuildEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeSuccessUrlBuildEvent;
use OxidEsales\Payments\Stripe\Core\AmountConverter;
use OxidEsales\Payments\Stripe\Service\Result\CheckoutSessionResult;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for creating Stripe checkout sessions.
 *
 * Sprint 21: Extract business logic from StripeCheckoutSessionHandler.
 *
 * SOLID Principles:
 * - SRP: Handles checkout session creation only
 * - OCP: Can be extended for different checkout configurations
 * - DIP: Depends on abstractions (interfaces)
 * - ISP: Focused interface for checkout session operations only
 *
 * @since 2.0.0
 */
class CheckoutSessionService implements CheckoutSessionServiceInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @inheritDoc
     */
    public function createSession(
        string $contractId,
        BasketSnapshot $basketSnapshot,
        string $successUrl,
        string $cancelUrl,
        string $shopId = '1',
        string $captureMode = 'automatic',
        ?string $orderId = null,
        ?string $orderNumber = null,
        ?string $stripeCustomerId = null
    ): CheckoutSessionResult {
        try {
            $lineItems = $this->buildLineItems($basketSnapshot);

            $sessionMetadata = [
                'contract_id' => $contractId,
                'shop_id' => $shopId,
            ];

            $paymentIntentMetadata = [
                'contract_id' => $contractId,
            ];

            // STRP-75: Include order info in metadata when available
            if ($orderId !== null) {
                $sessionMetadata['order_id'] = $orderId;
                $paymentIntentMetadata['order_id'] = $orderId;
            }

            if ($orderNumber !== null) {
                $sessionMetadata['order_number'] = $orderNumber;
                $paymentIntentMetadata['order_number'] = $orderNumber;
            }

            $params = [
                'mode' => 'payment',
                'line_items' => $lineItems,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $sessionMetadata,
                'payment_intent_data' => [
                    'capture_method' => $captureMode,
                    'metadata' => $paymentIntentMetadata,
                ],
            ];

            // Sprint 45: Add customer for email prefill and saved cards
            if ($stripeCustomerId !== null) {
                $params['customer'] = $stripeCustomerId;
                $params['saved_payment_method_options'] = [
                    'payment_method_save' => 'enabled',
                ];
            }

            $session = $this->adapterFactory->getStripeAdapter()->createCheckoutSession($params);

            $this->logger->info('Checkout session created', [
                'session_id' => $session->id,
                'contract_id' => $contractId,
                'order_number' => $orderNumber,
            ]);

            return CheckoutSessionResult::success($session->id, $session->url);
        } catch (PaymentAdapterException $e) {
            $this->logger->error('Failed to create checkout session', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return CheckoutSessionResult::failure($e->getMessage(), $e->getErrorCode());
        }
    }

    /**
     * @inheritDoc
     *
     * Builds Stripe-formatted line items from basket snapshot.
     *
     * When discounts or vouchers are present in the snapshot, uses totalGross
     * (the authoritative basket total from OXID) as a single line item.
     * Stripe doesn't support negative line item amounts, so itemized display
     * is only possible when no discounts are applied.
     *
     * When no discounts are present, items are sent individually for better
     * visibility on the Stripe Checkout page.
     */
    public function buildLineItems(BasketSnapshot $snapshot): array
    {
        $currency = strtolower($snapshot->getCurrency());

        if (!empty($snapshot->getDiscounts())) {
            return $this->buildTotalLineItem($snapshot, $currency);
        }

        return $this->buildItemizedLineItems($snapshot, $currency);
    }

    /**
     * Build individual line items from snapshot items (products, shipping, fees).
     *
     * Used when no discounts are applied, so item sum matches totalGross.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildItemizedLineItems(BasketSnapshot $snapshot, string $currency): array
    {
        $lineItems = [];

        foreach ($snapshot->getItems() as $item) {
            $title = isset($item['title']) && is_string($item['title']) ? $item['title'] : 'Product';
            $unitPrice = isset($item['unitPrice']) && (is_float($item['unitPrice']) || is_int($item['unitPrice']))
                ? (float) $item['unitPrice']
                : 0.0;
            $quantity = isset($item['quantity']) && is_int($item['quantity']) ? $item['quantity'] : 1;

            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => AmountConverter::toMinorUnits($unitPrice, $currency),
                    'product_data' => [
                        'name' => $title,
                    ],
                ],
                'quantity' => $quantity,
            ];
        }

        return $lineItems;
    }

    /**
     * Build a single line item using totalGross from the basket snapshot.
     *
     * Used when discounts/vouchers are present. Stripe doesn't support negative
     * line item amounts, so we use the authoritative totalGross from OXID's
     * basket engine which already includes all discounts, shipping, and fees.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTotalLineItem(BasketSnapshot $snapshot, string $currency): array
    {
        $totalCents = AmountConverter::toMinorUnits($snapshot->getTotalGross(), $currency);

        return [
            [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $totalCents,
                    'product_data' => [
                        'name' => 'Order Total',
                    ],
                ],
                'quantity' => 1,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function buildSuccessUrl(
        string $shopUrl,
        string $contractId,
        string $contractToken,
        string $sessionId = '',
        int $languageId = 0,
        int $shopId = 1
    ): string {
        $url = $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess'
            . '&lang=' . $languageId
            . '&shp=' . $shopId
            . '&session_id={CHECKOUT_SESSION_ID}'
            . '&contract_id=' . urlencode($contractId)
            . '&contract_token=' . urlencode($contractToken);

        // Include OXID session ID to preserve session across external redirect
        if ($sessionId !== '') {
            $url .= '&force_sid=' . urlencode($sessionId);
        }

        if ($this->eventDispatcher !== null) {
            $event = new StripeSuccessUrlBuildEvent($url, $contractId);
            $this->eventDispatcher->dispatch($event);
            $url = $event->getUrl();
        }

        return $url;
    }

    /**
     * @inheritDoc
     */
    public function buildCancelUrl(string $cancelUrl): string
    {
        if ($this->eventDispatcher !== null) {
            $event = new StripeCancelUrlBuildEvent($cancelUrl);
            $this->eventDispatcher->dispatch($event);
            $cancelUrl = $event->getUrl();
        }

        return $cancelUrl;
    }
}
