<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Database\ConnectionProviderInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\EventSystem\Event\EventContext;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Refund\ProviderRefundLedger;
use OxidEsales\PaymentBase\Refund\RefundSettledPolicy;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use OxidEsales\PaymentBase\Repository\DoctrineContractRepository;
use OxidEsales\PaymentBase\Repository\DoctrineTransactionRepository;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeRefundRequestEvent;
use OxidEsales\Payments\Stripe\EventSystem\Handler\StripeRefundRequestHandler;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\PaymentBase\Contract\ContractTerminationServiceInterface;
use OxidEsales\Payments\Stripe\Service\PaymentIntentResolver;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\ChargeRefundListerInterface;
use OxidEsales\Payments\Stripe\Webhook\Handler\ChargeRefundedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;

/**
 * VR-21 F5 on the shop database, with payment-base's real ledger: the admin refund and Stripe's `charge.refunded`
 * webhook report the same refund; in either order it is booked once - one `refund` transaction row keyed by
 * Stripe's refund id, the amount on the contract once, and the amount is Stripe's, not the authorized one.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('vr-21')]
final class RefundFlowIntegrationTest extends IntegrationTestCase
{
    private const SHOP_ID = 1;
    private const TEST_PREFIX = 'ox_test_rf_';

    private Connection $connection;
    private DoctrineContractRepository $contracts;
    private string $contractId;
    private string $paymentIntentId;
    private ChargeRefundedWebhookHandler $webhook;
    private StripeRefundRequestHandler $admin;
    private RefundServiceInterface&MockObject $refundService;

    public function setUp(): void
    {
        parent::setUp();

        $container = ContainerFactory::getInstance()->getContainer();
        /** @var ConnectionProviderInterface $connectionProvider */
        $connectionProvider = $container->get(ConnectionProviderInterface::class);
        $this->connection = $connectionProvider->get();
        $this->contracts = new DoctrineContractRepository($this->connection, new NullLogger(), new ThrowableSummary([]));
        $transactions = new DoctrineTransactionRepository($this->connection);
        $ledger = new ProviderRefundLedger(
            $this->connection,
            $this->contracts,
            $transactions,
            new RefundSettledPolicy(new class implements StockRestorationServiceInterface {
                public function restoreForFullRefund(string $orderId): int
                {
                    return 0;
                }
            }),
            new NullLogger(),
        );

        $runId = date('His') . substr(uniqid(), -4);
        $this->contractId = substr(self::TEST_PREFIX . $runId, 0, 32);
        $this->paymentIntentId = 'pi_rf_' . $runId;
        $this->saveFulfilledContract(100.0);

        $shop = $this->createMock(ShopAdapterInterface::class);
        $shop->method('getShopId')->willReturn((string) self::SHOP_ID);
        $this->refundService = $this->createMock(RefundServiceInterface::class);
        $this->admin = new StripeRefundRequestHandler(
            $this->refundService,
            $this->createMock(RequestLogServiceInterface::class),
            $shop,
            $ledger,
            new PaymentIntentResolver($this->contracts),
            new \OxidEsales\PaymentBase\Logging\ThrowableSummary([])
        );
        $this->webhook = new ChargeRefundedWebhookHandler(
            new StripeWebhookEventParser(),
            new WebhookContractFulfillmentHandler(
                $this->contracts,
                $this->createMock(ContractFulfillmentServiceInterface::class),
                $this->createMock(ContractTerminationServiceInterface::class),
                $transactions,
                $shop,
                new ContractCaptureRecorder($this->createMock(ProviderCaptureLedgerInterface::class), new NullLogger()),
                $ledger
            ),
            $this->contracts,
            new NullLogger(),
            $this->createMock(ChargeRefundListerInterface::class)
        );
    }

    public function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM oe_payments_transaction WHERE OXCONTRACTID = ?', [$this->contractId]);
        $this->connection->executeStatement('DELETE FROM oe_payments_contract WHERE OXID = ?', [$this->contractId]);
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function anAdminRefundThenItsWebhookBooksOnce(): void
    {
        $this->adminRefund('re_it_1', 25.0);

        $this->webhook->handle($this->chargeRefunded('re_it_1', 2500));

        $this->assertBookedOnce('re_it_1', 25.0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aWebhookThenItsAdminRefundBooksOnce(): void
    {
        $this->webhook->handle($this->chargeRefunded('re_it_2', 2500));

        $this->adminRefund('re_it_2', 25.0);

        $this->assertBookedOnce('re_it_2', 25.0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aFullRefundAfterAPartialCaptureBooksStripesAmountNotTheAuthorizedOne(): void
    {
        // 100.00 authorized, 60.00 captured: Stripe's full refund is 60.00.
        $this->adminRefund('re_it_3', 60.0, amount: null);

        $this->assertBookedOnce('re_it_3', 60.0);
    }

    private function assertBookedOnce(string $refundId, float $amount): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT OXTRANSACTIONID, OXAMOUNT FROM oe_payments_transaction WHERE OXCONTRACTID = ? AND OXTYPE = ?',
            [$this->contractId, 'refund']
        );
        $this->assertCount(1, $rows);
        $this->assertSame($refundId, $rows[0]['OXTRANSACTIONID'] ?? null);
        $stored = $rows[0]['OXAMOUNT'] ?? null;
        $this->assertIsNumeric($stored);
        $this->assertEqualsWithDelta($amount, (float) $stored, 0.001);
        $contract = $this->contracts->findById($this->contractId);
        $this->assertNotNull($contract);
        $this->assertEqualsWithDelta($amount, $contract->getRefundedAmount() ?? 0.0, 0.001);
    }

    private function adminRefund(string $refundId, float $refunded, ?float $amount = 25.0): void
    {
        $this->refundService->method('processRefund')->willReturn(RefundResponse::success(
            providerPaymentId: $this->paymentIntentId,
            refundId: $refundId,
            amountRefunded: $refunded,
            currency: 'eur',
            status: 'succeeded',
            refundedAt: new DateTimeImmutable()
        ));
        $context = new EventContext([
            'orderId' => 'ox_test_order_rf',
            'contractId' => $this->contractId,
            'paymentIntentId' => $this->paymentIntentId,
            'amount' => $amount,
        ]);

        $this->admin->handle(new StripeRefundRequestEvent($context));

        $this->assertTrue($context->get('refundSuccess'), is_string($context->get('error')) ? $context->get('error') : '');
    }

    private function chargeRefunded(string $refundId, int $minorUnits): WebhookEvent
    {
        return new WebhookEvent(
            id: 'evt_' . bin2hex(random_bytes(4)),
            type: 'charge.refunded',
            data: ['object' => [
                'id' => 'ch_rf',
                'payment_intent' => $this->paymentIntentId,
                'currency' => 'eur',
                'amount_refunded' => $minorUnits,
                'refunds' => ['has_more' => false, 'data' => [
                    ['id' => $refundId, 'amount' => $minorUnits, 'currency' => 'eur', 'status' => 'succeeded'],
                ]],
            ]],
            created: time()
        );
    }

    private function saveFulfilledContract(float $authorized): void
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => $authorized, 'totalNet' => 84.0, 'totalVat' => 16.0,
            'currency' => 'EUR', 'capturedAt' => date('Y-m-d H:i:s'),
        ]);
        $contract = new PaymentContract(self::SHOP_ID, 'ox_test_user', $snapshot, $this->contractId);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('ox_test_order_rf');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $this->paymentIntentId);
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('ox_test_order_rf');
        $contract->fulfill();
        $contract->setCapturedAmount(60.0);
        $this->contracts->save($contract);
    }
}
