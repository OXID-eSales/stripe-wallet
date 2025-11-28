<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripePaymentStatusHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripePaymentExecuteEvent;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\Stripe3DSRequiredEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\EventDispatcherInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\PaymentAdapterInterface;
use OxidSolutionCatalysts\Payments\Component\Adapter\Response\PaymentDetailsResponse;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeStatusMapper;
use PHPUnit\Framework\TestCase;

class StripePaymentStatusHandlerTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private StripeAdapterFactoryInterface $adapterFactory;
    private EventDispatcherInterface $eventDispatcher;
    private PaymentAdapterInterface $adapter;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->adapter = $this->createMock(PaymentAdapterInterface::class);
    }

    public function testHandlerIgnoresNonStripePaymentExecuteEvent(): void
    {
        $handler = new StripePaymentStatusHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->eventDispatcher
        );

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $handler->handle($otherEvent);
    }

    public function testGetsPaymentIntentStatus(): void
    {
        $paymentDetails = $this->createPaymentDetailsResponse(
            StripeStatusMapper::STATUS_CAPTURED,
            'pi_test_123',
            100.00,
            'EUR'
        );

        $this->adapter
            ->expects($this->once())
            ->method('getPaymentDetails')
            ->with('pi_test_123')
            ->willReturn($paymentDetails);

        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($this->adapter);

        $contract = $this->createContractMock('contract_123');
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $context = new EventContext([
            'paymentIntentId' => 'pi_test_123',
            'contractId' => 'contract_123',
        ]);
        $event = new StripePaymentExecuteEvent($context);

        $handler = new StripePaymentStatusHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->eventDispatcher
        );

        $handler->handle($event);

        $this->assertEquals(StripeStatusMapper::STATUS_CAPTURED, $context->get('paymentStatus'));
    }

    public function testDispatchesPaymentAuthorizedOnSuccess(): void
    {
        $paymentDetails = $this->createPaymentDetailsResponse(
            StripeStatusMapper::STATUS_CAPTURED,
            'pi_test_success',
            50.00,
            'EUR'
        );

        $this->adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($this->adapter);

        $contract = $this->createContractMock('contract_success');
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentAuthorizedEvent::class))
            ->willReturnCallback(fn($e) => $e);

        $context = new EventContext([
            'paymentIntentId' => 'pi_test_success',
            'contractId' => 'contract_success',
        ]);
        $event = new StripePaymentExecuteEvent($context);

        $handler = new StripePaymentStatusHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->eventDispatcher
        );

        $handler->handle($event);
    }

    public function testDispatchesPaymentAuthorizedOnAuthorized(): void
    {
        $paymentDetails = $this->createPaymentDetailsResponse(
            StripeStatusMapper::STATUS_AUTHORIZED,
            'pi_test_auth',
            75.00,
            'EUR'
        );

        $this->adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($this->adapter);

        $contract = $this->createContractMock('contract_auth');
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentAuthorizedEvent::class))
            ->willReturnCallback(fn($e) => $e);

        $context = new EventContext([
            'paymentIntentId' => 'pi_test_auth',
            'contractId' => 'contract_auth',
        ]);
        $event = new StripePaymentExecuteEvent($context);

        $handler = new StripePaymentStatusHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->eventDispatcher
        );

        $handler->handle($event);
    }

    public function testDispatches3DSRequiredOnRequiresAction(): void
    {
        $paymentDetails = $this->createPaymentDetailsResponse(
            StripeStatusMapper::STATUS_PENDING,
            'pi_test_3ds',
            100.00,
            'EUR',
            [
                'status' => StripeStatusMapper::STRIPE_REQUIRES_ACTION,
                'client_secret' => 'pi_test_3ds_secret_xyz',
            ]
        );

        $this->adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($this->adapter);

        $contract = $this->createContractMock('contract_3ds');
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(Stripe3DSRequiredEvent::class))
            ->willReturnCallback(fn($e) => $e);

        $context = new EventContext([
            'paymentIntentId' => 'pi_test_3ds',
            'contractId' => 'contract_3ds',
        ]);
        $event = new StripePaymentExecuteEvent($context);

        $handler = new StripePaymentStatusHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->eventDispatcher
        );

        $handler->handle($event);

        $this->assertTrue($context->get('requires3DS'));
        $this->assertEquals('pi_test_3ds_secret_xyz', $context->get('clientSecret'));
    }

    public function testSetsErrorOnDecline(): void
    {
        $paymentDetails = $this->createPaymentDetailsResponse(
            StripeStatusMapper::STATUS_FAILED,
            'pi_test_failed',
            100.00,
            'EUR'
        );

        $this->adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($this->adapter);

        $contract = $this->createContractMock('contract_failed');
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Should NOT dispatch PaymentAuthorizedEvent on failure
        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $context = new EventContext([
            'paymentIntentId' => 'pi_test_failed',
            'contractId' => 'contract_failed',
        ]);
        $event = new StripePaymentExecuteEvent($context);

        $handler = new StripePaymentStatusHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->eventDispatcher
        );

        $handler->handle($event);

        $this->assertNotNull($context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testSetsPaymentDetailsInContext(): void
    {
        $paymentDetails = $this->createPaymentDetailsResponse(
            StripeStatusMapper::STATUS_CAPTURED,
            'pi_test_details',
            123.45,
            'USD'
        );

        $this->adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($this->adapter);

        $contract = $this->createContractMock('contract_details');
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $context = new EventContext([
            'paymentIntentId' => 'pi_test_details',
            'contractId' => 'contract_details',
        ]);
        $event = new StripePaymentExecuteEvent($context);

        $handler = new StripePaymentStatusHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->eventDispatcher
        );

        $handler->handle($event);

        $this->assertEquals(123.45, $context->get('amount'));
        $this->assertEquals('USD', $context->get('currency'));
        $this->assertSame($paymentDetails, $context->get('paymentDetails'));
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripePaymentExecuteEvent::class,
            StripePaymentStatusHandler::getHandledEventClass()
        );
    }

    public function testSetsContractInContext(): void
    {
        $paymentDetails = $this->createPaymentDetailsResponse(
            StripeStatusMapper::STATUS_CAPTURED,
            'pi_test_contract',
            100.00,
            'EUR'
        );

        $this->adapter
            ->method('getPaymentDetails')
            ->willReturn($paymentDetails);

        $this->adapterFactory
            ->method('createDefaultAdapter')
            ->willReturn($this->adapter);

        $contract = $this->createContractMock('contract_ctx_test');
        $this->contractRepository
            ->method('findById')
            ->with('contract_ctx_test')
            ->willReturn($contract);

        $context = new EventContext([
            'paymentIntentId' => 'pi_test_contract',
            'contractId' => 'contract_ctx_test',
        ]);
        $event = new StripePaymentExecuteEvent($context);

        $handler = new StripePaymentStatusHandler(
            $this->contractRepository,
            $this->adapterFactory,
            $this->eventDispatcher
        );

        $handler->handle($event);

        $this->assertSame($contract, $context->getContract());
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
     * @param array<string, mixed> $providerData
     */
    private function createPaymentDetailsResponse(
        string $status,
        string $providerPaymentId,
        float $amount,
        string $currency,
        array $providerData = []
    ): PaymentDetailsResponse {
        $isCaptured = $status === StripeStatusMapper::STATUS_CAPTURED;

        return new PaymentDetailsResponse(
            providerPaymentId: $providerPaymentId,
            status: $status,
            amount: $amount,
            currency: $currency,
            amountCaptured: $isCaptured ? $amount : 0.0,
            amountRefunded: 0.0,
            isCaptured: $isCaptured,
            isRefunded: false,
            isCancelled: $status === StripeStatusMapper::STATUS_CANCELLED,
            createdAt: new \DateTime(),
            capturedAt: $isCaptured ? new \DateTime() : null,
            refundedAt: null,
            providerData: $providerData,
            metadata: []
        );
    }
}
