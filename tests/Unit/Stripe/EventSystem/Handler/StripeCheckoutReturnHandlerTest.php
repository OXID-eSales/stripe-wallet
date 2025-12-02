<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidSolutionCatalysts\Payments\Stripe\Service\Result\SecurityValidationResult;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\TokenServiceInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ReturnSecurityValidatorInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Stripe\Service\Checkout\SessionService;

/**
 * Testable subclass that allows injecting the event dispatcher for testing.
 */
class TestableStripeCheckoutReturnHandler extends StripeCheckoutReturnHandler
{
    private ?EventDispatcherInterface $testEventDispatcher = null;

    public function setTestEventDispatcher(EventDispatcherInterface $dispatcher): void
    {
        $this->testEventDispatcher = $dispatcher;
    }

    protected function getEventDispatcher(): EventDispatcherInterface
    {
        if ($this->testEventDispatcher !== null) {
            return $this->testEventDispatcher;
        }
        return parent::getEventDispatcher();
    }

    /**
     * Expose request injection for testing
     */
    public function getInjectedRequestValues(): array
    {
        return [
            'sDeliveryAddressMD5' => $_REQUEST['sDeliveryAddressMD5'] ?? null,
        ];
    }
}

class StripeCheckoutReturnHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private StripeAdapterFactoryInterface $adapterFactory;
    private TokenServiceInterface $tokenService;
    private ReturnSecurityValidatorInterface $securityValidator;
    private LoggerInterface $logger;
    private EventDispatcherInterface $eventDispatcher;
    private StripeClient $stripeClient;
    private SessionService $sessionService;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->securityValidator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->sessionService = $this->createMock(SessionService::class);

        // Default: token validation passes
        $this->tokenService->method('validateToken')->willReturn(true);
        $this->tokenService->method('extractContractId')->willReturnCallback(
            fn($token) => str_starts_with($token, 'token_') ? str_replace('token_', '', $token) : null
        );

        // Default: security validation passes
        $this->securityValidator->method('validateReturn')->willReturn(
            new SecurityValidationResult(100, [], true)
        );

        // Clear any previous $_REQUEST state
        unset($_REQUEST['sDeliveryAddressMD5']);
    }

    protected function tearDown(): void
    {
        // Clean up $_REQUEST
        unset($_REQUEST['sDeliveryAddressMD5']);
    }

    private function createHandler(): TestableStripeCheckoutReturnHandler
    {
        $handler = new TestableStripeCheckoutReturnHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService,
            $this->securityValidator,
            $this->logger
        );
        $handler->setTestEventDispatcher($this->eventDispatcher);
        return $handler;
    }

    public function testHandlerIgnoresNonStripeCheckoutReturnEvent(): void
    {
        $handler = $this->createHandler();

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        // Should not throw, just return early
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $handler->handle($otherEvent);
    }

    public function testRetrievesCheckoutSession(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_123', 'paid', 'pi_test_123', 'contract_xyz');

        $this->sessionService
            ->expects($this->once())
            ->method('retrieve')
            ->with('cs_test_123', ['expand' => ['payment_intent']])
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_xyz', [
            'delivery_address_hash' => 'hash_xyz',
        ]);
        $this->contractRepository
            ->method('findById')
            ->with('contract_xyz')
            ->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_123',
            'contract_token' => 'token_contract_xyz',
            'contract_id' => 'contract_xyz',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testVerifiesPaymentStatus(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_paid', 'paid', 'pi_test_paid', 'contract_paid');

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_paid', [
            'delivery_address_hash' => 'hash_paid',
        ]);
        $this->contractRepository
            ->method('findById')
            ->with('contract_paid')
            ->willReturn($contract);

        // When payment is 'paid', should dispatch PaymentAuthorizedEvent
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentAuthorizedEvent::class));

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_paid',
            'contract_token' => 'token_contract_paid',
            'contract_id' => 'contract_paid',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testLoadsContractFromMetadata(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_load', 'paid', 'pi_test_load', 'contract_load_test');

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_load_test', [
            'delivery_address_hash' => 'hash_load',
        ]);

        // Verify the correct contract ID is used
        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with('contract_load_test')
            ->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_load',
            'contract_token' => 'token_contract_load_test',
            'contract_id' => 'contract_load_test',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testDispatchesPaymentConfirmedEvent(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_confirm', 'paid', 'pi_test_confirm', 'contract_confirm');

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_confirm', [
            'delivery_address_hash' => 'hash_confirm',
        ]);
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $dispatchedEvent = null;
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatchedEvent) {
                $dispatchedEvent = $event;
                return $event;
            });

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_confirm',
            'contract_token' => 'token_contract_confirm',
            'contract_id' => 'contract_confirm',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertInstanceOf(PaymentAuthorizedEvent::class, $dispatchedEvent);
    }

    public function testSetsErrorOnPaymentNotCompleted(): void
    {
        // Unpaid session
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_unpaid', 'unpaid', 'pi_test_unpaid', 'contract_unpaid');

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        // Should NOT dispatch PaymentConfirmedEvent
        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_unpaid',
            'contract_token' => 'token_contract_unpaid',
            'contract_id' => 'contract_unpaid',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertNotNull($context->get('error'));
        $this->assertStringContainsString('unpaid', $context->get('error'));
    }

    public function testSetsRedirectTargetToPaymentOnError(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_err', 'expired', 'pi_test_err', 'contract_err');

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_err',
            'contract_token' => 'token_contract_err',
            'contract_id' => 'contract_err',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testSetsContractInContext(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_ctx', 'paid', 'pi_test_ctx', 'contract_ctx');

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_ctx', [
            'delivery_address_hash' => 'hash_ctx',
        ]);
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_ctx',
            'contract_token' => 'token_contract_ctx',
            'contract_id' => 'contract_ctx',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertSame($contract, $context->getContract());
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripeCheckoutReturnEvent::class,
            StripeCheckoutReturnHandler::getHandledEventClass()
        );
    }

    public function testSetsPaymentIntentIdInContext(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_pi', 'paid', 'pi_test_xyz123', 'contract_pi');

        $this->sessionService
            ->method('retrieve')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_pi', [
            'delivery_address_hash' => 'hash_pi',
        ]);
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_pi',
            'contract_token' => 'token_contract_pi',
            'contract_id' => 'contract_pi',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertEquals('pi_test_xyz123', $context->get('paymentIntentId'));
    }

    // =========================================================================
    // Token Validation Tests (Sprint 1 - Session Restoration)
    // =========================================================================

    public function testValidatesContractTokenFromUrl(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_token', 'paid', 'pi_test_token', 'contract_token_test');

        $this->sessionService->method('retrieve')->willReturn($checkoutSession);
        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_token_test', [
            'delivery_address_hash' => 'hash_abc123',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Token should be validated
        $this->tokenService
            ->expects($this->once())
            ->method('validateToken')
            ->with('token_contract_token_test', 'contract_token_test')
            ->willReturn(true);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_token',
            'contract_token' => 'token_contract_token_test',
            'contract_id' => 'contract_token_test',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Should proceed without error
        $this->assertNull($context->get('error'));
    }

    public function testRejectsInvalidToken(): void
    {
        // Token validation fails
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->tokenService->method('validateToken')->willReturn(false);
        $this->tokenService->method('extractContractId')->willReturn('contract_invalid');

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_invalid',
            'contract_token' => 'invalid_token',
            'contract_id' => 'contract_invalid',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Should set error
        $this->assertNotNull($context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testRejectsMissingToken(): void
    {
        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_no_token',
            // No contract_token provided
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Should set error for missing token
        $this->assertNotNull($context->get('error'));
    }

    // =========================================================================
    // Security Validation Tests (Sprint 1 - Session Restoration)
    // =========================================================================

    public function testPerformsSecurityValidation(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_sec', 'paid', 'pi_test_sec', 'contract_sec');

        $this->sessionService->method('retrieve')->willReturn($checkoutSession);
        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_sec', [
            'user_ip' => '192.168.1.100',
            'delivery_address_hash' => 'hash_sec',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Security validator should be called
        $this->securityValidator
            ->expects($this->once())
            ->method('validateReturn')
            ->with($contract, $this->isType('array'))
            ->willReturn(new SecurityValidationResult(100, [], true));

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_sec',
            'contract_token' => 'token_contract_sec',
            'contract_id' => 'contract_sec',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testLogsWarningForLowSecurityScore(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_warn', 'paid', 'pi_test_warn', 'contract_warn');

        $this->sessionService->method('retrieve')->willReturn($checkoutSession);
        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_warn', [
            'delivery_address_hash' => 'hash_warn',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Low security score but still allowed
        $this->securityValidator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $this->securityValidator->method('validateReturn')->willReturn(
            new SecurityValidationResult(60, ['ip_changed'], true)
        );

        // Should log warning
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Suspicious'),
                $this->callback(fn($ctx) => $ctx['score'] === 60)
            );

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_warn',
            'contract_token' => 'token_contract_warn',
            'contract_id' => 'contract_warn',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testBlocksReturnWhenSecurityCheckFails(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_block', 'paid', 'pi_test_block', 'contract_block');

        $this->sessionService->method('retrieve')->willReturn($checkoutSession);
        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_block', [
            'delivery_address_hash' => 'hash_block',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Security check fails
        $this->securityValidator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $this->securityValidator->method('validateReturn')->willReturn(
            new SecurityValidationResult(30, ['country_changed', 'very_late'], false)
        );

        // Should NOT dispatch event
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_block',
            'contract_token' => 'token_contract_block',
            'contract_id' => 'contract_block',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Should set error
        $this->assertNotNull($context->get('error'));
        $this->assertEquals('security_check_failed', $context->get('errorCode'));
    }

    // =========================================================================
    // Session Restoration Tests (Sprint 1 - CRITICAL)
    // =========================================================================

    public function testInjectsDeliveryAddressHashIntoRequest(): void
    {
        $expectedHash = 'abc123hash';

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_inject', 'paid', 'pi_test_inject', 'contract_inject');

        $this->sessionService->method('retrieve')->willReturn($checkoutSession);
        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_inject', [
            'delivery_address_hash' => $expectedHash,
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Clear REQUEST to simulate return from Stripe
        unset($_REQUEST['sDeliveryAddressMD5']);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_inject',
            'contract_token' => 'token_contract_inject',
            'contract_id' => 'contract_inject',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // CRITICAL: Hash should be injected into $_REQUEST
        $this->assertEquals($expectedHash, $_REQUEST['sDeliveryAddressMD5']);
    }

    public function testRestoresDeliveryAddressIdToContext(): void
    {
        $checkoutSession = $this->createCheckoutSessionMock('cs_test_addr', 'paid', 'pi_test_addr', 'contract_addr');

        $this->sessionService->method('retrieve')->willReturn($checkoutSession);
        $this->setupStripeClientMocks();

        $contract = $this->createContractMockWithMetadata('contract_addr', [
            'delivery_address_hash' => 'hash_addr',
            'delivery_address_id' => 'addr_456',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_addr',
            'contract_token' => 'token_contract_addr',
            'contract_id' => 'contract_addr',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Address ID should be in context for session restoration
        $this->assertEquals('addr_456', $context->get('restoredDeliveryAddressId'));
    }

    // --- Helper methods ---

    private function createContractMock(string $contractId): PaymentContractInterface
    {
        return $this->createContractMockWithMetadata($contractId, []);
    }

    private function createContractMockWithMetadata(string $contractId, array $metadata): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);

        $contract->method('getMetadata')->willReturnCallback(
            fn(string $key) => $metadata[$key] ?? null
        );
        $contract->method('getAllMetadata')->willReturn($metadata);

        $snapshot = BasketSnapshot::fromArray([
            'items' => [['title' => 'Test', 'unitPrice' => 10.00, 'quantity' => 1]],
            'discounts' => [],
            'totalGross' => 10.00,
            'totalNet' => 8.40,
            'totalVat' => 1.60,
            'currency' => 'EUR',
        ]);
        $contract->method('getBasketSnapshot')->willReturn($snapshot);

        return $contract;
    }

    /**
     * Create a checkout session mock with metadata
     */
    private function createCheckoutSessionMock(
        string $sessionId,
        string $paymentStatus,
        string $paymentIntentId,
        string $contractId
    ): object {
        return new class($sessionId, $paymentStatus, $paymentIntentId, $contractId) {
            public string $id;
            public string $payment_status;
            public string $payment_intent;
            public int $amount_total;
            public string $currency;
            public object $metadata;

            public function __construct(
                string $id,
                string $paymentStatus,
                string $paymentIntentId,
                string $contractId
            ) {
                $this->id = $id;
                $this->payment_status = $paymentStatus;
                $this->payment_intent = $paymentIntentId;
                $this->amount_total = 1000; // 10.00 EUR in cents
                $this->currency = 'eur';
                $this->metadata = new class($contractId) {
                    public string $contract_id;
                    public function __construct(string $contractId)
                    {
                        $this->contract_id = $contractId;
                    }
                };
            }
        };
    }

    private function setupStripeClientMocks(): void
    {
        $checkoutService = new \stdClass();
        $checkoutService->sessions = $this->sessionService;

        $this->stripeClient->checkout = $checkoutService;

        $this->adapterFactory
            ->method('getStripeClient')
            ->willReturn($this->stripeClient);
    }
}
