<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Controller\Core\OrderController;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Traits\ServiceContainer;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;

/**
 * Thin Stripe Order Controller.
 *
 * This controller is THIN - it only:
 * 1. Validates input
 * 2. Creates EventContext with data
 * 3. Dispatches appropriate event
 * 4. Returns result from context
 *
 * ALL business logic is in event handlers:
 * - StripeCheckoutSessionHandler
 * - StripeCheckoutReturnHandler
 * - StripePaymentStatusHandler
 * - StripePaymentReturnHandler
 *
 * @since 2.0.0
 */
class StripeOrderController extends OrderController
{
    use ServiceContainer;

    /**
     * Execute Stripe payment via Payment Element flow.
     *
     * Called when customer submits payment form with Payment Element.
     */
    public function executeStripePayment(): string
    {
        // 1. Validate
        $basket = $this->getBasketFromSession();
        if ($basket->getProductsCount() === 0) {
            $this->addErrorToDisplay('Basket is empty');
            return 'basket';
        }

        $paymentIntentId = $this->getPaymentIntentIdFromRequest()
            ?? $this->getSessionPaymentIntentId();

        if ($paymentIntentId === null) {
            $this->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // 2. Create context - ONLY DATA, NO LOGIC
        $context = new EventContext([
            'basket' => $basket,
            'user' => $basket->getBasketUser(),
            'userId' => $basket->getBasketUser()?->getId(),
            'sessionId' => $this->getSessionId(),
            'paymentId' => $basket->getPaymentId(),
            'paymentIntentId' => $paymentIntentId,
            'contractId' => $this->getContractIdFromSession(),
        ]);

        // 3. Dispatch event - HANDLERS DO THE WORK
        $event = new StripePaymentExecuteEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // 4. Process results from context
        $this->processContextResults($context);

        return $context->get('redirectTarget') ?? 'order';
    }

    /**
     * Create Stripe Checkout Session (AJAX endpoint).
     *
     * Returns JSON with session ID for client-side redirect.
     */
    public function createCheckoutSession(): void
    {
        header('Content-Type: application/json');

        try {
            // 0. Validate API key configuration
            $config = $this->getServiceFromContainer(\OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService::class);
            $keyValidationError = $config->getKeyValidationError();
            if ($keyValidationError !== null) {
                throw new \RuntimeException('Stripe configuration error: ' . $keyValidationError);
            }

            // 1. Validate
            $basket = $this->getBasketFromSession();
            if ($basket->getProductsCount() === 0) {
                throw new \RuntimeException('Basket is empty');
            }

            $user = $this->getUser();
            if ($user === null) {
                throw new \RuntimeException('User not found');
            }

            // 2. Create context - ONLY DATA
            $context = new EventContext([
                'basket' => $basket,
                'user' => $user,
                'userId' => $user->getId(),
                'sessionId' => $this->getSessionId(),
                'shopId' => $this->getShopId(),
                'shopUrl' => $this->getShopUrl(),
                'captureMode' => $this->getCaptureMode(),
                'conditionTypes' => ['payment_authorized'],
            ]);

            // 3. Dispatch event - HANDLERS DO THE WORK
            $event = new StripeCheckoutSessionRequestEvent($context);
            $this->getEventDispatcher()->dispatch($event);

            // 4. Store in session
            if ($sessionId = $context->get('checkoutSessionId')) {
                $this->setSessionVariable('stripe_checkout_session_id', $sessionId);
            }
            if ($contractId = $context->get('contractId')) {
                $this->setSessionVariable('stripe_contract_id', $contractId);
            }

            // Debug: Log session creation details
            $publishableKey = $config->getPublishableKey();
            $secretKeyPrefix = substr($config->getToken(), 0, 12) . '...';

            Registry::getLogger()->info('Checkout session created', [
                'sessionId' => $context->get('checkoutSessionId'),
                'contractId' => $context->get('contractId'),
                'publishableKeyPrefix' => substr($publishableKey, 0, 12) . '...',
                'secretKeyPrefix' => $secretKeyPrefix,
                'isTestMode' => $config->isTestMode(),
                'keysValid' => $config->validateKeyPair(),
            ]);

            echo json_encode([
                'id' => $context->get('checkoutSessionId'),
                'url' => $context->get('checkoutUrl'), // Direct URL for redirect
                'contract_id' => $context->get('contractId'),
                // Debug info (remove in production)
                '_debug' => [
                    'pk_prefix' => substr($publishableKey, 0, 20),
                    'sk_prefix' => $secretKeyPrefix,
                    'testMode' => $config->isTestMode(),
                    'keysValid' => $config->validateKeyPair(),
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            $this->logError('createCheckoutSession failed', $e);
            echo json_encode(['error' => $e->getMessage()]);
        }

        $this->exitWithJson();
    }

    /**
     * Handle successful return from Stripe Checkout.
     *
     * Called when customer completes payment on Stripe hosted page.
     */
    public function checkoutSuccess(): string
    {
        // 1. Validate checkout session ID
        $sessionId = $this->getCheckoutSessionIdFromRequest();

        if ($sessionId === null) {
            $this->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // 2. Get contract_id and contract_token from URL (passed in return URL)
        $contractId = Registry::getRequest()->getRequestParameter('contract_id');
        $contractToken = Registry::getRequest()->getRequestParameter('contract_token');

        // 3. Create context with URL parameters
        $context = new EventContext([
            'checkoutSessionId' => $sessionId,
            'contract_id' => $contractId,
            'contract_token' => $contractToken,
            // Also pass session contract ID as fallback
            'contractId' => $this->getContractIdFromSession(),
        ]);

        // 4. Dispatch event - HANDLERS DO THE WORK
        $event = new StripeCheckoutReturnEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // 4. Process results
        $this->processContextResults($context);

        // Set order in session for thank you page
        if ($orderId = $context->get('orderId')) {
            $this->setSessionVariable('sess_challenge', $orderId);
            $this->clearStripeSessionVariables();
        }

        return $context->get('redirectTarget') ?? 'payment';
    }

    /**
     * Handle return from Stripe after Payment Element confirmation.
     *
     * Called when customer is redirected back after Stripe.confirmPayment().
     */
    public function stripeReturn(): string
    {
        // 1. Get payment intent from URL or session
        $paymentIntentId = $this->getPaymentIntentIdFromRequest()
            ?? $this->getSessionPaymentIntentId();

        if ($paymentIntentId === null) {
            $this->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // 2. Create context - ONLY DATA
        $context = new EventContext([
            'paymentIntentId' => $paymentIntentId,
            'redirectStatus' => $this->getRedirectStatusFromRequest(),
            'contractId' => $this->getContractIdFromSession(),
        ]);

        // 3. Dispatch event - HANDLERS DO THE WORK
        $event = new StripePaymentReturnEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // 4. Process results
        $this->processContextResults($context);

        return $context->get('redirectTarget') ?? 'payment';
    }

    // ==========================================
    // HELPER METHODS (Controller concerns only)
    // ==========================================

    /**
     * Process results from event context.
     * Handles session data, template params, and errors.
     */
    protected function processContextResults(EventContext $context): void
    {
        // Handle 3DS requirement
        if ($context->get('requires3DS')) {
            $this->addTplParam('stripe3DSRequired', true);
            $this->addTplParam('stripeClientSecret', $context->get('clientSecret'));
            $this->addTplParam('paymentIntentId', $context->get('paymentIntentId'));
        }

        // Handle errors
        $error = $context->get('error');
        if (is_string($error) && $error !== '') {
            $this->addErrorToDisplay($error);
        }

        // Handle order success
        if ($context->get('orderId') !== null) {
            $this->setSessionVariable('sess_challenge', $context->get('orderId'));
        }
    }

    protected function clearStripeSessionVariables(): void
    {
        $this->deleteSessionVariable('stripe_payment_intent_id');
        $this->deleteSessionVariable('stripe_client_secret');
        $this->deleteSessionVariable('stripe_checkout_session_id');
        $this->deleteSessionVariable('stripe_contract_id');
    }

    // ==========================================
    // DATA ACCESSORS (Extracted for testing)
    // ==========================================

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->getServiceFromContainer(EventDispatcherInterface::class);
    }

    protected function getPaymentIntentIdFromRequest(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('payment_intent_id')
            ?? Registry::getRequest()->getRequestParameter('payment_intent');
        return is_string($value) ? $value : null;
    }

    protected function getSessionPaymentIntentId(): ?string
    {
        $value = Registry::getSession()->getVariable('stripe_payment_intent_id');
        return is_string($value) ? $value : null;
    }

    protected function getCheckoutSessionIdFromRequest(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('session_id');
        return is_string($value) ? $value : null;
    }

    protected function getRedirectStatusFromRequest(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('redirect_status');
        return is_string($value) ? $value : null;
    }

    protected function getContractIdFromSession(): ?string
    {
        $value = Registry::getSession()->getVariable('stripe_contract_id');
        return is_string($value) ? $value : null;
    }

    protected function getSessionId(): string
    {
        return Registry::getSession()->getId();
    }

    protected function getShopId(): int
    {
        return (int) Registry::getConfig()->getShopId();
    }

    protected function getShopUrl(): string
    {
        return Registry::getConfig()->getShopUrl();
    }

    /**
     * Get capture mode from module configuration.
     *
     * Uses ModuleConfigurationService to determine if automatic or manual capture
     * should be used. Request parameter can override for testing purposes.
     */
    protected function getCaptureMode(): string
    {
        // Allow override from request (for testing)
        $override = Registry::getRequest()->getRequestParameter('capture_mode_override');
        if (is_string($override) && in_array($override, ['automatic', 'manual'], true)) {
            return $override;
        }

        // Get from module configuration
        $config = $this->getServiceFromContainer(
            \OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService::class
        );

        return $config->getStripeCaptureMethod();
    }

    public function getUser()
    {
        $basket = $this->getBasketFromSession();
        return $basket?->getBasketUser();
    }

    protected function setSessionVariable(string $key, mixed $value): void
    {
        Registry::getSession()->setVariable($key, $value);
    }

    protected function deleteSessionVariable(string $key): void
    {
        Registry::getSession()->deleteVariable($key);
    }

    public function addTplParam($name, $value): void
    {
        // This method exists in parent OXID controller
        /** @phpstan-ignore-next-line */
        parent::addTplParam($name, $value);
    }

    protected function logError(string $message, \Throwable $e): void
    {
        Registry::getLogger()->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    protected function exitWithJson(): void
    {
        exit;
    }
}
