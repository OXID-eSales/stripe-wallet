<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Application\Model\Order;
use OxidEsales\Eshop\Core\Field;
use OxidEsales\PaymentComponent\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentComponent\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Contract\ContractCondition;
use OxidEsales\PaymentComponent\Contract\PaymentContract;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractCommittedEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\Contract\ContractReadyToCommitEvent;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\OrderPaymentStateServiceInterface;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeOrderCreationHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeOrderCreationHandler.
 *
 * Sprint 72: Tests for handleExistingOrder setting OXTRANSSTATUS = 'OK'.
 *
 * Uses TestableStripeOrderCreationHandler to override oxNew() calls
 * and StubOrder to track field assignments.
 */
class StripeOrderCreationHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ShopOrderServiceInterface&MockObject $shopOrderService;
    private OrderPaymentStateServiceInterface&MockObject $orderPaymentStateService;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private SessionAdapterInterface&MockObject $sessionAdapter;
    private StubOrder $stubOrder;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->shopOrderService = $this->createMock(ShopOrderServiceInterface::class);
        $this->orderPaymentStateService = $this->createMock(OrderPaymentStateServiceInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->sessionAdapter = $this->createMock(SessionAdapterInterface::class);
        $this->stubOrder = new StubOrder('order_123', 1001);
    }

    private function createHandler(): TestableStripeOrderCreationHandler
    {
        return new TestableStripeOrderCreationHandler(
            $this->contractRepository,
            $this->shopOrderService,
            $this->orderPaymentStateService,
            $this->eventDispatcher,
            $this->sessionAdapter,
            $this->stubOrder
        );
    }

    private function createReadyToCommitContract(): PaymentContract
    {
        $basket = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
            'capturedAt' => date('Y-m-d H:i:s'),
        ]);

        $contract = new PaymentContract(1, 'user123', $basket, 'contract_123');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));

        // draft → not_finished → pending → ready_to_commit (auto via fulfillCondition)
        $contract->transitionToNotFinished('order_123');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);

        return $contract;
    }

    public function testHandleExistingOrderSetsStatusOK(): void
    {
        $contract = $this->createReadyToCommitContract();
        $context = new EventContext(['paymentIntentId' => 'pi_test_123']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->orderPaymentStateService->method('markOrderAsPaid')->willReturn(true);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertNotNull($this->stubOrder->oxorder__oxtransstatus);
        $this->assertSame('OK', $this->stubOrder->oxorder__oxtransstatus->value);
        $this->assertTrue($contract->getState()->isCommitted());
    }

    public function testHandleExistingOrderUpdatesTransactionId(): void
    {
        $contract = $this->createReadyToCommitContract();
        $context = new EventContext(['paymentIntentId' => 'pi_test_456']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->orderPaymentStateService->method('markOrderAsPaid')->willReturn(true);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertNotNull($this->stubOrder->oxorder__oxtransid);
        $this->assertSame('pi_test_456', $this->stubOrder->oxorder__oxtransid->value);
    }

    public function testHandleExistingOrderDispatchesCommittedEvent(): void
    {
        $contract = $this->createReadyToCommitContract();
        $context = new EventContext(['paymentIntentId' => 'pi_test_789']);
        $event = new ContractReadyToCommitEvent($contract, $context, []);

        $this->orderPaymentStateService->method('markOrderAsPaid')->willReturn(true);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ContractCommittedEvent::class));

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testGetHandledEventClassReturnsCorrectClass(): void
    {
        $this->assertSame(
            ContractReadyToCommitEvent::class,
            StripeOrderCreationHandler::getHandledEventClass()
        );
    }
}

/**
 * Stub Order that tracks field assignments without OXID framework.
 */
class StubOrder extends Order
{
    public ?Field $oxorder__oxtransstatus = null;
    public ?Field $oxorder__oxtransid = null;
    private bool $saved = false;

    public function __construct(
        private readonly string $stubId,
        private readonly int $stubOrderNr
    ) {
        // Skip parent constructor (OXID framework)
    }

    public function load($oxID): bool // @phpstan-ignore-line
    {
        return true;
    }

    public function getId(): string
    {
        return $this->stubId;
    }

    public function getFieldData($fieldName): mixed // @phpstan-ignore-line
    {
        return match ($fieldName) {
            'oxordernr' => $this->stubOrderNr,
            default => null,
        };
    }

    public function save(): int|bool // @phpstan-ignore-line
    {
        $this->saved = true;
        return true;
    }

    public function wasSaved(): bool
    {
        return $this->saved;
    }
}

/**
 * Testable subclass that overrides oxNew() order creation.
 */
class TestableStripeOrderCreationHandler extends StripeOrderCreationHandler
{
    public function __construct(
        ContractRepositoryInterface $contractRepository,
        ShopOrderServiceInterface $shopOrderService,
        OrderPaymentStateServiceInterface $orderPaymentStateService,
        EventDispatcherInterface $eventDispatcher,
        SessionAdapterInterface $sessionAdapter,
        private readonly Order $testOrder
    ) {
        parent::__construct(
            $contractRepository,
            $shopOrderService,
            $orderPaymentStateService,
            $eventDispatcher,
            $sessionAdapter
        );
    }

    protected function loadOrder(string $orderId): ?Order
    {
        return $this->testOrder;
    }
}
