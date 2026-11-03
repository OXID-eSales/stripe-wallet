<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 114.1: WebhookContractFulfillmentHandler audit rows must use the
 * injected shop id, not a hardcoded 1.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler::class)]
class WebhookContractFulfillmentHandlerShopIdTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ContractFulfillmentServiceInterface&MockObject $contractFulfillmentService;
    private ContractLinkedOrderUpdaterInterface&MockObject $orderUpdater;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->orderUpdater = $this->createMock(ContractLinkedOrderUpdaterInterface::class);
    }

    /**
     * Sprint 114.1: recordAudit writes the shop id from the injected ShopAdapterInterface,
     * not a hardcoded 1. (Refund rows are the ledger's since VR-21 F5; the failure row is the handler's.)
     */
    public function testAuditRowUsesInjectedShopId(): void
    {
        $shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $shopAdapter->method('getShopId')->willReturn('7');

        $contract = $this->makePendingContract();
        $this->contractRepository
            ->method('findByProviderOrderId')
            ->willReturn($contract);

        $capturedTransaction = null;
        $recordingRepo = $this->createMock(TransactionRepositoryInterface::class);
        $recordingRepo
            ->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (Transaction $tx) use (&$capturedTransaction): void {
                $capturedTransaction = $tx;
            });

        $handler = new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService,
            $this->orderUpdater,
            $recordingRepo,
            $shopAdapter,
            new ContractCaptureRecorder($this->contractRepository, $recordingRepo, $shopAdapter),
            $this->createMock(ProviderRefundLedgerInterface::class)
        );
        $handler->handlePaymentFailed('pi_fail_shop7', 'card_declined');

        $this->assertNotNull($capturedTransaction);
        $this->assertSame(7, $capturedTransaction->getShopId());
    }

    private function makePendingContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-shop7');
        $contract->transitionToPending();

        return $contract;
    }
}
