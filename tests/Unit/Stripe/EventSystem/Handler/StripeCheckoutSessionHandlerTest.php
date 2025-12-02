<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutSessionHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\TokenServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;
use Stripe\Service\Checkout\SessionService;

class StripeCheckoutSessionHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private StripeAdapterFactoryInterface $adapterFactory;
    private TokenServiceInterface $tokenService;
    private StripeClient $stripeClient;
    private SessionService $sessionService;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->tokenService = $this->createMock(TokenServiceInterface::class);
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->sessionService = $this->createMock(SessionService::class);

        // Default token generation behavior
        $this->tokenService
            ->method('generateToken')
            ->willReturnCallback(fn($contractId) => 'token_for_' . $contractId);
    }

    public function testHandlerIgnoresNonStripeCheckoutSessionRequestEvent(): void
    {
        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        // Should not throw, just return early
        $this->contractRepository->expects($this->never())->method('save');

        $handler->handle($otherEvent);
    }

    public function testCreatesStripeCheckoutSession(): void
    {
        $contract = $this->createContractMock('contract_123');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_abc123');

        $this->setupStripeClientMocks($checkoutSession);

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler->handle($event);

        $this->assertEquals('cs_test_abc123', $context->get('checkoutSessionId'));
    }

    public function testUsesContractIdInMetadata(): void
    {
        $contract = $this->createContractMock('contract_xyz');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_456');

        $this->sessionService
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $params) {
                return isset($params['metadata']['contract_id'])
                    && $params['metadata']['contract_id'] === 'contract_xyz'
                    && isset($params['payment_intent_data']['metadata']['contract_id'])
                    && $params['payment_intent_data']['metadata']['contract_id'] === 'contract_xyz';
            }))
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocksWithSessionService();

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);
    }

    public function testDoesNotCreateOrder(): void
    {
        $contract = $this->createContractMock('contract_789');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_789');
        $this->setupStripeClientMocks($checkoutSession);

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);

        // Verify no order ID is set in context
        $this->assertNull($context->get('orderId'));
    }

    public function testBuildsLineItemsFromContractSnapshot(): void
    {
        $basketSnapshot = BasketSnapshot::fromArray([
            'items' => [
                ['title' => 'Product 1', 'unitPrice' => 19.99, 'quantity' => 2],
                ['title' => 'Product 2', 'unitPrice' => 29.99, 'quantity' => 1],
            ],
            'discounts' => [],
            'totalGross' => 69.97,
            'totalNet' => 58.80,
            'totalVat' => 11.17,
            'currency' => 'EUR',
        ]);

        $contract = $this->createContractMock('contract_items', $basketSnapshot);
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_items');

        $this->sessionService
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $params) {
                return isset($params['line_items'])
                    && count($params['line_items']) === 2
                    && $params['line_items'][0]['price_data']['unit_amount'] === 1999
                    && $params['line_items'][1]['price_data']['unit_amount'] === 2999;
            }))
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocksWithSessionService();

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);
    }

    public function testSetsCheckoutSessionIdInContext(): void
    {
        $contract = $this->createContractMock('contract_context');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_context123');
        $this->setupStripeClientMocks($checkoutSession);

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);

        $this->assertEquals('cs_test_context123', $context->get('checkoutSessionId'));
    }

    public function testStoresSessionIdInContract(): void
    {
        $contract = $this->createContractMock('contract_store');
        $context = $this->createContextWithContract($contract);
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_store456');
        $this->setupStripeClientMocks($checkoutSession);

        // setProvider is used to store session ID as providerOrderId
        $contract->expects($this->once())
            ->method('setProvider')
            ->with('stripe', 'cs_test_store456', $this->anything());

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripeCheckoutSessionRequestEvent::class,
            StripeCheckoutSessionHandler::getHandledEventClass()
        );
    }

    public function testUsesCaptureModeFromContext(): void
    {
        $contract = $this->createContractMock('contract_capture');
        $context = $this->createContextWithContract($contract);
        $context->set('captureMode', 'manual');
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_capture');

        $this->sessionService
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $params) {
                return isset($params['payment_intent_data']['capture_method'])
                    && $params['payment_intent_data']['capture_method'] === 'manual';
            }))
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocksWithSessionService();

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);
    }

    // =========================================================================
    // Contract Token in URL Tests (Sprint 1 - Session Restoration)
    // =========================================================================

    public function testSuccessUrlContainsContractToken(): void
    {
        $contract = $this->createContractMock('contract_token_test');
        $context = $this->createContextWithContract($contract);
        $context->set('shopUrl', 'https://shop.example.com/');
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_token');

        $capturedParams = null;
        $this->sessionService
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            }))
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocksWithSessionService();

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);

        // Assert success URL contains contract_token
        $this->assertNotNull($capturedParams);
        $this->assertArrayHasKey('success_url', $capturedParams);
        $this->assertStringContainsString('contract_token=', $capturedParams['success_url']);
        $this->assertStringContainsString('token_for_contract_token_test', $capturedParams['success_url']);
    }

    public function testSuccessUrlContainsContractId(): void
    {
        $contract = $this->createContractMock('contract_id_in_url');
        $context = $this->createContextWithContract($contract);
        $context->set('shopUrl', 'https://shop.example.com/');
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_id');

        $capturedParams = null;
        $this->sessionService
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            }))
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocksWithSessionService();

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);

        // Assert success URL contains contract_id
        $this->assertNotNull($capturedParams);
        $this->assertArrayHasKey('success_url', $capturedParams);
        $this->assertStringContainsString('contract_id=contract_id_in_url', $capturedParams['success_url']);
    }

    public function testCancelUrlDoesNotContainToken(): void
    {
        $contract = $this->createContractMock('contract_cancel');
        $context = $this->createContextWithContract($contract);
        $context->set('shopUrl', 'https://shop.example.com/');
        $event = new StripeCheckoutSessionRequestEvent($context);

        $checkoutSession = $this->createCheckoutSessionMock('cs_test_cancel');

        $capturedParams = null;
        $this->sessionService
            ->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $params) use (&$capturedParams) {
                $capturedParams = $params;
                return true;
            }))
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocksWithSessionService();

        $handler = new StripeCheckoutSessionHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->tokenService
        );

        $handler->handle($event);

        // Assert cancel URL does NOT contain contract_token
        $this->assertNotNull($capturedParams);
        $this->assertArrayHasKey('cancel_url', $capturedParams);
        $this->assertStringNotContainsString('contract_token', $capturedParams['cancel_url']);
    }

    // --- Helper methods ---

    private function createContractMock(string $contractId, ?BasketSnapshot $snapshot = null): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);

        if ($snapshot === null) {
            $snapshot = BasketSnapshot::fromArray([
                'items' => [['title' => 'Test', 'unitPrice' => 10.00, 'quantity' => 1]],
                'discounts' => [],
                'totalGross' => 10.00,
                'totalNet' => 8.40,
                'totalVat' => 1.60,
                'currency' => 'EUR',
            ]);
        }

        $contract->method('getBasketSnapshot')->willReturn($snapshot);

        return $contract;
    }

    private function createContextWithContract(PaymentContractInterface $contract): EventContext
    {
        $context = new EventContext([
            'shopId' => 1,
            'userId' => 'user_123',
        ]);
        $context->setContract($contract);

        return $context;
    }

    private function createCheckoutSessionMock(string $sessionId): object
    {
        // Create a simple object that mimics Stripe CheckoutSession
        // We can't use a mock because PHPUnit mocks don't support public properties well
        return new class($sessionId) {
            public string $id;
            public function __construct(string $id)
            {
                $this->id = $id;
            }
        };
    }

    private function setupStripeClientMocks(object $checkoutSession): void
    {
        $this->sessionService
            ->method('create')
            ->willReturn($checkoutSession);

        $this->setupStripeClientMocksWithSessionService();
    }

    private function setupStripeClientMocksWithSessionService(): void
    {
        $checkoutService = new \stdClass();
        $checkoutService->sessions = $this->sessionService;

        $this->stripeClient->checkout = $checkoutService;

        $this->adapterFactory
            ->method('getStripeClient')
            ->willReturn($this->stripeClient);
    }
}
