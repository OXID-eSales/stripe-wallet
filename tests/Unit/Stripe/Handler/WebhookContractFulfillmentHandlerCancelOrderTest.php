<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Handler;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 112 / G1: cancelled & failed contract transitions must mirror onto
 * the linked oxorder row so cancelled orders no longer look paid in the admin
 * order list.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler::class)]
class WebhookContractFulfillmentHandlerCancelOrderTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;
    private ContractFulfillmentServiceInterface $contractFulfillmentService;
    private RecordingOrderUpdater $orderUpdater;
    private TransactionRepositoryInterface $transactionRepository;
    private ShopAdapterInterface $shopAdapter;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->contractFulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->orderUpdater = new RecordingOrderUpdater();
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
    }

    public function testHandlePaymentCanceledMarksLinkedOrderAsCancelled(): void
    {
        $contract = $this->makeContractWithOrderId('order-uuid-cancel');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentCanceled('pi_cancel', 'requested_by_customer');

        $this->assertTrue($result);
        $this->assertSame(['cancelled:order-uuid-cancel'], $this->orderUpdater->calls);
    }

    public function testHandlePaymentFailedMarksLinkedOrderAsFailed(): void
    {
        $contract = $this->makeContractWithOrderId('order-uuid-fail');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentFailed('pi_fail', 'card_declined');

        $this->assertTrue($result);
        $this->assertSame(['failed:order-uuid-fail:card_declined'], $this->orderUpdater->calls);
    }

    public function testHandlePaymentCanceledOnContractWithoutLinkedOrderSkipsUpdater(): void
    {
        $contract = $this->makeContractWithoutOrder();
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentCanceled('pi_no_order', '');

        $this->assertTrue($result);
        $this->assertSame([], $this->orderUpdater->calls, 'no linked order → updater not called');
    }

    public function testHandlePaymentCanceledDoesNotCallUpdaterWhenContractAlreadyTerminal(): void
    {
        $contract = $this->makeContractWithOrderId('order-uuid');
        $contract->cancel('already cancelled earlier');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentCanceled('pi_terminal', '');

        $this->assertFalse($result, 'state guard hits → no transition');
        $this->assertSame([], $this->orderUpdater->calls);
    }

    public function testHandlePaymentCanceledWithoutMatchingContractDoesNotCallUpdater(): void
    {
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);

        $handler = $this->makeHandler();
        $result = $handler->handlePaymentCanceled('pi_missing', '');

        $this->assertNull($result);
        $this->assertSame([], $this->orderUpdater->calls);
    }

    /**
     * STRP-168. checkout.session.expired used to expire the contract and stop
     * there, unlike its cancel and fail siblings which both mirror onto the
     * order. The order stayed at NOT_FINISHED with its vouchers spent — and
     * because every contract-keyed cleanup path skips terminal states, the act
     * of recording the expiry was what put the order out of reach.
     */
    public function testHandleSessionExpiredMarksLinkedOrderAsCancelled(): void
    {
        $contract = $this->makeContractWithOrderId('order-uuid-expired');
        $this->contractRepository->method('findById')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handleSessionExpired('contract-expired');

        $this->assertTrue($result);
        $this->assertSame(['cancelled:order-uuid-expired'], $this->orderUpdater->calls);
    }

    public function testHandleSessionExpiredOnContractWithoutLinkedOrderSkipsUpdater(): void
    {
        $contract = $this->makeContractWithoutOrder();
        $this->contractRepository->method('findById')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handleSessionExpired('contract-no-order');

        $this->assertTrue($result);
        $this->assertSame([], $this->orderUpdater->calls, 'no linked order -> updater not called');
    }

    /**
     * An already-settled contract keeps its ending; a late duplicate webhook
     * must not reach in and storno an order that was paid for.
     */
    public function testHandleSessionExpiredDoesNotTouchOrderWhenContractAlreadyTerminal(): void
    {
        $contract = $this->makeContractWithOrderId('order-uuid');
        $contract->cancel('already cancelled earlier');
        $this->contractRepository->method('findById')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handleSessionExpired('contract-terminal');

        $this->assertFalse($result);
        $this->assertSame([], $this->orderUpdater->calls);
    }

    public function testHandleSessionExpiredWithoutMatchingContractDoesNotCallUpdater(): void
    {
        $this->contractRepository->method('findById')->willReturn(null);

        $handler = $this->makeHandler();
        $result = $handler->handleSessionExpired('contract-missing');

        $this->assertNull($result);
        $this->assertSame([], $this->orderUpdater->calls);
    }

    /**
     * STRP-168 item 6. `committed` is not a terminal state, so a late or
     * duplicate checkout.session.expired for an already-paid contract used to
     * sail past the guard. With the mirror in place that would have stornoed a
     * paid order; the state machine now refuses the transition outright, so the
     * handler has to skip it rather than throw out of a webhook.
     */
    public function testHandleSessionExpiredSkipsAContractWhosePaymentWasTaken(): void
    {
        $contract = $this->makeContractWithOrderId('order-uuid-paid');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-uuid-paid');
        $this->contractRepository->method('findById')->willReturn($contract);

        $handler = $this->makeHandler();
        $result = $handler->handleSessionExpired('contract-committed');

        $this->assertFalse($result);
        $this->assertSame('committed', $contract->getStateValue(), 'a paid contract keeps its state');
        $this->assertSame([], $this->orderUpdater->calls, 'and its order is left alone');
    }

    private function makeHandler(): WebhookContractFulfillmentHandler
    {
        return new WebhookContractFulfillmentHandler(
            $this->contractRepository,
            $this->contractFulfillmentService,
            $this->orderUpdater,
            $this->transactionRepository,
            $this->shopAdapter
        );
    }

    private function makeContractWithOrderId(string $orderId): PaymentContract
    {
        $contract = $this->makeContractWithoutOrder();
        $contract->transitionToNotFinished($orderId);

        return $contract;
    }

    private function makeContractWithoutOrder(): PaymentContract
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

        return $contract;
    }
}

/**
 * @internal
 */
final class RecordingOrderUpdater implements ContractLinkedOrderUpdaterInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function markCancelled(string $orderId): void
    {
        $this->calls[] = "cancelled:{$orderId}";
    }

    public function markFailed(string $orderId, string $reason): void
    {
        $this->calls[] = "failed:{$orderId}:{$reason}";
    }
}
