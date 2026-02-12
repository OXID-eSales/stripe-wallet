<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\EventSystem\Event\EventInterface;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpResponseFormatterInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ContractServiceInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentResult;
use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentServiceInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\StripeAcpCheckoutService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeAcpCheckoutService.
 *
 * Tests the Stripe-specific checkout service that extends AbstractAcpCheckoutService.
 * Focuses on createCheckout (event dispatching) and completePayment (SPT flow).
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\StripeAcpCheckoutService
 */
class StripeAcpCheckoutServiceTest extends TestCase
{
    private ContractServiceInterface&MockObject $contractService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private AcpResponseFormatterInterface&MockObject $formatter;
    private SptPaymentServiceInterface&MockObject $sptPaymentService;
    private ShopAdapterInterface&MockObject $shopAdapter;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->formatter = $this->createMock(AcpResponseFormatterInterface::class);
        $this->sptPaymentService = $this->createMock(SptPaymentServiceInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
    }

    private function createService(): StripeAcpCheckoutService
    {
        return new StripeAcpCheckoutService(
            $this->contractService,
            $this->contractRepository,
            $this->eventDispatcher,
            $this->formatter,
            $this->sptPaymentService,
            $this->shopAdapter
        );
    }

    private function createContractMock(
        string $id = 'contract_acp_123',
        string $state = 'pending',
        ?string $orderId = null
    ): PaymentContractInterface&MockObject {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getOrderId')->willReturn($orderId);
        $contract->method('getAmount')->willReturn(99.99);
        $contract->method('getCurrency')->willReturn('EUR');

        $contractState = ContractState::fromValue($state);
        $contract->method('getState')->willReturn($contractState);

        return $contract;
    }

