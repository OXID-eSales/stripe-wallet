<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\TokenServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use RuntimeException;

/**
 * Creates Stripe Checkout Session for contract-first payment flow.
 *
 * Key differences from Bartek's OrderController::createCheckoutSession():
 * - Uses CONTRACT ID in metadata instead of order ID
 * - No order is created at this point
 * - Line items are built from contract's basket snapshot
 *
 * Flow:
 * 1. StripeCheckoutSessionRequestEvent dispatched by controller
 * 2. ContractCreationHandler creates contract (runs first via priority)
 * 3. This handler creates Stripe Checkout Session with contract_id
 * 4. Session ID returned to controller for redirect
 */
class StripeCheckoutSessionHandler implements HandlerInterface
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private StripeAdapterFactoryInterface $adapterFactory,
        private TokenServiceInterface $tokenService
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutSessionRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutSessionRequestEvent) {
            return;
        }

        $context = $event->getContext();
        $contract = $context->getContract();

        if ($contract === null) {
            throw new RuntimeException('Contract not found in context. ContractCreationHandler must run first.');
        }

        // Build line items from CONTRACT's basket snapshot (not current basket!)
        $lineItems = $this->buildLineItems($contract->getBasketSnapshot());

        // Get capture mode from context (default: automatic)
        $captureMode = $context->get('captureMode', 'automatic');
        if (!is_string($captureMode)) {
            $captureMode = 'automatic';
        }

        // Build URLs with contract ID and secure token
        $shopUrl = $context->get('shopUrl', 'https://shop.example.com/');
        if (!is_string($shopUrl)) {
            $shopUrl = 'https://shop.example.com/';
        }

        // Get contract ID
        $contractId = $contract->getId() ?? '';

        // Generate secure token for session restoration
        $contractToken = $this->tokenService->generateToken($contractId);

        // Success URL includes contract_id and contract_token for session restoration
        // The {CHECKOUT_SESSION_ID} placeholder is replaced by Stripe with actual session ID
        $successUrl = $shopUrl . 'index.php?cl=order&fnc=checkoutSuccess'
            . '&session_id={CHECKOUT_SESSION_ID}'
            . '&contract_id=' . urlencode($contractId)
            . '&contract_token=' . urlencode($contractToken);

        // Cancel URL just goes back to payment page (no token needed)
        $cancelUrl = $shopUrl . 'index.php?cl=payment';

        // Get Stripe SDK client
        $stripeClient = $this->adapterFactory->getStripeClient();

        // Get shop ID
        $shopId = $context->get('shopId', '1');
        $shopIdString = is_string($shopId) ? $shopId : (string) $shopId;

        // Create Checkout Session with CONTRACT reference (not order!)
        /** @var \Stripe\Checkout\Session $checkoutSession */
        $checkoutSession = $stripeClient->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'contract_id' => $contractId,
                'shop_id' => $shopIdString,
            ],
            'payment_intent_data' => [
                'capture_method' => $captureMode,
                'metadata' => [
                    'contract_id' => $contractId,
                ],
            ],
        ]);

        // Store session ID in contract via setProvider
        $contract->setProvider('stripe', $checkoutSession->id, $successUrl);

        $this->contractRepository->save($contract);

        // Update context for controller
        $context->set('checkoutSessionId', $checkoutSession->id);
        // Also provide the direct URL for redirect (more reliable than redirectToCheckout)
        $context->set('checkoutUrl', $checkoutSession->url);
    }

    /**
     * Build Stripe line items from basket snapshot.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLineItems(\OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot $snapshot): array
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
}
