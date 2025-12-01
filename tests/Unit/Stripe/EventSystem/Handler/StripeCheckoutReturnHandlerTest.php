<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use PHPUnit\Framework\TestCase;
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
}

class StripeCheckoutReturnHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private StripeAdapterFactoryInterface $adapterFactory;
    private EventDispatcherInterface $eventDispatcher;
    private StripeClient $stripeClient;
    private SessionService $sessionService;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->sessionService = $this->createMock(SessionService::class);
    }

    private function createHandler(): TestableStripeCheckoutReturnHandler
    {
        $handler = new TestableStripeCheckoutReturnHandler(
            $this->contractRepository,
            $this->adapterFactory
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

        $contract = $this->createContractMock('contract_xyz');
        $this->contractRepository
            ->method('findById')
            ->with('contract_xyz')
            ->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_123',
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

        $contract = $this->createContractMock('contract_paid');
        $this->contractRepository
            ->method('findById')
            ->with('contract_paid')
            ->willReturn($contract);

        // When payment is 'paid', should dispatch PaymentAuthorizedEvent
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentAuthorizedEvent::class));

        $context = new EventContext(['checkoutSessionId' => 'cs_test_paid']);
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

        $contract = $this->createContractMock('contract_load_test');

        // Verify the correct contract ID is used
        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with('contract_load_test')
            ->willReturn($contract);

        $context = new EventContext(['checkoutSessionId' => 'cs_test_load']);
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

        $contract = $this->createContractMock('contract_confirm');
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

        $context = new EventContext(['checkoutSessionId' => 'cs_test_confirm']);
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

        $context = new EventContext(['checkoutSessionId' => 'cs_test_unpaid']);
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

        $context = new EventContext(['checkoutSessionId' => 'cs_test_err']);
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

        $contract = $this->createContractMock('contract_ctx');
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $context = new EventContext(['checkoutSessionId' => 'cs_test_ctx']);
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

        $contract = $this->createContractMock('contract_pi');
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $context = new EventContext(['checkoutSessionId' => 'cs_test_pi']);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertEquals('pi_test_xyz123', $context->get('paymentIntentId'));
    }

    // --- Helper methods ---

    private function createContractMock(string $contractId): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);

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
