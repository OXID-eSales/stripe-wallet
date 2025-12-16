<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use OxidSolutionCatalysts\Payments\Component\Adapter\Exception\PaymentAdapterException;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Stripe\DTO\CheckoutSessionResult;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
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
        ?LoggerInterface $logger = null
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
        string $captureMode = 'automatic'
    ): CheckoutSessionResult {
        try {
            $lineItems = $this->buildLineItems($basketSnapshot);

            $params = [
                'mode' => 'payment',
                'line_items' => $lineItems,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'contract_id' => $contractId,
                    'shop_id' => $shopId,
                ],
                'payment_intent_data' => [
                    'capture_method' => $captureMode,
                    'metadata' => [
                        'contract_id' => $contractId,
                    ],
                ],
            ];

            $session = $this->adapterFactory->getStripeAdapter()->createCheckoutSession($params);

            $this->logger->info('Checkout session created', [
                'session_id' => $session->id,
                'contract_id' => $contractId,
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
     */
    public function buildLineItems(BasketSnapshot $snapshot): array
    {
        $lineItems = [];
        $currency = strtolower($snapshot->getCurrency());

        foreach ($snapshot->getItems() as $item) {
            $title = isset($item['title']) && is_string($item['title']) ? $item['title'] : 'Product';
            $unitPrice = isset($item['unitPrice']) && (is_float($item['unitPrice']) || is_int($item['unitPrice']))
                ? (float) $item['unitPrice']
                : 0.0;
            $quantity = isset($item['quantity']) && is_int($item['quantity']) ? $item['quantity'] : 1;

            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) round($unitPrice * 100),
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
     * @inheritDoc
     */
    public function buildSuccessUrl(string $shopUrl, string $contractId, string $contractToken, string $sessionId = ''): string
    {
        $url = $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess'
            . '&session_id={CHECKOUT_SESSION_ID}'
            . '&contract_id=' . urlencode($contractId)
            . '&contract_token=' . urlencode($contractToken);

        // Include OXID session ID to preserve session across external redirect
        if ($sessionId !== '') {
            $url .= '&force_sid=' . urlencode($sessionId);
        }

        return $url;
    }
}
