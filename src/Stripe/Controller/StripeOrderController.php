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
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;

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
     * Render the order confirmation page.
     *
     * STRP-105: Before rendering, detect if the user navigated back from
     * Stripe Checkout without completing payment. If a stale contract exists
     * in the session, clean it up — this releases vouchers that were marked
     * as "used" during early order creation so OXID's basket recalculation
     * won't invalidate them.
     *
     * @return string
     */
    public function render(): string
    {
        $this->cleanupStaleCheckoutOnRender();

        return parent::render();
    }

    /**
     * If the session holds a stripe_contract_id but the user is simply
     * viewing the order page (not submitting), the previous checkout attempt
     * is stale. Cancel the contract and delete the NOT_FINISHED order so
     * that vouchers are released before the basket is recalculated.
     *
     * @since 2.0.0 STRP-105
     */
    private function cleanupStaleCheckoutOnRender(): void
    {
        $helper = $this->getRequestHelper();
        $contractId = $helper->getContractIdFromSession();

        if ($contractId === null) {
            return;
        }

        try {
            $cleanupService = $this->getServiceFromContainer(RetryCleanupService::class);
            $cleanupService->cleanupPreviousAttempt($contractId);
        } catch (\Throwable $e) {
            Registry::getLogger()->error('STRP-105: Order page cleanup failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $helper->clearStripeSessionVariables();

        // Sprint 88: Generate new sess_challenge after storno cleanup
        $helper->setSessionVariable('sess_challenge', $this->generateNewSessChallenge());
    }

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
            // STRP-100: Clean up previous checkout attempt on retry
            $this->cleanupPreviousCheckoutAttempt($helper);

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
                'languageId' => $helper->getActiveLanguageId(),
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

    /**
     * Handle cancel/back-navigation from Stripe Checkout.
     *
     * When user cancels on Stripe hosted page, Stripe redirects here.
     * Cleans up the NOT_FINISHED order and cancels the contract,
     * then redirects to the payment page.
     *
     * @since 2.0.0 STRP-100
     */
    public function checkoutCancel(): string
    {
        $helper = $this->getRequestHelper();
        $contractId = $helper->getContractIdFromSession();

        if ($contractId !== null) {
            try {
                $cleanupService = $this->getServiceFromContainer(RetryCleanupService::class);
                $cleanupService->cleanupPreviousAttempt($contractId);
            } catch (\Throwable $e) {
                $helper->logError('checkoutCancel cleanup failed', $e);
            }
            $helper->clearStripeSessionVariables();
        }

        // Sprint 88: Generate new sess_challenge so the next finalizeOrder()
        // creates a fresh order row instead of hitting checkOrderExist() for
        // the storno'd order that remains in the database.
        $helper->setSessionVariable('sess_challenge', $this->generateNewSessChallenge());

        return 'payment';
    }

    // ==========================================
    // CONTROLLER-SPECIFIC METHODS
    // ==========================================

    /**
     * Clean up previous checkout attempt before creating a new session.
     *
     * If the session has a contractId from a previous attempt, cancels that contract
     * and deletes the NOT_FINISHED order. Otherwise falls back to userId lookup
     * (covers the case where user closed the tab and lost the session).
     *
     * @since 2.0.0 STRP-100
     */
    protected function cleanupPreviousCheckoutAttempt(ControllerRequestHelper $helper): void
    {
        $cleanupService = $this->getServiceFromContainer(RetryCleanupService::class);
        $previousContractId = $helper->getContractIdFromSession();

        if ($previousContractId !== null) {
            $cleanupService->cleanupPreviousAttempt($previousContractId);
            $helper->clearStripeSessionVariables();
            $helper->setSessionVariable('sess_challenge', $this->generateNewSessChallenge());
            return;
        }

        // Fallback: user closed tab, session lost — look up by userId
        $user = $this->getUser();
        if ($user !== null) {
            $cleanupService->cleanupForUser((string) $user->getId());
        }
    }

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
        /** @var \OxidEsales\Eshop\Application\Model\User|false $user OXID returns false when not logged in */
        $user = $this->getRequestHelper()->getBasketFromSession()->getBasketUser();

        return $user instanceof \OxidEsales\Eshop\Application\Model\User ? $user : null;
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

    protected function generateNewSessChallenge(): string
    {
        return Registry::getUtilsObject()->generateUId();
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
