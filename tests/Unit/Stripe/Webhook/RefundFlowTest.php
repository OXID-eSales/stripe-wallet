<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use DateTimeImmutable;
use InvalidArgumentException;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedgerInterface;
use OxidEsales\PaymentBase\Refund\RefundBooking;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeRefundRequestHandler;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Service\PaymentIntentResolver;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefund;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefundListerInterface;
use OxidEsales\Payments\Stripe\Webhook\Handler\ChargeRefundedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * VR-21 F5: every Stripe refund is booked on its contract exactly once, by Stripe's refund id, through
 * payment-base's ledger - whichever of the admin action and the `charge.refunded` webhook reports it first.
 * The ledger here is an in-memory twin of ProviderRefundLedger (dedupe by refund id, FULFILLED contracts only);
 * the real one runs against the shop database in RefundFlowIntegrationTest.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ChargeRefundedWebhookHandler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(WebhookContractFulfillmentHandler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(StripeRefundRequestHandler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(StripeWebhookEventParser::class)]
final class RefundFlowTest extends TestCase
{
    private const PAYMENT_INTENT = 'pi_money_flow';
    private const CONTRACT_ID = 'ctr-money-flow';

    private PaymentContract $contract;
    private InMemoryRefundLedger $ledger;
    private RefundServiceInterface&MockObject $refundService;
    private ChargeRefundListerInterface&MockObject $lister;
    private StripeRefundRequestHandler $admin;
    private ChargeRefundedWebhookHandler $webhook;

    protected function setUp(): void
    {
        $this->contract = $this->fulfilledContract();
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findByProviderOrderId')->willReturn($this->contract);
        $contracts->method('findById')->willReturn($this->contract);
        $this->ledger = new InMemoryRefundLedger($this->contract);
        $this->lister = $this->createMock(ChargeRefundListerInterface::class);
        $this->refundService = $this->createMock(RefundServiceInterface::class);
        $shop = $this->createMock(ShopAdapterInterface::class);
        $shop->method('getShopId')->willReturn('1');
        $transactions = $this->createMock(TransactionRepositoryInterface::class);

        $this->admin = new StripeRefundRequestHandler(
            $this->refundService,
            $this->createMock(RequestLogServiceInterface::class),
            $shop,
            $this->ledger,
            new PaymentIntentResolver($contracts),
            new \OxidEsales\PaymentBase\Logging\ThrowableSummary([])
        );
        $fulfillment = new WebhookContractFulfillmentHandler(
            $contracts,
            $this->createMock(ContractFulfillmentServiceInterface::class),
            $this->createMock(ContractLinkedOrderUpdaterInterface::class),
            $transactions,
            $shop,
            new ContractCaptureRecorder($this->createMock(ProviderCaptureLedgerInterface::class), new NullLogger()),
            $this->ledger
        );
        $this->webhook = new ChargeRefundedWebhookHandler(
            new StripeWebhookEventParser(),
            $fulfillment,
            $contracts,
            new NullLogger(),
            $this->lister
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anAdminRefundFollowedByItsWebhookIsBookedOnce(): void
    {
        $this->adminRefund('re_admin_1', 25.0);

        $this->webhook->handle($this->chargeRefunded([['re_admin_1', 2500]]));

        $this->assertSame(['re_admin_1'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aWebhookFollowedByItsAdminRefundIsBookedOnce(): void
    {
        $this->webhook->handle($this->chargeRefunded([['re_admin_1', 2500]]));

        $this->adminRefund('re_admin_1', 25.0);

        $this->assertSame(['re_admin_1'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anAdminRefundFollowedByAnEventWithoutARefundListIsBookedOnce(): void
    {
        $this->adminRefund('re_admin_1', 25.0);
        $this->lister->method('listFor')->with('ch_1')->willReturn([new ChargeRefund('re_admin_1', 25.0, 'EUR', true)]);

        $this->webhook->handle($this->chargeRefunded(null));

        $this->assertSame(['re_admin_1'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aDashboardRefundIsBookedOnceWithItsRefundIdAndAReplayAddsNothing(): void
    {
        $this->webhook->handle($this->chargeRefunded([['re_dash_1', 2500]]));
        $this->webhook->handle($this->chargeRefunded([['re_dash_1', 2500]]));

        $this->assertSame(['re_dash_1'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aDashboardRefundWithoutARefundListIsBookedByTheRefundIdStripeLists(): void
    {
        $this->lister->expects($this->exactly(2))->method('listFor')->with('ch_1')
            ->willReturn([new ChargeRefund('re_dash_1', 25.0, 'EUR', true)]);

        $this->webhook->handle($this->chargeRefunded(null));
        $this->webhook->handle($this->chargeRefunded(null));

        $this->assertSame(['re_dash_1'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(25.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anEventWhoseRefundListIsTruncatedIsCompletedFromStripe(): void
    {
        $this->lister->expects($this->once())->method('listFor')->with('ch_1')->willReturn([
            new ChargeRefund('re_1', 10.0, 'EUR', true),
            new ChargeRefund('re_2', 10.0, 'EUR', true),
        ]);

        $this->webhook->handle($this->chargeRefunded([['re_1', 1000]], hasMore: true));

        $this->assertSame(['re_1', 're_2'], $this->ledger->bookedIds());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anEventThatListsItsRefundsCompletelyDoesNotAskStripe(): void
    {
        $this->lister->expects($this->never())->method('listFor');

        $this->webhook->handle($this->chargeRefunded([['re_1', 1000]]));

        $this->assertSame(['re_1'], $this->ledger->bookedIds());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function twoDifferentRefundsOfTheSameAmountAreBothBooked(): void
    {
        $this->adminRefund('re_admin_1', 10.0);

        $this->webhook->handle($this->chargeRefunded([['re_admin_1', 1000]]));
        $this->webhook->handle($this->chargeRefunded([['re_admin_1', 1000], ['re_dash_2', 1000]]));

        $this->assertSame(['re_admin_1', 're_dash_2'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(20.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anEventCarryingSeveralRefundsBooksOnlyTheUnknownOnes(): void
    {
        $this->adminRefund('re_admin_1', 10.0);

        $this->webhook->handle($this->chargeRefunded([['re_admin_1', 1000], ['re_dash_2', 1000], ['re_dash_3', 1000]]));

        $this->assertSame(['re_admin_1', 're_dash_2', 're_dash_3'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(30.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aFailedRefundOnTheChargeIsNotBooked(): void
    {
        $this->webhook->handle($this->chargeRefunded([['re_ok', 1000, 'succeeded'], ['re_bad', 500, 'failed']]));

        $this->assertSame(['re_ok'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(10.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aLateReplayOfAnOlderEventAddsNothing(): void
    {
        $this->webhook->handle($this->chargeRefunded([['re_1', 1000], ['re_2', 1000]]));
        $this->webhook->handle($this->chargeRefunded([['re_1', 1000]]));

        $this->assertSame(['re_1', 're_2'], $this->ledger->bookedIds());
        $this->assertEqualsWithDelta(20.0, $this->contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    private function adminRefund(string $refundId, float $amount): void
    {
        $this->refundService->method('processRefund')->willReturn(RefundResponse::success(
            providerPaymentId: self::PAYMENT_INTENT,
            refundId: $refundId,
            amountRefunded: $amount,
            currency: 'eur',
            status: 'succeeded',
            refundedAt: new DateTimeImmutable()
        ));
        $context = new EventContext([
            'orderId' => 'order-1',
            'contractId' => self::CONTRACT_ID,
            'paymentIntentId' => self::PAYMENT_INTENT,
            'amount' => $amount,
        ]);

        $this->admin->handle(new StripeRefundRequestEvent($context));

        $this->assertTrue($context->get('refundSuccess'), is_string($context->get('error')) ? $context->get('error') : '');
    }

    /**
     * @param list<array{0: string, 1: int, 2?: string}>|null $refunds [id, minor-unit amount, status]; null = no list
     */
    private function chargeRefunded(?array $refunds, bool $hasMore = false): WebhookEvent
    {
        $charge = [
            'id' => 'ch_1',
            'payment_intent' => self::PAYMENT_INTENT,
            'currency' => 'eur',
            'amount_refunded' => 9999,
        ];
        if ($refunds !== null) {
            $charge['refunds'] = ['object' => 'list', 'has_more' => $hasMore, 'data' => array_map(
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
        $contract = new PaymentContract(1, 'user-1', $snapshot, self::CONTRACT_ID);
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
 * In-memory twin of payment-base's ProviderRefundLedger: one booking per Stripe refund id, the amount added to the
 * contract (which only accepts it while FULFILLED), an empty id refused.
 */
final class InMemoryRefundLedger implements ProviderRefundLedgerInterface
{
    /** @var array<string, float> */
    private array $booked = [];

    public function __construct(private readonly PaymentContract $contract)
    {
    }

    /**
     * @return list<string>
     */
    public function bookedIds(): array
    {
        return array_keys($this->booked);
    }

    public function book(
        string $contractId,
        string $providerRefundId,
        float $amount,
        string $currency,
        string $source,
        bool $succeeded
    ): RefundBooking {
        if ($providerRefundId === '') {
            throw new InvalidArgumentException('A provider refund is booked by its refund id; none was given.');
        }
        if (isset($this->booked[$providerRefundId])) {
            return RefundBooking::duplicate((float) $this->contract->getRefundedAmount());
        }
        $this->contract->addRefundedAmount($amount);
        $this->booked[$providerRefundId] = $amount;

        return RefundBooking::booked((float) $this->contract->getRefundedAmount());
    }
}
