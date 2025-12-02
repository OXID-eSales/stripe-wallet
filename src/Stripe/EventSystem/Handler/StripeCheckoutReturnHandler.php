<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\TokenServiceInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ReturnSecurityValidatorInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles return from Stripe Checkout page.
 *
 * This handler:
 * 1. Retrieves the Checkout Session from Stripe
 * 2. Verifies payment_status is 'paid'
 * 3. Loads the contract using contract_id from metadata
 * 4. Dispatches PaymentAuthorizedEvent to trigger condition fulfillment
 *
 * NOTE: EventDispatcher is fetched lazily to avoid circular dependency
 * with EventListenerProvider during container initialization.
 */
class StripeCheckoutReturnHandler implements HandlerInterface
{
    private const SECURITY_WARNING_THRESHOLD = 75;

    private LoggerInterface $logger;

    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private StripeAdapterFactoryInterface $adapterFactory,
        private TokenServiceInterface $tokenService,
        private ReturnSecurityValidatorInterface $securityValidator,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = ContainerFactory::getInstance()
            ->getContainer()
            ->get(EventDispatcherInterface::class);

        return $dispatcher;
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

        // Step 1: Validate URL parameters
        $sessionId = $this->validateSessionId($event, $context);
        if ($sessionId === null) {
            return;
        }

        // Step 2: Validate contract token
        $contractIdFromUrl = $this->validateContractToken($context, $sessionId);
        if ($contractIdFromUrl === null) {
            return;
        }

        // Step 3: Retrieve and validate Stripe session
        $checkoutSession = $this->retrieveStripeSession($sessionId);
        $contractId = $this->validatePaymentStatus($checkoutSession, $context, $contractIdFromUrl);
        if ($contractId === null) {
            return;
        }

        // Step 4: Load and validate contract
        $contract = $this->loadContract($contractId, $context);
        if ($contract === null) {
            return;
        }

        // Step 5: Perform security validation
        if (!$this->validateSecurity($contract, $contractId, $context)) {
            return;
        }

        // Step 6: Restore session state and dispatch event
        $this->restoreDeliveryAddressHash($contract, $context);
        $this->dispatchPaymentEvent($checkoutSession, $sessionId, $context);
    }

    private function validateSessionId(StripeCheckoutReturnEvent $event, EventContext $context): ?string
    {
        $sessionId = $event->getCheckoutSessionId();
        if ($sessionId === null) {
            $context->set('error', 'Checkout session ID is missing');
            $context->set('redirectTarget', 'payment');
            return null;
        }
        return $sessionId;
    }

    private function validateContractToken(EventContext $context, string $sessionId): ?string
    {
        $contractToken = $context->get('contract_token');
        $contractIdFromUrl = $context->get('contract_id');

        if (!is_string($contractToken) || $contractToken === '') {
            $context->set('error', 'Contract token is missing');
            $context->set('redirectTarget', 'payment');
            return null;
        }

        if (!is_string($contractIdFromUrl) || $contractIdFromUrl === '') {
            $context->set('error', 'Contract ID is missing from URL');
            $context->set('redirectTarget', 'payment');
            return null;
        }

        if (!$this->tokenService->validateToken($contractToken, $contractIdFromUrl)) {
            $this->logger->warning('Invalid contract token', [
                'contract_id' => $contractIdFromUrl,
                'session_id' => $sessionId,
            ]);
            $context->set('error', 'Invalid contract token');
            $context->set('redirectTarget', 'payment');
            return null;
        }

        return $contractIdFromUrl;
    }

    /**
     * @return object Stripe\Checkout\Session object (or mock in tests)
     */
    private function retrieveStripeSession(string $sessionId): object
    {
        $stripeClient = $this->adapterFactory->getStripeClient();
        return $stripeClient->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent'],
        ]);
    }

    /**
     * @param object $session Stripe\Checkout\Session object (or mock in tests)
     */
    private function validatePaymentStatus(
        object $session,
        EventContext $context,
        string $contractIdFromUrl
    ): ?string {
        if ($session->payment_status !== 'paid') {
            $context->set('error', 'Payment not completed: ' . $session->payment_status);
            $context->set('redirectTarget', 'payment');
            return null;
        }

        $contractId = $session->metadata->contract_id ?? null;

        if ($contractId === null) {
            $context->set('error', 'Contract ID not found in checkout session metadata');
            $context->set('redirectTarget', 'payment');
            return null;
        }

        if ($contractId !== $contractIdFromUrl) {
            $this->logger->warning('Contract ID mismatch', [
                'url_contract_id' => $contractIdFromUrl,
                'metadata_contract_id' => $contractId,
            ]);
            $context->set('error', 'Contract ID mismatch');
            $context->set('redirectTarget', 'payment');
            return null;
        }

        return $contractId;
    }

    private function loadContract(string $contractId, EventContext $context): ?PaymentContractInterface
    {
        $contract = $this->contractRepository->findById($contractId);

        if ($contract === null) {
            $context->set('error', 'Contract not found: ' . $contractId);
            $context->set('redirectTarget', 'payment');
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
            $context->set('error', 'Security validation failed');
            $context->set('errorCode', 'security_check_failed');
            $context->set('redirectTarget', 'payment');
            return false;
        }

        return true;
    }

    /**
     * Build security context from current request.
     *
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

    /**
     * @param object $session Stripe\Checkout\Session object (or mock in tests)
     */
    private function dispatchPaymentEvent(
        object $session,
        string $sessionId,
        EventContext $context
    ): void {
        $paymentIntent = $session->payment_intent;
        $paymentIntentId = is_string($paymentIntent) ? $paymentIntent : ($paymentIntent->id ?? '');

        $context->set('paymentIntentId', $paymentIntentId);
        $context->set('amount', $session->amount_total / 100);
        $currency = $session->currency ?? 'EUR';
        $context->set('currency', $currency);

        $event = new PaymentAuthorizedEvent(
            context: $context,
            authorizationId: $paymentIntentId,
            providerOrderId: $sessionId,
            amount: $session->amount_total / 100,
            currency: $currency
        );

        $this->getEventDispatcher()->dispatch($event);

        if ($context->get('orderId') !== null) {
            $context->set('redirectTarget', 'thankyou');
        }
    }

    /**
     * Restore delivery address hash from contract metadata.
     *
     * CRITICAL: We inject into BOTH $_REQUEST and session because:
     * - Order::validateDeliveryAddress() reads from $_REQUEST['sDeliveryAddressMD5']
     * - Some OXID code paths also check session variable 'sDelAddrMD5'
     */
    private function restoreDeliveryAddressHash(PaymentContractInterface $contract, EventContext $context): void
    {
        $session = Registry::getSession();

        $deliveryHash = $contract->getMetadata('delivery_address_hash');
        if ($deliveryHash !== null && is_string($deliveryHash)) {
            $_REQUEST['sDeliveryAddressMD5'] = $deliveryHash;
            $session->setVariable('sDelAddrMD5', $deliveryHash);
        }

        $deliveryId = $contract->getMetadata('delivery_address_id');
        if ($deliveryId !== null && is_string($deliveryId)) {
            $session->setVariable('deladrid', $deliveryId);
            $context->set('restoredDeliveryAddressId', $deliveryId);
        }
    }
}
