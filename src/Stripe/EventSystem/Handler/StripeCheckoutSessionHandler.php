<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Payments\Stripe\Core\ShopId;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\StripeCustomerServiceInterface;
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
        private readonly ShopAdapterInterface $shopAdapter,
        private readonly StripeCustomerServiceInterface $customerService,
        private readonly ModuleConfigurationServiceInterface $config,
        private readonly ?FileLoggerInterface $eventLogger = null,
        private readonly ?IframeCheckoutSettingsInterface $iframeSettings = null
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutSessionRequestEvent::class;
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

        // Build checkout parameters and resolve customer
        $params = $this->buildCheckoutParams($context, $contract);
        $stripeCustomerId = $this->resolveCustomerId($context);

        $this->logEvent('StripeCheckoutSessionHandler: Creating checkout session', [
            'contractId' => $params['contractId'],
            'captureMode' => $params['captureMode'],
            'orderId' => $params['orderId'],
            'orderNumber' => $params['orderNumber'],
            'stripeCustomerId' => $stripeCustomerId,
        ]);

        $embedded = $this->iframeSettings?->isEnabled() ?? false;

        $result = $this->checkoutSessionService->createSession(
            $params['contractId'],
            $contract->getBasketSnapshot(),
            $params['successUrl'],
            $params['cancelUrl'],
            $params['shopId'],
            $params['captureMode'],
            $params['orderId'],
            $params['orderNumber'],
            $stripeCustomerId,
            $embedded
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
        $contract->setProvider(StripeDefinitions::PROVIDER, $result->getSessionId() ?? '', $params['successUrl']);

        $this->contractRepository->save($contract);

        // Update context for controller
        $context->set('checkoutSessionId', $result->getSessionId());
        $context->set('checkoutUrl', $result->getCheckoutUrl());
        $context->set('clientSecret', $result->getClientSecret());
        $context->set('renderMode', $embedded ? 'iframe' : 'redirect');

        $this->logEvent('StripeCheckoutSessionHandler::handle() END', [
            'checkoutSessionId' => $result->getSessionId(),
        ]);
    }

    /**
     * Build all parameters needed for checkout session creation.
     *
     * @return array{contractId: string, captureMode: string, shopId: string, successUrl: string, cancelUrl: string, orderId: ?string, orderNumber: ?string}
     */
    private function buildCheckoutParams(EventContext $context, PaymentContractInterface $contract): array
    {
        $contractId = $contract->getId() ?? '';
        $captureMode = $this->getContextString($context, 'captureMode', StripeDefinitions::CAPTURE_MODE_AUTOMATIC);
        $shopUrl = $this->getContextString($context, 'shopUrl', $this->shopAdapter->getShopUrl());
        $sessionId = $this->getContextString($context, 'sessionId', '');
        $shopId = $this->getContextString($context, 'shopId', '1');

        $rawLangId = $context->get('languageId');
        $languageId = is_numeric($rawLangId) ? (int) $rawLangId : 0;
        // Sprint 133 (F14): no silent fallback to shop 1 on EE multishop.
        $shopIdInt = ShopId::of($shopId, 'checkout session handler');

        $contractToken = $this->tokenService->generateToken($contractId);
        $successUrl = $this->checkoutSessionService->buildSuccessUrl($shopUrl, $contractId, $contractToken, $sessionId, $languageId, $shopIdInt);
        $cancelUrl = $shopUrl . 'index.php?cl=order&fnc=checkoutCancel&lang=' . $languageId . '&shp=' . $shopIdInt;

        $orderId = $contract->getOrderId();
        $orderNumber = $contract->getMetadata('order_number');
        $orderNumberString = is_scalar($orderNumber) ? (string) $orderNumber : null;

        return [
            'contractId' => $contractId,
            'captureMode' => $captureMode,
            'shopId' => $shopId,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'orderId' => $orderId,
            'orderNumber' => $orderNumberString,
        ];
    }

    /**
     * Resolve Stripe Customer ID from context user, if feature is enabled.
     *
     * Sprint 45: Email prefill and saved cards.
     */
    private function resolveCustomerId(EventContext $context): ?string
    {
        if (!$this->config->shouldProvideCustomerEmail()) {
            return null;
        }

        $customerData = $this->extractCustomerData($context);
        if ($customerData === null) {
            return null;
        }

        try {
            return $this->customerService->resolveStripeCustomerId(
                $customerData['userId'],
                $customerData['email'],
                $customerData['name']
            );
        } catch (\Throwable $e) {
            $this->logEvent('StripeCheckoutSessionHandler: Failed to resolve customer', [
                'userId' => $customerData['userId'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Extract customer data (userId, email, name) from event context.
     *
     * @return array{userId: string, email: string, name: string}|null
     */
    private function extractCustomerData(EventContext $context): ?array
    {
        $userId = $this->getContextString($context, 'userId', '');
        if ($userId === '') {
            return null;
        }

        $user = $context->getUser();
        if ($user === null) {
            return null;
        }

        $email = $this->getUserFieldString($user, 'oxusername');
        if ($email === '') {
            return null;
        }

        $firstName = $this->getUserFieldString($user, 'oxfname');
        $lastName = $this->getUserFieldString($user, 'oxlname');

        return [
            'userId' => $userId,
            'email' => $email,
            'name' => trim($firstName . ' ' . $lastName),
        ];
    }

    /**
     * Get a typed string value from EventContext.
     */
    private function getContextString(EventContext $context, string $key, string $default): string
    {
        $value = $context->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Extract a string field from an OXID user object via getFieldData().
     */
    private function getUserFieldString(object $user, string $fieldName): string
    {
        if (!method_exists($user, 'getFieldData')) {
            return '';
        }

        /** @phpstan-ignore-next-line OXID core: getFieldData() on dynamic user object */
        $value = $user->getFieldData($fieldName);

        return is_string($value) ? $value : '';
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
