<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Eshop\Application\Controller\OrderController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\Payments\Stripe\Traits\ServiceContainer;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\ContractTokenService;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

/**
 * Thin Stripe Order Controller.
 *
 * This controller is THIN - it only:
 * 1. Validates input
 * 2. Creates EventContext with data
 * 3. Dispatches appropriate event
 * 4. Returns result from context
 *
 * ALL business logic is in event handlers.
 * Request/session/config access is delegated to ControllerRequestHelper.
 *
 * Sprint 71: Extracted accessor methods to ControllerRequestHelper (PHPMD compliance).
 *
 * @since 2.0.0
 */
class StripeOrderController extends OrderController
{
    use ServiceContainer;

    private ?ControllerRequestHelper $requestHelper = null;

    /**
     * Execute Stripe payment via Payment Element flow.
     *
     * Called when customer submits payment form with Payment Element.
     */
    public function executeStripePayment(): string
    {
        $helper = $this->getRequestHelper();

        if (!$helper->validateSessionChallenge()) {
            $helper->addErrorToDisplay('Session expired. Please reload the page.');
            return 'payment';
        }

        // 1. Validate
        $basket = $helper->getBasketFromSession();
        if ($basket->getProductsCount() === 0) {
            $helper->addErrorToDisplay('Basket is empty');
            return 'basket';
        }

        $paymentIntentId = $helper->getPaymentIntentIdFromRequest()
            ?? $helper->getSessionPaymentIntentId();

        if ($paymentIntentId === null) {
            $helper->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // 2. Create context - ONLY DATA, NO LOGIC
        $context = new EventContext([
            'basket' => $basket,
            'user' => $basket->getBasketUser(),
            'userId' => $basket->getBasketUser()->getId(),
            'sessionId' => $helper->getSessionId(),
            'paymentId' => $basket->getPaymentId(),
            'paymentIntentId' => $paymentIntentId,
            'contractId' => $helper->getContractIdFromSession(),
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
        $helper = $this->getRequestHelper();
        header('Content-Type: application/json');

        if (!$helper->validateSessionChallenge()) {
            http_response_code(403);
            echo json_encode(['error' => 'Session expired. Please reload the page.']);
            $this->exitWithJson();
            return;
        }

        try {
            // 0. Validate API key configuration
            $validator = $this->getServiceFromContainer(ConfigurationValidatorInterface::class);
            $keyValidationError = $validator->getKeyValidationError();
            if ($keyValidationError !== null) {
                throw new \RuntimeException('Stripe configuration error: ' . $keyValidationError);
            }

            // 1. Validate
            $basket = $helper->getBasketFromSession();
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
                'paymentId' => StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
                'sessionId' => $helper->getSessionId(),
                'shopId' => $helper->getShopId(),
                'shopUrl' => $helper->getShopUrl(),
                'captureMode' => $helper->getCaptureMode(),
                'conditionTypes' => ['payment_authorized'],
            ]);

            // 3. Dispatch event - HANDLERS DO THE WORK
            $event = new StripeCheckoutSessionRequestEvent($context);
            $this->getEventDispatcher()->dispatch($event);

            // 4. Store in session
            if ($sessionId = $context->get('checkoutSessionId')) {
                $helper->setSessionVariable('stripe_checkout_session_id', $sessionId);
            }
            if ($contractId = $context->get('contractId')) {
                $helper->setSessionVariable('stripe_contract_id', $contractId);
            }

            echo json_encode([
                'id' => $context->get('checkoutSessionId'),
                'url' => $context->get('checkoutUrl'),
                'contract_id' => $context->get('contractId'),
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            $helper->logError('createCheckoutSession failed', $e);
            echo json_encode(['error' => 'Payment processing failed. Please try again.']);
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
        $helper = $this->getRequestHelper();

        // 1. Validate checkout session ID
        $sessionId = $helper->getCheckoutSessionIdFromRequest();

        if ($sessionId === null) {
            $helper->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // 2. Get contract_id and contract_token from URL (passed in return URL)
        $contractId = $helper->getContractIdFromRequest();
        $contractToken = $helper->getContractTokenFromRequest();

        // 3. Sprint 67a (H3): Validate contract token BEFORE any business logic
        if (!is_string($contractId) || !is_string($contractToken)) {
            $helper->addErrorToDisplay('Payment verification failed');
            return 'payment';
        }

        if (!$helper->validateContractToken($contractId, $contractToken)) {
            $helper->addErrorToDisplay('Payment verification failed');
            return 'payment';
        }

        // 4. Validate contract_id from URL matches session
        $sessionContractId = $helper->getContractIdFromSession();
        if (is_string($sessionContractId) && $contractId !== $sessionContractId) {
            $helper->addErrorToDisplay('Payment verification failed');
            return 'payment';
        }

        // 5. Create context with validated URL parameters
        $context = new EventContext([
            'checkoutSessionId' => $sessionId,
            'contract_id' => $contractId,
            'contract_token' => $contractToken,
            'contractId' => $sessionContractId,
        ]);

        // 6. Dispatch event - HANDLERS DO THE WORK
        $event = new StripeCheckoutReturnEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // 7. Process results
        $this->processContextResults($context);

        // Set order in session for thank you page
        if ($orderId = $context->get('orderId')) {
            $helper->setSessionVariable('sess_challenge', $orderId);
            $helper->clearStripeSessionVariables();
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
        $helper = $this->getRequestHelper();

        // 1. Get payment intent from URL or session
        $paymentIntentId = $helper->getPaymentIntentIdFromRequest()
            ?? $helper->getSessionPaymentIntentId();

        if ($paymentIntentId === null) {
            $helper->addErrorToDisplay('Payment information missing');
            return 'payment';
        }

        // 2. Create context - ONLY DATA
        $context = new EventContext([
            'paymentIntentId' => $paymentIntentId,
            'redirectStatus' => $helper->getRedirectStatusFromRequest(),
            'contractId' => $helper->getContractIdFromSession(),
        ]);

        // 3. Dispatch event - HANDLERS DO THE WORK
        $event = new StripePaymentReturnEvent($context);
        $this->getEventDispatcher()->dispatch($event);

        // 4. Process results
        $this->processContextResults($context);

        return $context->get('redirectTarget') ?? 'payment';
    }

    // ==========================================
    // CONTROLLER-SPECIFIC METHODS
    // ==========================================

    /**
     * Process results from event context.
     * Handles template params and errors.
     */
    protected function processContextResults(EventContext $context): void
    {
        $helper = $this->getRequestHelper();

        // Handle 3DS requirement
        if ($context->get('requires3DS')) {
            $this->addTplParam('stripe3DSRequired', true);
            $this->addTplParam('stripeClientSecret', $context->get('clientSecret'));
            $this->addTplParam('paymentIntentId', $context->get('paymentIntentId'));
        }

        // Handle errors
        $error = $context->get('error');
        if (is_string($error) && $error !== '') {
            $helper->addErrorToDisplay($error);
        }

        // Handle order success
        if ($context->get('orderId') !== null) {
            $helper->setSessionVariable('sess_challenge', $context->get('orderId'));
        }
    }

    /**
     * Get the current user from the basket session.
     *
     * @return \OxidEsales\Eshop\Application\Model\User|null
     */
    public function getUser(): ?\OxidEsales\Eshop\Application\Model\User
    {
        return $this->getRequestHelper()->getBasketFromSession()->getBasketUser();
    }

    public function addTplParam($name, $value): void
    {
        /** @phpstan-ignore-next-line */
        parent::addTplParam($name, $value);
    }

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->getServiceFromContainer(EventDispatcherInterface::class);
    }

    protected function exitWithJson(): void
    {
        exit;
    }

    protected function getRequestHelper(): ControllerRequestHelper
    {
        if ($this->requestHelper === null) {
            $this->requestHelper = new ControllerRequestHelper(
                $this->getServiceFromContainer(ContractTokenService::class),
                $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class)
            );
        }
        return $this->requestHelper;
    }
}
