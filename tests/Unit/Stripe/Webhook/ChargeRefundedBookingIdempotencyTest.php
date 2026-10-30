<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Service\ContractRefundRecorder;
use OxidEsales\Payments\Stripe\Webhook\Handler\ChargeRefundedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * CFG-1 S8: an admin refund books the contract and a `re_...` row; Stripe then sends charge.refunded for that same
 * refund. The webhook must not book it a second time, and must still book refunds the module has not seen.
 * Idempotency is by Stripe's refund id (and by Stripe's cumulative amount_refunded when an event carries no list),
 * never by "an amount like this was booked already".
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ChargeRefundedWebhookHandler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(WebhookContractFulfillmentHandler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(StripeWebhookEventParser::class)]
final class ChargeRefundedBookingIdempotencyTest extends TestCase
{
    private const PAYMENT_INTENT = 'pi_money_flow';

    private PaymentContract $contract;
    private InMemoryTransactionRepository $transactions;
    private ContractRefundRecorder $adminRecorder;
    private ChargeRefundedWebhookHandler $webhook;

    protected function setUp(): void
    {
        $this->contract = $this->fulfilledContract();
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findByProviderOrderId')->willReturn($this->contract);
        $this->transactions = new InMemoryTransactionRepository();
        $shop = $this->createMock(ShopAdapterInterface::class);
        $shop->method('getShopId')->willReturn('1');
        $recorder = new ContractRefundRecorder($contracts, new NullLogger(), $this->transactions);
        $this->adminRecorder = $recorder;

        $fulfillment = new WebhookContractFulfillmentHandler(
            $contracts,
            $this->createMock(ContractFulfillmentServiceInterface::class),
            $this->createMock(ContractLinkedOrderUpdaterInterface::class),
            $this->transactions,
            $shop,
            new ContractCaptureRecorder($contracts, $this->transactions, $shop),
            $recorder
        );
        $this->webhook = new ChargeRefundedWebhookHandler(
            new StripeWebhookEventParser(),
            $fulfillment,
            $contracts,
            new NullLogger()
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anAdminRefundFollowedByItsWebhookIsBookedOnce(): void
    {
        $this->adminRefund('re_admin_1', 25.0);

        $this->webhook->handle($this->chargeRefunded(2500, [['re_admin_1', 2500]]));

        $this->assertSame(['re_admin_1'], $this->refundReferences());
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anAdminRefundFollowedByAnEventWithoutARefundListIsBookedOnce(): void
    {
        $this->adminRefund('re_admin_1', 25.0);

        $this->webhook->handle($this->chargeRefunded(2500, null));

        $this->assertSame(['re_admin_1'], $this->refundReferences());
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aRefundMadeInTheDashboardIsBookedOnceWithItsRefundId(): void
    {
        $this->webhook->handle($this->chargeRefunded(2500, [['re_dash_1', 2500]]));
        $this->webhook->handle($this->chargeRefunded(2500, [['re_dash_1', 2500]]));

        $this->assertSame(['re_dash_1'], $this->refundReferences());
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aDashboardRefundWithoutARefundListIsBookedOnceAndAReplayAddsNothing(): void
    {
        $event = $this->chargeRefunded(2500, null);

        $this->webhook->handle($event);
        $this->webhook->handle($event);

        $this->assertCount(1, $this->transactions->rowsOfType('refund'));
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function twoDifferentRefundsOfTheSameAmountAreBothBooked(): void
    {
        $this->adminRefund('re_admin_1', 10.0);

        $this->webhook->handle($this->chargeRefunded(1000, [['re_admin_1', 1000]]));
        $this->webhook->handle($this->chargeRefunded(2000, [['re_admin_1', 1000], ['re_dash_2', 1000]]));

        $this->assertSame(['re_admin_1', 're_dash_2'], $this->refundReferences());
        $this->assertEqualsWithDelta(20.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function twoRefundsOfTheSameAmountWithoutARefundListAreBothBooked(): void
    {
        $this->adminRefund('re_admin_1', 10.0);

        $this->webhook->handle($this->chargeRefunded(1000, null));
        $this->webhook->handle($this->chargeRefunded(2000, null));

        $this->assertCount(2, $this->transactions->rowsOfType('refund'));
        $this->assertEqualsWithDelta(20.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anEventCarryingSeveralRefundsBooksOnlyTheUnknownOnes(): void
    {
        $this->adminRefund('re_admin_1', 10.0);

        $this->webhook->handle($this->chargeRefunded(
            3000,
            [['re_admin_1', 1000], ['re_dash_2', 1000], ['re_dash_3', 1000]]
        ));

        $this->assertSame(['re_admin_1', 're_dash_2', 're_dash_3'], $this->refundReferences());
        $this->assertEqualsWithDelta(30.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aFailedRefundOnTheChargeIsNotBooked(): void
    {
        $this->webhook->handle($this->chargeRefunded(1000, [['re_ok', 1000, 'succeeded'], ['re_bad', 500, 'failed']]));

        $this->assertSame(['re_ok'], $this->refundReferences());
        $this->assertEqualsWithDelta(10.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aLateReplayOfAnOlderEventAddsNothing(): void
    {
        $this->webhook->handle($this->chargeRefunded(2000, [['re_1', 1000], ['re_2', 1000]]));
        $this->webhook->handle($this->chargeRefunded(1000, [['re_1', 1000]]));

        $this->assertSame(['re_1', 're_2'], $this->refundReferences());
        $this->assertEqualsWithDelta(20.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    private function adminRefund(string $refundId, float $amount): void
    {
        $this->adminRecorder->record($this->contract, $amount, 'ctr', $refundId, 'requested_by_customer');
    }

    /**
     * @return list<string> Stripe refund ids the transaction table lists, in booking order
     */
    private function refundReferences(): array
    {
        return array_values(array_filter(array_map(
            static fn (Transaction $row): ?string => $row->getTransactionId(),
            $this->transactions->rowsOfType('refund')
        )));
    }

    /**
     * @param list<array{0: string, 1: int, 2?: string}>|null $refunds [id, minor-unit amount, status]; null = no list
     */
    private function chargeRefunded(int $amountRefunded, ?array $refunds): WebhookEvent
    {
        $charge = [
            'id' => 'ch_1',
            'payment_intent' => self::PAYMENT_INTENT,
            'currency' => 'eur',
            'amount_refunded' => $amountRefunded,
        ];
        if ($refunds !== null) {
            $charge['refunds'] = ['object' => 'list', 'has_more' => false, 'data' => array_map(
                static fn (array $refund): array => [
                    'id' => $refund[0],
                    'amount' => $refund[1],
                    'currency' => 'eur',
                    'status' => $refund[2] ?? 'succeeded',
                ],
                $refunds
            )];
        }

        return new WebhookEvent(
            id: 'evt_' . bin2hex(random_bytes(4)),
            type: 'charge.refunded',
            data: ['object' => $charge],
            created: time()
        );
    }

    private function fulfilledContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('stripe', self::PAYMENT_INTENT);
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $contract->fulfill();

        return $contract;
    }
}

/**
 * Behaves like DoctrineTransactionRepository for the calls these tests make: logRefund stores the refund id as the
 * provider transaction id, findByProviderTransactionId finds by it.
 */
final class InMemoryTransactionRepository implements TransactionRepositoryInterface
{
    /** @var list<Transaction> */
    private array $rows = [];

    /**
     * @return list<Transaction>
     */
    public function rowsOfType(string $type): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (Transaction $row): bool => $row->getType() === $type
        ));
    }

    public function save(Transaction $transaction): void
    {
        $this->rows[] = $transaction;
    }

    public function findById(string $id): ?Transaction
    {
        return null;
    }

    public function findByOrderId(string $orderId): array
    {
        return [];
    }

    public function findByContractId(string $contractId): array
    {
        return $this->rows;
    }

    public function findByProviderTransactionId(string $transactionId): ?Transaction
    {
        foreach ($this->rows as $row) {
            if ($row->getTransactionId() === $transactionId) {
                return $row;
            }
        }

        return null;
    }

    public function findByTypeAndStatus(string $type, string $status): array
    {
        return [];
    }

    public function findChildTransactions(string $parentTransactionId): array
    {
        return [];
    }

    public function exists(string $id): bool
    {
        return false;
    }

    public function getTotalRefundedForContract(string $contractId): float
    {
        return 0.0;
    }

    public function logRefund(string $contractId, float $amount, string $refundId, string $reason): void
    {
        $row = new Transaction('refund_' . bin2hex(random_bytes(8)), 1, 'order-1', $contractId, 'stripe', 'refund', 'completed', $amount, 'EUR');
        $row->setTransactionId($refundId);
        $this->save($row);
    }
}
