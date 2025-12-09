<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ReturnSecurityValidatorInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutReturnServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\DeliveryAddressHashServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles return from Stripe Checkout page.
 *
 * Sprint 21: Refactored to delegate validation to CheckoutReturnService (SRP).
 * Sprint 22: EventDispatcher now injected via constructor (no ContainerFactory).
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

    public function __construct(
        private readonly CheckoutReturnServiceInterface $checkoutReturnService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ReturnSecurityValidatorInterface $securityValidator,
        private readonly DeliveryAddressHashServiceInterface $deliveryAddressHashService,
        private readonly EventDispatcherInterface $eventDispatcher,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutReturnEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutReturnEvent) {
            return;
        }

        $context = $event->getContext();

        // Step 1: Extract and validate parameters
        $sessionId = $event->getCheckoutSessionId();
        if ($sessionId === null) {
            $this->setError($context, 'Checkout session ID is missing');
            return;
        }

        $contractId = $this->getStringFromContext($context, 'contract_id');
        $contractToken = $this->getStringFromContext($context, 'contract_token');

        if ($contractId === null || $contractToken === null) {
            $this->setError($context, 'Contract ID or token is missing');
            return;
        }

        // Step 2: Delegate validation to service
        $result = $this->checkoutReturnService->validateReturn($sessionId, $contractId, $contractToken);
        if (!$result->isSuccessful()) {
            $this->setError($context, $result->getErrorMessage() ?? 'Validation failed', $result->getErrorCode());
            return;
        }

        // Step 3: Load and validate contract
        $contract = $this->loadContract($contractId, $context);
        if ($contract === null) {
            return;
        }

        // Step 4: Perform security validation
        if (!$this->validateSecurity($contract, $contractId, $context)) {
            return;
        }

        // Step 5: Restore session state and dispatch event
        $this->restoreDeliveryAddressHash($contract, $context);
        $this->dispatchPaymentEvent($result, $context);
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
        $session = Registry::getSession();

        $deliveryHash = $contract->getMetadata('delivery_address_hash');
        if ($deliveryHash !== null && is_string($deliveryHash)) {
            $this->deliveryAddressHashService->restoreHashForValidation($deliveryHash);
            $session->setVariable('sDelAddrMD5', $deliveryHash);
        }

        $deliveryId = $contract->getMetadata('delivery_address_id');
        if ($deliveryId !== null && is_string($deliveryId)) {
            $session->setVariable('deladrid', $deliveryId);
            $context->set('restoredDeliveryAddressId', $deliveryId);
        }
    }

    private function dispatchPaymentEvent(
        \OxidSolutionCatalysts\Payments\Stripe\DTO\CheckoutReturnResult $result,
        EventContext $context
    ): void {
        $paymentIntentId = $result->getPaymentIntentId() ?? '';
        $amount = $result->getAmount() ?? 0;
        $currency = $result->getCurrency() ?? 'EUR';

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

        $this->eventDispatcher->dispatch($event);

        if ($context->get('orderId') !== null) {
            $context->set('redirectTarget', 'thankyou');
        }
    }
}
