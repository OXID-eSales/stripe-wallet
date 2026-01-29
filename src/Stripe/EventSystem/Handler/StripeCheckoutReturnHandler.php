<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ReturnSecurityValidatorInterface;
use OxidEsales\PaymentComponent\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentComponent\Service\DeliveryAddressHashServiceInterface;
use OxidEsales\Payments\Stripe\Service\CheckoutReturnServiceInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles return from Stripe Checkout page.
 *
 * Sprint 21: Refactored to delegate validation to CheckoutReturnService (SRP).
 * Sprint 22: EventDispatcher now injected via constructor (no ContainerFactory).
 * Sprint 25: Added event file logger for debugging.
 *
 * Handler responsibilities (ONLY):
 * 1. Extract parameters from event
 * 2. Delegate validation to CheckoutReturnService
 * 3. Load contract and validate security
 * 4. Restore session state
 * 5. Dispatch PaymentAuthorizedEvent
 *
 * @since 2.0.0
 */
class StripeCheckoutReturnHandler implements HandlerInterface
{
    private const SECURITY_WARNING_THRESHOLD = 75;

    private LoggerInterface $logger;
    private ?FileLoggerInterface $eventLogger;

    public function __construct(
        private readonly CheckoutReturnServiceInterface $checkoutReturnService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ReturnSecurityValidatorInterface $securityValidator,
        private readonly DeliveryAddressHashServiceInterface $deliveryAddressHashService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly SessionAdapterInterface $sessionAdapter,
        ?LoggerInterface $logger = null,
        ?FileLoggerInterface $eventLogger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->eventLogger = $eventLogger;
    }

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutReturnEvent::class;
    }

    public function handle(object $event): void
    {
        $this->logEvent('StripeCheckoutReturnHandler::handle() START');

        if (!$event instanceof StripeCheckoutReturnEvent) {
            $this->logEvent('StripeCheckoutReturnHandler: Wrong event type, skipping');
            return;
        }

        $context = $event->getContext();

        // Step 1: Extract and validate parameters
        $sessionId = $event->getCheckoutSessionId();
        $this->logEvent('Step 1: Extract parameters', [
            'sessionId' => $sessionId,
        ]);

        if ($sessionId === null) {
            $this->logEvent('ERROR: Checkout session ID is missing');
            $this->setError($context, 'Checkout session ID is missing');
            return;
        }

        $contractId = $this->getStringFromContext($context, 'contract_id');
        $contractToken = $this->getStringFromContext($context, 'contract_token');

        $this->logEvent('Step 1b: Contract params', [
            'contractId' => $contractId,
            'contractToken' => $contractToken ? 'present' : 'missing',
        ]);

        if ($contractId === null || $contractToken === null) {
            $this->logEvent('ERROR: Contract ID or token is missing');
            $this->setError($context, 'Contract ID or token is missing');
            return;
        }

        // Step 2: Delegate validation to service
        $this->logEvent('Step 2: Validating return with service...');
        $result = $this->checkoutReturnService->validateReturn($sessionId, $contractId, $contractToken);
        $this->logEvent('Step 2b: Validation result', [
            'successful' => $result->isSuccessful(),
            'errorMessage' => $result->getErrorMessage(),
            'paymentStatus' => $result->getPaymentStatus(),
            'paymentIntentStatus' => $result->getPaymentIntentStatus(),
        ]);

        if (!$result->isSuccessful()) {
            $this->logEvent('ERROR: Validation failed: ' . ($result->getErrorMessage() ?? 'unknown'));
            $this->setError($context, $result->getErrorMessage() ?? 'Validation failed', $result->getErrorCode());
            return;
        }

        // Step 3: Load and validate contract
        $this->logEvent('Step 3: Loading contract...');
        $contract = $this->loadContract($contractId, $context);
        if ($contract === null) {
            $this->logEvent('ERROR: Contract not found');
            return;
        }
        $this->logEvent('Step 3b: Contract loaded', [
            'state' => $contract->getStateValue(),
            'userId' => $contract->getUserId(),
        ]);

        // Step 4: Perform security validation
        $this->logEvent('Step 4: Security validation...');
        if (!$this->validateSecurity($contract, $contractId, $context)) {
            $this->logEvent('ERROR: Security validation failed');
            return;
        }
        $this->logEvent('Step 4b: Security validation passed');

        // Step 5: Restore session state
        $this->logEvent('Step 5: Restoring session state...');
        $this->restoreDeliveryAddressHash($contract, $context);

        // Step 6: Handle based on PaymentIntent status
        $this->logEvent('Step 6: Handle payment status', [
            'isRequiresCapture' => $result->isRequiresCapture(),
            'paymentIntentStatus' => $result->getPaymentIntentStatus(),
        ]);

        if ($result->isRequiresCapture()) {
            // Manual capture mode: transition to AUTHORIZED, wait for capture
            $this->logEvent('Step 6a: Manual capture mode - calling handleRequiresCaptureStatus');
            $this->handleRequiresCaptureStatus($contract, $result, $context);
        } else {
            // Automatic capture or succeeded: dispatch normal payment flow
            $this->logEvent('Step 6b: Automatic capture - calling dispatchPaymentEvent');
            $this->dispatchPaymentEvent($result, $context);
        }

        $this->logEvent('StripeCheckoutReturnHandler::handle() END', [
            'redirectTarget' => $context->get('redirectTarget'),
            'orderId' => $context->get('orderId'),
            'error' => $context->get('error'),
        ]);
    }

    private function getStringFromContext(EventContext $context, string $key): ?string
    {
        $value = $context->get($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function setError(EventContext $context, string $message, ?string $code = null): void
    {
        $context->set('error', $message);
        if ($code !== null) {
            $context->set('errorCode', $code);
        }
        $context->set('redirectTarget', 'payment');
    }

    private function loadContract(string $contractId, EventContext $context): ?PaymentContractInterface
    {
        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            $this->setError($context, 'Contract not found: ' . $contractId);
            return null;
        }

        $context->setContract($contract);
        $context->set('contractId', $contractId);

        return $contract;
    }

    private function validateSecurity(
        PaymentContractInterface $contract,
        string $contractId,
        EventContext $context
    ): bool {
        $currentContext = $this->buildSecurityContext();
        $securityResult = $this->securityValidator->validateReturn($contract, $currentContext);

        if ($securityResult->getScore() < self::SECURITY_WARNING_THRESHOLD) {
            $this->logger->warning('Suspicious return from Stripe', [
                'contract_id' => $contractId,
                'score' => $securityResult->getScore(),
                'warnings' => $securityResult->getWarnings(),
            ]);
        }

        if (!$securityResult->isAllowed()) {
            $this->logger->error('Security check blocked return', [
                'contract_id' => $contractId,
                'score' => $securityResult->getScore(),
                'warnings' => $securityResult->getWarnings(),
            ]);
            $this->setError($context, 'Security validation failed', 'security_check_failed');
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSecurityContext(): array
    {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'country' => null,
        ];
    }

    private function restoreDeliveryAddressHash(PaymentContractInterface $contract, EventContext $context): void
    {
        $deliveryHash = $contract->getMetadata('delivery_address_hash');
        if ($deliveryHash !== null && is_string($deliveryHash)) {
            $this->deliveryAddressHashService->restoreHashForValidation($deliveryHash);
            $this->sessionAdapter->setVariable('sDelAddrMD5', $deliveryHash);
        }

        $deliveryId = $contract->getMetadata('delivery_address_id');
        if ($deliveryId !== null && is_string($deliveryId)) {
            $this->sessionAdapter->setVariable('deladrid', $deliveryId);
            $context->set('restoredDeliveryAddressId', $deliveryId);
        }
    }

    private function dispatchPaymentEvent(
        \OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult $result,
        EventContext $context
    ): void {
        $paymentIntentId = $result->getPaymentIntentId() ?? '';
        $amount = $result->getAmount() ?? 0;
        $currency = $result->getCurrency() ?? 'EUR';

        $this->logEvent('dispatchPaymentEvent: Creating PaymentAuthorizedEvent', [
            'paymentIntentId' => $paymentIntentId,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        $context->set('paymentIntentId', $paymentIntentId);
        $context->set('amount', $amount);
        $context->set('currency', $currency);

        $event = new PaymentAuthorizedEvent(
            context: $context,
            authorizationId: $paymentIntentId,
            providerOrderId: $paymentIntentId,
            amount: $amount,
            currency: $currency
        );

        $this->logEvent('dispatchPaymentEvent: Dispatching PaymentAuthorizedEvent...');
        $this->eventDispatcher->dispatch($event);
        $this->logEvent('dispatchPaymentEvent: PaymentAuthorizedEvent dispatched', [
            'orderId' => $context->get('orderId'),
            'orderNumber' => $context->get('orderNumber'),
        ]);

        if ($context->get('orderId') !== null) {
            $this->logEvent('dispatchPaymentEvent: Order created, setting redirectTarget=thankyou');
            $context->set('redirectTarget', 'thankyou');
        } else {
            $this->logEvent('dispatchPaymentEvent: WARNING - orderId is NULL after dispatch!');
        }
    }

    /**
     * Handle PaymentIntent with requires_capture status (manual capture mode).
     *
     * When the PaymentIntent status is 'requires_capture', it means:
     * - The payment has been authorized but not yet captured
     * - We still need to create the order so thankyou page works
     * - The order will have OXPAID = NULL until capture
     * - Later, capture will update OXPAID timestamp
     *
     * Sprint 25: Fixed to dispatch PaymentAuthorizedEvent to create order.
     * OXID's thankyou page requires sess_challenge (order ID) to display.
     */
    private function handleRequiresCaptureStatus(
        PaymentContractInterface $contract,
        \OxidEsales\Payments\Stripe\Service\Result\CheckoutReturnResult $result,
        EventContext $context
    ): void {
        $paymentIntentId = $result->getPaymentIntentId() ?? '';
        $amount = $result->getAmount() ?? 0;
        $currency = $result->getCurrency() ?? 'EUR';

        $this->logEvent('handleRequiresCaptureStatus: Manual capture mode', [
            'contractId' => $contract->getId(),
            'paymentIntentId' => $paymentIntentId,
            'amount' => $amount,
        ]);

        $this->logger->info('Payment authorized, awaiting manual capture', [
            'contract_id' => $contract->getId(),
            'payment_intent_id' => $paymentIntentId,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        // Store PaymentIntent ID for later capture
        $contract->setMetadata('payment_intent_id', $paymentIntentId);

        // Update provider order ID if not already set
        if ($contract->getProviderOrderId() === null) {
            $contract->setProvider('stripe', $paymentIntentId);
        }
        $this->contractRepository->save($contract);

        // Set context values for downstream processing
        $context->set('paymentIntentId', $paymentIntentId);
        $context->set('amount', $amount);
        $context->set('currency', $currency);
        $context->set('paymentStatus', 'authorized');
        $context->set('requiresCapture', true);

        // Dispatch PaymentAuthorizedEvent to trigger order creation
        // This is needed because OXID's thankyou page requires an order ID
        $this->logEvent('handleRequiresCaptureStatus: Dispatching PaymentAuthorizedEvent for order creation');
        $event = new PaymentAuthorizedEvent(
            context: $context,
            authorizationId: $paymentIntentId,
            providerOrderId: $paymentIntentId,
            amount: $amount,
            currency: $currency
        );

        $this->eventDispatcher->dispatch($event);

        $this->logEvent('handleRequiresCaptureStatus: After dispatch', [
            'orderId' => $context->get('orderId'),
        ]);

        // Set redirect target
        if ($context->get('orderId') !== null) {
            $context->set('redirectTarget', 'thankyou');
        } else {
            $this->logEvent('handleRequiresCaptureStatus: WARNING - orderId is NULL, order creation may have failed');
        }
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
