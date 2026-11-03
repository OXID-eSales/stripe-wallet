<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Payment\CaptureBooking;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * VR-22 A5: a capture Stripe reports by webhook is booked through payment-base's ProviderCaptureLedger, keyed by the
 * PaymentIntent id (Stripe makes at most one capture per PaymentIntent) - the same id the admin's capture is booked
 * under, so whichever path reports first books it and the other changes nothing.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ContractCaptureRecorder::class)]
final class ContractCaptureRecorderTest extends TestCase
{
    private ProviderCaptureLedgerInterface&MockObject $ledger;
    private LoggerInterface&MockObject $logger;
    private ContractCaptureRecorder $recorder;

    protected function setUp(): void
    {
        $this->ledger = $this->createMock(ProviderCaptureLedgerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->recorder = new ContractCaptureRecorder($this->ledger, $this->logger);
    }

    public function testRecordBooksWhatStripeCollectedUnderThePaymentIntentId(): void
    {
        $this->ledger->expects($this->once())->method('book')
            ->with('ctr-1', 'pi_1', 19.99, 'EUR', 'webhook')
            ->willReturn(CaptureBooking::booked(19.99));

        $this->recorder->record($this->fulfilledContract(), 19.99, 'pi_1');
    }

    public function testRecordLeavesAContractThatAlreadyCarriesACaptureAlone(): void
    {
        $contract = $this->fulfilledContract();
        $contract->setCapturedAmount(42.50);
        $this->ledger->expects($this->never())->method('book');

        $this->recorder->record($contract, 99.00, 'pi_1');
    }

    /**
     * @return iterable<string, array{0: ?float}>
     */
    public static function unusableAmounts(): iterable
    {
        yield 'missing' => [null];
        yield 'zero' => [0.0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableAmounts')]
    public function testRecordWithZeroOrMissingAmountLogsAnErrorAndBooksNothing(?float $amount): void
    {
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('Capture not booked'));
        $this->ledger->expects($this->never())->method('book');

        $this->recorder->record($this->fulfilledContract(), $amount, 'pi_1');
    }

    private function fulfilledContract(): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [],
            'discounts' => [],
            'totalGross' => 100.0,
            'totalNet' => 84.03,
            'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot, 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $contract->fulfill();

        return $contract;
    }
}
