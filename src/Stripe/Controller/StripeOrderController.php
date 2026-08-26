<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller;

use OxidEsales\Eshop\Core\Registry;
use RuntimeException;
use Throwable;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\Controller\CheckoutReturnResponder;
use OxidEsales\PaymentBase\Controller\HandlesCheckoutReturn;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\ResponseHeaders;
use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnInputs;
use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnInputsResolver;
use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnRejection;
use OxidEsales\Payments\Stripe\Service\Return\StripeReturnResolver;
use OxidEsales\Payments\Stripe\Traits\ServiceContainer;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripePaymentReturnEvent;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Stripe\Service\BasketBuyabilityValidator;
use OxidEsales\Payments\Stripe\Service\BuyabilityFailure;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\ContractTokenService;
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use OxidEsales\Payments\Stripe\Service\LanguageResolverInterface;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\OxidLanguageTranslator;
use OxidEsales\Payments\Stripe\Service\OxidUserFieldReader;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\Payments\Stripe\Service\UserDataValidationMessageFormatter;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;

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
class StripeOrderController extends StripeOrderController_parent
{
    use ServiceContainer;
    use HandlesCheckoutReturn;

    protected function resolveCheckoutReturnResponder(): CheckoutReturnResponder
    {
        return $this->getServiceFromContainer(CheckoutReturnResponder::class);
    }

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
        } catch (Throwable $e) {
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
     * Delegates to focused helpers to stay within the 25-line budget.
     */
    public function createCheckoutSession(): void
    {

        $helper = $this->getRequestHelper();
        $this->sendSecureJsonHeaders();

        if (!$helper->validateSessionChallenge()) {
            $this->setHttpResponseCode(403);
            echo json_encode(['error' => 'Session expired. Please reload the page.']);
            $this->exitWithJson();
            return;
        }

        if (!$this->ensureAgbAccepted($helper)) {
            return;
        }

        try {
            $this->cleanupPreviousCheckoutAttempt($helper);
            $context = $this->buildCheckoutEventContext($helper);
            $this->dispatchSessionEvent($helper, $context);
            $this->emitSessionResponse($context);
        } catch (UserDataValidationException $e) {
            $this->emitUserDataValidationErrors($e->getFailures());
        } catch (BasketNotBuyableException $e) {
            $this->emitBuyabilityErrors($e->getFailures());
        } catch (Throwable $e) {
            $this->setHttpResponseCode(500);
            $helper->logError('createCheckoutSession failed', $e);
            echo json_encode(['error' => 'Payment processing failed. Please try again.']);
        }

        $this->exitWithJson();
    }

    /**
     * Validate API key, basket, and user before creating the checkout context.
     *
     * @throws RuntimeException on any precondition failure
     */
    private function validateCheckoutPreconditions(ControllerRequestHelper $helper): void
    {
        $validator = $this->getServiceFromContainer(ConfigurationValidatorInterface::class);
        $keyValidationError = $validator->getKeyValidationError();
        if ($keyValidationError !== null) {
            throw new RuntimeException('Stripe configuration error: ' . $keyValidationError);
        }

        $basket = $helper->getBasketFromSession();
        if ($basket->getProductsCount() === 0) {
            throw new RuntimeException('Basket is empty');
        }

        if ($this->getUser() === null) {
            throw new RuntimeException('User not found');
        }
    }

    /**
     * Build the EventContext for the checkout session request.
     *
     * Validates preconditions first, then assembles context data.
     *
     * @throws RuntimeException when API key, basket, or user is invalid
     * @throws UserDataValidationException when user field validation fails
     * @throws BasketNotBuyableException when a cart item is no longer buyable
     */
    private function buildCheckoutEventContext(ControllerRequestHelper $helper): EventContext
    {
        $this->validateCheckoutPreconditions($helper);

        $basket = $helper->getBasketFromSession();
        $user   = $this->getUser();

        if ($user === null) {
            throw new RuntimeException('User not found');
        }

        $this->validateUserData($user);

        $buyabilityFailures = $this->getBasketBuyabilityValidator()->validate($basket);
        if ($buyabilityFailures !== []) {
            throw new BasketNotBuyableException($buyabilityFailures);
        }

        return new EventContext([
            'basket'         => $basket,
            'user'           => $user,
            'userId'         => $user->getId(),
            'paymentId'      => StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            'sessionId'      => $helper->getSessionId(),
            'shopId'         => $helper->getShopId(),
            'shopUrl'        => $helper->getShopUrl(),
            'languageId'     => $helper->getActiveLanguageId(),
            'captureMode'    => $helper->getCaptureMode(),
            'conditionTypes' => ['payment_authorized'],
        ]);
    }

    /**
     * Set skip-addr-check flag, dispatch the checkout session event, and persist
     * session IDs (checkoutSessionId, contractId) returned in the context.
     *
     * The skip-addr-check flag must be set BEFORE dispatch so that
     * Order::validateDeliveryAddress() (called during finalizeOrder inside the
     * event handlers) recognises this as the legitimate return flow.
     * It is cleared by clearStripeSessionVariables() on completion or cancellation.
     */
    private function dispatchSessionEvent(ControllerRequestHelper $helper, EventContext $context): void
    {
        $helper->setSessionVariable(ControllerRequestHelper::SESSION_SKIP_ADDR_CHECK, true);
        $event = new StripeCheckoutSessionRequestEvent($context);

        try {
            $this->getEventDispatcher()->dispatch($event);
        } catch (ShopOrderException $e) {
            // Race window: an item became unbuyable between the pre-dispatch
            // check and finalizeOrder. Translate to the domain exception so the
            // existing 409 path handles it; anything else stays a 500.
            if ($e->getErrorCode() === 'article_not_buyable') {
                throw new BasketNotBuyableException([
                    new BuyabilityFailure('', '', BasketBuyabilityValidator::REASON_NOT_BUYABLE),
                ]);
            }
            throw $e;
        }

        if ($sessionId = $context->get('checkoutSessionId')) {
            $helper->setSessionVariable('stripe_checkout_session_id', $sessionId);
        }
        if ($contractId = $context->get('contractId')) {
            $helper->setSessionVariable('stripe_contract_id', $contractId);
        }
    }

    /**
     * Write the JSON body with checkoutSessionId, checkoutUrl, and contractId.
     */
    private function emitSessionResponse(EventContext $context): void
    {
        echo json_encode([
            'id'            => $context->get('checkoutSessionId'),
            'url'           => $context->get('checkoutUrl'),
            'contract_id'   => $context->get('contractId'),
            'client_secret' => $context->get('clientSecret'),
            'render_mode'   => $context->get('renderMode'),
        ]);
    }

    /**
     * Guard: reject the request when blConfirmAGB is active but the customer
     * has not submitted ord_agb=1. Must be called after session validation and
     * before cleanupPreviousCheckoutAttempt() to avoid side effects on
     * invalid requests.
     *
     * Returns true when the request may proceed; false when rejected (HTTP 400
     * already written and exitWithJson() called).
     */
    private function ensureAgbAccepted(ControllerRequestHelper $helper): bool
    {
        if (!$helper->isAgbConfirmationRequired()) {
            return true;
        }

        if ($helper->getAgbAcceptedFromRequest()) {
            $helper->persistAgbConsent();
            return true;
        }

        $this->setHttpResponseCode(400);
        echo json_encode(['error' => 'You must accept the Terms and Conditions to continue.']);
        $this->exitWithJson();
        return false;
    }

    /**
     * Handle successful return from Stripe Checkout.
     *
     * Called when customer completes payment on Stripe hosted page.
     */
    public function checkoutSuccess(): string
    {
        $helper = $this->getRequestHelper();
        $inputs = $this->resolveReturnInputs($helper);
        if ($inputs instanceof CheckoutReturnRejection) {
            return $this->rejectReturn($helper, $inputs);
        }

        $contract = $this->findReturnContract($inputs->contractId);
        if ($contract === null) {
            return $this->rejectReturn(
                $helper,
                CheckoutReturnRejection::ContractNotFound,
                ['contractId' => $inputs->contractId]
            );
        }

        /** @var \OxidEsales\PaymentBase\Contract\PaymentContractInterface $contract */
        $ownershipRejection = (new CheckoutReturnInputsResolver())
            ->checkOwnership($contract->getUserId(), $this->readCurrentUserId());
        if ($ownershipRejection !== null) {
            return $this->rejectReturn(
                $helper,
                $ownershipRejection,
                ['contractId' => $inputs->contractId, 'contractUserId' => $contract->getUserId()]
            );
        }

        $orderId = $this->dispatchCheckoutReturn(
            providerName: StripeDefinitions::PROVIDER,
            contract: $contract,
            resolver: $this->getServiceFromContainer(StripeReturnResolver::class),
            extraContextKeys: [
                'checkoutSessionId' => $inputs->sessionId,
                'contract_token' => $inputs->contractToken,
            ],
        );

        if ($orderId === null) {
            return $this->rejectReturn(
                $helper,
                CheckoutReturnRejection::NoOrderCreated,
                ['contractId' => $inputs->contractId, 'checkoutSessionId' => $inputs->sessionId]
            );
        }

        $helper->clearStripeSessionVariables();
        $helper->clearAgbConsent();
        return 'thankyou';
    }

    private function resolveReturnInputs(
        ControllerRequestHelper $helper
    ): CheckoutReturnInputs|CheckoutReturnRejection {
        $contractId = $helper->getContractIdFromRequest();
        $contractToken = $helper->getContractTokenFromRequest();

        return (new CheckoutReturnInputsResolver())->resolve(
            sessionId: $helper->getCheckoutSessionIdFromRequest(),
            contractId: $contractId,
            contractToken: $contractToken,
            contractTokenValid: $helper->validateContractToken($contractId, $contractToken)
        );
    }

    /**
     * Turns the customer away with a message that gives nothing away, and
     * records in the log which check refused — without the log line a support
     * request about "Payment verification failed" is unanswerable.
     *
     * @param array<string, mixed> $context
     */
    private function rejectReturn(
        ControllerRequestHelper $helper,
        CheckoutReturnRejection $rejection,
        array $context = []
    ): string {
        $helper->logReturnRejected($rejection, $context);
        $helper->addErrorToDisplay($rejection->customerMessage());

        return 'payment';
    }

    /**
     * The shopper this request belongs to, or null when the session has none.
     */
    private function readCurrentUserId(): ?string
    {
        $user = $this->getUser();
        if (!is_object($user)) {
            return null;
        }

        $userId = (string) $user->getId();

        return $userId === '' ? null : $userId;
    }

    private function findReturnContract(string $contractId): ?object
    {
        /** @var ContractRepositoryInterface $repo */
        $repo = $this->getServiceFromContainer(ContractRepositoryInterface::class);

        return $repo->findById($contractId);
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
            } catch (Throwable $e) {
                $helper->logError('checkoutCancel cleanup failed', $e);
            }
            $helper->clearStripeSessionVariables();
        }

        // Sprint 88: Generate new sess_challenge so the next finalizeOrder()
        // creates a fresh order row instead of hitting checkOrderExist() for
        // the storno'd order that remains in the database.
        $helper->setSessionVariable('sess_challenge', $this->generateNewSessChallenge());

        // Sprint 128: Clear consent — customer cancelled, so a new attempt
        // must require fresh consent.
        $helper->clearAgbConsent();

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

    /**
     * Return true when the customer gave AGB consent in a prior checkout
     * submission that was persisted to the session.
     *
     * Consumed by the template via oView.isPriorAgbConsent() to render
     * data-agb-validation-prior-consent-value so the JS controller can
     * re-check #checkAgbTop on a fresh-load return from the payment page.
     *
     * @since 2.0.0 Sprint 128
     */
    public function isPriorAgbConsent(): bool
    {
        return $this->getRequestHelper()->hasPersistedAgbConsent();
    }

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->getServiceFromContainer(EventDispatcherInterface::class);
    }

    protected function exitWithJson(): void
    {
        exit;
    }

    protected function setHttpResponseCode(int $code): void
    {
        http_response_code($code);
    }

    /**
     * Header sink seam (real emission); overridden in tests to capture.
     */
    protected function emitHeader(string $header): void
    {
        header($header);
    }

    /**
     * Content-Type + security headers for this JSON endpoint's response.
     */
    protected function sendSecureJsonHeaders(): void
    {
        $this->emitHeader('Content-Type: application/json');
        ResponseHeaders::applySecurity(function (string $header): void {
            $this->emitHeader($header);
        });
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
                $this->getServiceFromContainer(ModuleConfigurationServiceInterface::class),
                $this->getServiceFromContainer(LanguageResolverInterface::class)
            );
        }
        return $this->requestHelper;
    }

    /**
     * DI seam — resolves UserDataValidatorInterface from the container.
     *
     * Overridden in testable subclasses to inject a stub without booting the
     * DI container. Matches the getEventDispatcher() seam pattern.
     */
    protected function getUserDataValidator(): UserDataValidatorInterface
    {
        return $this->getServiceFromContainer(UserDataValidatorInterface::class);
    }

    /**
     * DI seam — the buyability validator has no dependencies, so it is created
     * directly rather than resolved from the container. Overridden in testable
     * subclasses to inject a stub.
     */
    protected function getBasketBuyabilityValidator(): BasketBuyabilityValidator
    {
        return new BasketBuyabilityValidator();
    }

    /**
     * DI seam — the translator is a dependency-free Registry wrapper and is
     * registered as a private service (injection-only), so it is created
     * directly rather than fetched from the container. Overridden in testable
     * subclasses to inject a stub.
     */
    protected function getLanguageTranslator(): LanguageTranslatorInterface
    {
        return new OxidLanguageTranslator();
    }

    /**
     * Validates user billing and delivery fields via the UserDataValidator.
     *
     * Throws UserDataValidationException when one or more fields fail
     * character-level validation. The exception carries the structured failure
     * list so createCheckoutSession() can emit a 422 JSON response without
     * repeating the validation call.
     *
     * Sprint 119 Phase C (STRP-129).
     *
     * @throws UserDataValidationException when any field fails validation
     */
    private function validateUserData(\OxidEsales\Eshop\Application\Model\User $user): void
    {
        $reader   = new OxidUserFieldReader($user);
        $failures = $this->getUserDataValidator()->validateForUser($reader);

        if ($failures === []) {
            return;
        }

        throw new UserDataValidationException($failures);
    }

    /**
     * Emits a 422 Unprocessable Entity JSON response with the structured
     * field-validation failures.
     *
     * Response shape:
     *   {"valid": false, "errors": [{"field": "…", "code": "…", "char": "…", "addressKind": "…", "message": "…"}]}
     *
     * Sprint 119 Phase C (STRP-129). Phase E: adds `message` via formatter.
     *
     * @param FieldValidationFailure[] $failures Non-empty list of field violations.
     */
    private function emitUserDataValidationErrors(array $failures): void
    {
        $this->setHttpResponseCode(422);

        $formatter = $this->getUserDataValidationMessageFormatter();
        $errors    = array_map(
            fn(FieldValidationFailure $f): array => [
                'field'       => $f->field,
                'code'        => $f->code,
                'char'        => $f->offendingChar,
                'addressKind' => $f->addressKind,
                'message'     => $formatter !== null
                    ? $formatter->format($f->field, $f->code, $f->offendingChar)
                    : null,
            ],
            $failures,
        );

        echo json_encode(['valid' => false, 'errors' => $errors]);
    }

    /**
     * Emits a 409 Conflict JSON response listing the cart items that are no
     * longer buyable. 409 (not 422) signals that the basket state changed under
     * the shopper. The `errors[]` shape matches the frontend contract used by
     * the user-data path, so the existing validation box renders it unchanged.
     *
     * Response shape:
     *   {"valid": false, "errors": [{"code": "article_not_buyable", "articleId": "…", "productTitle": "…", "message": "…"}]}
     *
     * Story 2 (unbuyable-article-checkout).
     *
     * @param BuyabilityFailure[] $failures Non-empty list of unbuyable items.
     */
    private function emitBuyabilityErrors(array $failures): void
    {
        $this->setHttpResponseCode(409);

        $translator = $this->getLanguageTranslator();
        $errors     = array_map(
            fn(BuyabilityFailure $f): array => [
                'code'         => 'article_not_buyable',
                'articleId'    => $f->articleId,
                'productTitle' => $f->productTitle,
                'message'      => $translator->translateString($f->reason),
            ],
            $failures,
        );

        echo json_encode(['valid' => false, 'errors' => $errors]);
    }

    /**
     * DI seam — resolves the message formatter from the container.
     *
     * Overridden in testable subclasses to inject a stub.
     * Returns null when the service is unavailable (formatter is optional).
     */
    protected function getUserDataValidationMessageFormatter(): ?MessageFormatterInterface
    {
        try {
            return $this->getServiceFromContainer(UserDataValidationMessageFormatter::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
