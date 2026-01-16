<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\PaymentComponent\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
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
        private readonly TokenServiceInterface $tokenService,
        private readonly ?FileLoggerInterface $eventLogger = null
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
        $this->logEvent('StripeCheckoutSessionHandler::handle() START');

        if (!$event instanceof StripeCheckoutSessionRequestEvent) {
            $this->logEvent('StripeCheckoutSessionHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();
        $contract = $context->getContract();

        if ($contract === null) {
            $this->logEvent('StripeCheckoutSessionHandler: ERROR - Contract not found in context');
            throw new RuntimeException('Contract not found in context. ContractCreationHandler must run first.');
        }

        $this->logEvent('StripeCheckoutSessionHandler: Contract found', [
            'contractId' => $contract->getId(),
        ]);

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

        // Get OXID session ID to preserve session across Stripe redirect
        $sessionId = $context->get('sessionId', '');
        if (!is_string($sessionId)) {
            $sessionId = '';
        }

        // Build success and cancel URLs
        $successUrl = $this->checkoutSessionService->buildSuccessUrl($shopUrl, $contractId, $contractToken, $sessionId);
        $cancelUrl = $shopUrl . 'index.php?cl=payment';

        // Get shop ID
        $shopId = $context->get('shopId', '1');
        $shopIdString = is_string($shopId) ? $shopId : (string) $shopId;

        // STRP-75: Get order ID and order number from contract
        $orderId = $contract->getOrderId();
        $orderNumber = $contract->getMetadata('order_number');
        $orderNumberString = is_string($orderNumber) || is_int($orderNumber) ? (string) $orderNumber : null;

        // Sprint 21: Delegate session creation to service
        $this->logEvent('StripeCheckoutSessionHandler: Creating checkout session', [
            'contractId' => $contractId,
            'captureMode' => $captureMode,
            'orderId' => $orderId,
            'orderNumber' => $orderNumberString,
        ]);

        $result = $this->checkoutSessionService->createSession(
            $contractId,
            $contract->getBasketSnapshot(),
            $successUrl,
            $cancelUrl,
            $shopIdString,
            $captureMode,
            $orderId,
            $orderNumberString
        );

        if (!$result->isSuccessful()) {
            $this->logEvent('StripeCheckoutSessionHandler: ERROR - Session creation failed', [
                'error' => $result->getErrorMessage(),
            ]);
            throw new RuntimeException(
                'Failed to create checkout session: ' . ($result->getErrorMessage() ?? 'Unknown error')
            );
        }

        $this->logEvent('StripeCheckoutSessionHandler: Session created', [
            'sessionId' => $result->getSessionId(),
        ]);

        // Store session ID in contract via setProvider
        $contract->setProvider('stripe', $result->getSessionId() ?? '', $successUrl);

        $this->contractRepository->save($contract);

        // Update context for controller
        $context->set('checkoutSessionId', $result->getSessionId());
        $context->set('checkoutUrl', $result->getCheckoutUrl());

        $this->logEvent('StripeCheckoutSessionHandler::handle() END', [
            'checkoutSessionId' => $result->getSessionId(),
        ]);
    }

    /**
     * Log event to file logger for debugging.
     *
     * @param string $message
     * @param array<string, mixed> $context
     */
    private function logEvent(string $message, array $context = []): void
    {
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
