<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\TokenServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use RuntimeException;

/**
 * Creates Stripe Checkout Session for contract-first payment flow.
 *
 * Sprint 21: Refactored to delegate business logic to CheckoutSessionService.
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
        private readonly CheckoutSessionServiceInterface $checkoutSessionService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly TokenServiceInterface $tokenService
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

        $contractId = $contract->getId() ?? '';

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

        // Generate secure token for session restoration
        $contractToken = $this->tokenService->generateToken($contractId);

        // Build success and cancel URLs
        $successUrl = $this->checkoutSessionService->buildSuccessUrl($shopUrl, $contractId, $contractToken);
        $cancelUrl = $shopUrl . 'index.php?cl=payment';

        // Get shop ID
        $shopId = $context->get('shopId', '1');
        $shopIdString = is_string($shopId) ? $shopId : (string) $shopId;

        // Sprint 21: Delegate session creation to service
        $result = $this->checkoutSessionService->createSession(
            $contractId,
            $contract->getBasketSnapshot(),
            $successUrl,
            $cancelUrl,
            $shopIdString,
            $captureMode
        );

        if (!$result->isSuccessful()) {
            throw new RuntimeException(
                'Failed to create checkout session: ' . ($result->getErrorMessage() ?? 'Unknown error')
            );
        }

        // Store session ID in contract via setProvider
        $contract->setProvider('stripe', $result->getSessionId() ?? '', $successUrl);

        $this->contractRepository->save($contract);

        // Update context for controller
        $context->set('checkoutSessionId', $result->getSessionId());
        $context->set('checkoutUrl', $result->getCheckoutUrl());
    }
}