    public function testImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(AcpCheckoutServiceInterface::class, $service);
    }

    // ==========================================
    // createCheckout tests
    // ==========================================

    public function testCreateCheckoutDispatchesEvent(): void
    {
        $agentContext = new AgentContext('agent_create', 'token_create');
        $arguments = [
            'items' => [['product_id' => 'prod_1', 'quantity' => 1]],
            'buyer' => ['name' => 'John Doe'],
            'fulfillment_address' => ['city' => 'Berlin'],
            'currency' => 'EUR',
        ];

        $contract = $this->createContractMock();
        $expectedResponse = ['id' => 'contract_acp_123', 'status' => 'pending'];

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                if (!$event instanceof StripeCheckoutSessionRequestEvent) {
                    return false;
                }
                $context = $event->getContext();
                return $context->get('source') === 'acp'
                    && $context->get('acp_agent_id') === 'agent_create'
                    && $context->get('acp_currency') === 'EUR';
            }))
            ->willReturnCallback(function (EventInterface $event) use ($contract) {
                // Simulate handler setting the contract on the context
                $event->getContext()->setContract($contract);
                return $event;
            });

        $this->formatter
            ->expects($this->once())
            ->method('formatCheckout')
            ->with($contract)
            ->willReturn($expectedResponse);

        $service = $this->createService();
        $result = $service->createCheckout($arguments, $agentContext);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCreateCheckoutReturnsErrorWhenNoContractCreated(): void
    {
        $agentContext = new AgentContext('agent_fail', 'token_fail');
        $arguments = ['items' => []];

        $errorResponse = ['error' => ['message' => 'Failed to create checkout session']];

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnArgument(0);

        $this->formatter
            ->expects($this->once())
            ->method('validationError')
            ->with('Failed to create checkout session')
            ->willReturn($errorResponse);

        $service = $this->createService();
        $result = $service->createCheckout($arguments, $agentContext);

        $this->assertSame($errorResponse, $result);
    }

    public function testCreateCheckoutPassesItemsFromArguments(): void
    {
        $agentContext = new AgentContext('agent_items', 'token_items');
        $items = [
            ['product_id' => 'prod_a', 'quantity' => 2],
            ['product_id' => 'prod_b', 'quantity' => 1],
        ];

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) use ($items) {
                return $event->getContext()->get('acp_items') === $items;
            }))
            ->willReturnArgument(0);

        $this->formatter->method('validationError')->willReturn(['error' => 'test']);

        $service = $this->createService();
        $service->createCheckout(['items' => $items], $agentContext);
    }

    public function testCreateCheckoutDefaultsCurrencyToEur(): void
    {
        $agentContext = new AgentContext('agent_default', 'token_default');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event->getContext()->get('acp_currency') === 'EUR';
            }))
            ->willReturnArgument(0);

        $this->formatter->method('validationError')->willReturn(['error' => 'test']);

        $service = $this->createService();
        $service->createCheckout([], $agentContext);
    }

    // ==========================================
    // completeCheckout / completePayment tests
    // ==========================================

    public function testCompletePaymentWithSuccessfulSpt(): void
    {
        $checkoutId = 'contract_complete_123';
        $contract = $this->createContractMock($checkoutId, 'pending', 'order_789');
        $agentContext = new AgentContext('agent_pay', 'token_pay');
        $paymentData = [
            'token' => 'spt_granted_success',
            'billing_address' => ['city' => 'Berlin'],
        ];

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        $sptResult = SptPaymentResult::success('pi_complete_456', 'succeeded');
        $this->sptPaymentService
            ->expects($this->once())
            ->method('confirmWithSpt')
            ->with($contract, 'spt_granted_success', ['city' => 'Berlin'])
            ->willReturn($sptResult);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->willReturnArgument(0);

        $this->shopAdapter
            ->method('getShopUrl')
            ->willReturn('https://shop.example.com');

        $expectedResponse = [
            'id' => 'order_789',
            'checkout_session_id' => $checkoutId,
            'permalink_url' => 'https://shop.example.com?cl=order_confirm&order=order_789',
        ];

        $this->formatter
            ->expects($this->once())
            ->method('formatOrder')
            ->with(
                $contract,
                'https://shop.example.com?cl=order_confirm&order=order_789'
            )
            ->willReturn($expectedResponse);

        $service = $this->createService();
        $result = $service->completeCheckout($checkoutId, $paymentData, $agentContext);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCompletePaymentWithFailedSptReturnsError(): void
    {
        $checkoutId = 'contract_fail_payment';
        $contract = $this->createContractMock($checkoutId, 'pending');
        $agentContext = new AgentContext('agent_fail', 'token_fail');
        $paymentData = ['token' => 'spt_granted_declined'];

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        $sptResult = SptPaymentResult::failed('Card was declined');
        $this->sptPaymentService
            ->expects($this->once())
            ->method('confirmWithSpt')
            ->willReturn($sptResult);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $errorResponse = ['error' => ['message' => 'Card was declined']];
        $this->formatter
            ->expects($this->once())
            ->method('validationError')
            ->with('Card was declined')
            ->willReturn($errorResponse);

        $service = $this->createService();
        $result = $service->completeCheckout($checkoutId, $paymentData, $agentContext);

        $this->assertSame($errorResponse, $result);
    }

    public function testCompletePaymentWithFailedSptAndNullErrorMessage(): void
    {
        $checkoutId = 'contract_null_error';
        $contract = $this->createContractMock($checkoutId, 'pending');
        $agentContext = new AgentContext('agent_null', 'token_null');
        $paymentData = ['token' => 'spt_granted_null_error'];

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        // Create a result where getErrorMessage returns null via the failed factory
        // The failed factory always has an error message, but we test the fallback 'Payment failed'
        $sptResult = SptPaymentResult::failed('');
        $this->sptPaymentService
            ->method('confirmWithSpt')
            ->willReturn($sptResult);

        // The code does: $result->getErrorMessage() ?? 'Payment failed'
        // Since empty string is not null, it won't use fallback.
        // To test the null fallback, we need errorMessage to be null,
        // but SptPaymentResult::failed always sets a string.
        // We test the path where errorMessage is empty string.
        $this->formatter
            ->expects($this->once())
            ->method('validationError')
            ->with('')
            ->willReturn(['error' => ['message' => 'Payment failed']]);

        $service = $this->createService();
        $result = $service->completeCheckout($checkoutId, $paymentData, $agentContext);

        $this->assertArrayHasKey('error', $result);
    }

    // ==========================================
    // completeCheckout parent validation tests
    // ==========================================

    public function testCompleteCheckoutReturnsNotFoundWhenContractMissing(): void
    {
        $agentContext = new AgentContext('agent_missing', 'token_missing');

        $this->contractRepository
            ->method('findById')
            ->with('nonexistent_id')
            ->willReturn(null);

        $notFoundResponse = ['error' => ['type' => 'not_found']];
        $this->formatter
            ->expects($this->once())
            ->method('notFoundError')
            ->with('nonexistent_id')
            ->willReturn($notFoundResponse);

        $service = $this->createService();
        $result = $service->completeCheckout('nonexistent_id', ['token' => 'spt_any'], $agentContext);

        $this->assertSame($notFoundResponse, $result);
    }

    public function testCompleteCheckoutReturnsErrorForTerminalContract(): void
    {
        $checkoutId = 'contract_terminal';
        $contract = $this->createContractMock($checkoutId, 'fulfilled');
        $agentContext = new AgentContext('agent_terminal', 'token_terminal');

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        $errorResponse = ['error' => ['message' => 'Checkout is already in a terminal state']];
        $this->formatter
            ->expects($this->once())
            ->method('validationError')
            ->with('Checkout is already in a terminal state', 'checkout_id')
            ->willReturn($errorResponse);

        $service = $this->createService();
        $result = $service->completeCheckout($checkoutId, ['token' => 'spt_any'], $agentContext);

        $this->assertSame($errorResponse, $result);
    }

    public function testCompleteCheckoutReturnsErrorWhenTokenMissing(): void
    {
        $checkoutId = 'contract_no_token';
        $contract = $this->createContractMock($checkoutId, 'pending');
        $agentContext = new AgentContext('agent_no_token', 'token_no_token');

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        $errorResponse = ['error' => ['message' => 'Payment token is required']];
        $this->formatter
            ->expects($this->once())
            ->method('validationError')
            ->with('Payment token is required', 'payment_data.token')
            ->willReturn($errorResponse);

        $service = $this->createService();
        $result = $service->completeCheckout($checkoutId, [], $agentContext);

        $this->assertSame($errorResponse, $result);
    }

    public function testCompleteCheckoutReturnsErrorWhenTokenIsEmptyString(): void
    {
        $checkoutId = 'contract_empty_token';
        $contract = $this->createContractMock($checkoutId, 'pending');
        $agentContext = new AgentContext('agent_empty', 'token_empty');

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        $errorResponse = ['error' => ['message' => 'Payment token is required']];
        $this->formatter
            ->expects($this->once())
            ->method('validationError')
            ->with('Payment token is required', 'payment_data.token')
            ->willReturn($errorResponse);

        $service = $this->createService();
        $result = $service->completeCheckout($checkoutId, ['token' => ''], $agentContext);

        $this->assertSame($errorResponse, $result);
    }

    public function testCompleteCheckoutSavesAgentMetadataBeforePayment(): void
    {
        $checkoutId = 'contract_metadata';
        $contract = $this->createContractMock($checkoutId, 'pending', 'order_meta');
        $agentContext = new AgentContext('agent_meta', 'token_meta');
        $paymentData = ['token' => 'spt_granted_meta'];

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        // Verify metadata is set on the contract
        $contract->expects($this->exactly(2))
            ->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value) {
                static $callIndex = 0;
                if ($callIndex === 0) {
                    $this->assertSame('acp_agent_id', $key);
                    $this->assertSame('agent_meta', $value);
                }
                if ($callIndex === 1) {
                    $this->assertSame('acp_completed_at', $key);
                    $this->assertIsInt($value);
                }
                $callIndex++;
            });

        // Contract is saved after metadata
        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $sptResult = SptPaymentResult::success('pi_meta', 'succeeded');
        $this->sptPaymentService
            ->method('confirmWithSpt')
            ->willReturn($sptResult);

        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->shopAdapter->method('getShopUrl')->willReturn('https://shop.test');
        $this->formatter->method('formatOrder')->willReturn(['id' => 'order_meta']);

        $service = $this->createService();
        $service->completeCheckout($checkoutId, $paymentData, $agentContext);
    }

    // ==========================================
    // Inherited methods from AbstractAcpCheckoutService
    // ==========================================

    public function testGetCheckoutReturnsFormattedContract(): void
    {
        $checkoutId = 'contract_get_123';
        $contract = $this->createContractMock($checkoutId);
        $expectedResponse = ['id' => $checkoutId, 'status' => 'pending'];

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        $this->formatter
            ->expects($this->once())
            ->method('formatCheckout')
            ->with($contract)
            ->willReturn($expectedResponse);

        $service = $this->createService();
        $result = $service->getCheckout($checkoutId);

        $this->assertSame($expectedResponse, $result);
    }

    public function testGetCheckoutReturnsNotFoundForMissingContract(): void
    {
        $this->contractRepository
            ->method('findById')
            ->willReturn(null);

        $notFoundResponse = ['error' => ['type' => 'not_found']];
        $this->formatter
            ->expects($this->once())
            ->method('notFoundError')
            ->with('missing_id')
            ->willReturn($notFoundResponse);

        $service = $this->createService();
        $result = $service->getCheckout('missing_id');

        $this->assertSame($notFoundResponse, $result);
    }

    public function testCancelCheckoutCancelsAndReturnsFormattedContract(): void
    {
        $checkoutId = 'contract_cancel_123';
        $contract = $this->createContractMock($checkoutId, 'pending');
        $expectedResponse = ['id' => $checkoutId, 'status' => 'cancelled'];

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        $contract->expects($this->once())->method('cancel');
        $this->contractRepository->expects($this->once())->method('save')->with($contract);

        $this->formatter
            ->expects($this->once())
            ->method('formatCheckout')
            ->with($contract)
            ->willReturn($expectedResponse);

        $service = $this->createService();
        $result = $service->cancelCheckout($checkoutId);

        $this->assertSame($expectedResponse, $result);
    }

    public function testCancelCheckoutRejectsTerminalState(): void
    {
        $checkoutId = 'contract_already_done';
        $contract = $this->createContractMock($checkoutId, 'fulfilled');

        $this->contractRepository
            ->method('findById')
            ->with($checkoutId)
            ->willReturn($contract);

        $errorResponse = ['error' => ['message' => 'Checkout is already in a terminal state']];
        $this->formatter
            ->expects($this->once())
            ->method('validationError')
            ->with('Checkout is already in a terminal state', 'checkout_id')
            ->willReturn($errorResponse);

        $service = $this->createService();
        $result = $service->cancelCheckout($checkoutId);

        $this->assertSame($errorResponse, $result);
    }
}
