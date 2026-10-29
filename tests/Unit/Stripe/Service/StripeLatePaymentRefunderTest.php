<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Request\RefundPaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Contract\AttemptClosure;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Contract\ReplacedAttempts;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeLatePaymentRefunder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * OPC-175 / S2g — money that arrives on a Stripe attempt the customer left for
 * another payment method goes back to the customer, once, and the contract's
 * history records it.
 */
final class StripeLatePaymentRefunderTest extends TestCase
{
    private StripeAdapterInterface&MockObject $adapter;
    private ContractRepositoryInterface&MockObject $contracts;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
        $this->contracts = $this->createMock(ContractRepositoryInterface::class);
    }

    public function testSettle_PaidIntent_IsRefundedInFullAndRecorded(): void
    {
        $contract = $this->contractThatReplacedAStripeAttempt();
        $this->adapter->method('retrievePaymentIntent')->with('pi_1')->willReturn($this->intent('succeeded'));
        $this->adapter->expects($this->once())->method('refundPayment')
            ->with($this->callback(static fn (RefundPaymentRequest $r): bool => $r->providerPaymentId === 'pi_1' && $r->amount === null))
            ->willReturn(RefundResponse::success('pi_1', 're_1', 42.0, 'EUR', 'succeeded', new \DateTimeImmutable()));
        $this->contracts->expects($this->once())->method('save')->with($contract);

        $this->refunder()->settle($contract, 0, 'pi_1');

        self::assertSame('refunded:re_1', ReplacedAttempts::lateMoneyIn($contract->getMetadata(ReplacedAttempts::METADATA_KEY), 0));
    }

    public function testSettle_AuthorizedIntent_IsReleasedAndRecorded(): void
    {
        $contract = $this->contractThatReplacedAStripeAttempt();
        $this->adapter->method('retrievePaymentIntent')->willReturn($this->intent('requires_capture'));
        $this->adapter->expects($this->once())->method('cancelPaymentIntent')->with('pi_1')->willReturn($this->intent('canceled'));
        $this->adapter->expects($this->never())->method('refundPayment');

        $this->refunder()->settle($contract, 0, 'pi_1');

        self::assertSame('released', ReplacedAttempts::lateMoneyIn($contract->getMetadata(ReplacedAttempts::METADATA_KEY), 0));
    }

    public function testSettle_RefusedRefund_IsRecordedAsFailed(): void
    {
        $contract = $this->contractThatReplacedAStripeAttempt();
        $this->adapter->method('retrievePaymentIntent')->willReturn($this->intent('succeeded'));
        $this->adapter->method('refundPayment')->willReturn(RefundResponse::failure('charge_disputed'));

        $this->refunder()->settle($contract, 0, 'pi_1');

        self::assertSame('refund_failed:charge_disputed', ReplacedAttempts::lateMoneyIn($contract->getMetadata(ReplacedAttempts::METADATA_KEY), 0));
    }

    public function testSettle_IntentWithoutMoneyYet_RecordsNothing(): void
    {
        $contract = $this->contractThatReplacedAStripeAttempt();
        $this->adapter->method('retrievePaymentIntent')->willReturn($this->intent('processing'));
        $this->contracts->expects($this->never())->method('save');

        $this->refunder()->settle($contract, 0, 'pi_1');

        self::assertNull(ReplacedAttempts::lateMoneyIn($contract->getMetadata(ReplacedAttempts::METADATA_KEY), 0));
    }

    /** Stripe sends checkout.session.completed AND payment_intent.succeeded for one payment. */
    public function testSettle_AnAttemptAlreadySettled_IsNotRefundedTwice(): void
    {
        $contract = $this->contractThatReplacedAStripeAttempt();
        $contract->setMetadata(ReplacedAttempts::METADATA_KEY, ReplacedAttempts::withLateMoney(
            $contract->getMetadata(ReplacedAttempts::METADATA_KEY),
            0,
            'refunded:re_1'
        ));
        $this->adapter->expects($this->never())->method('retrievePaymentIntent');
        $this->adapter->expects($this->never())->method('refundPayment');
        $this->contracts->expects($this->never())->method('save');

        $this->refunder()->settle($contract, 0, 'pi_1');
    }

    private function refunder(): StripeLatePaymentRefunder
    {
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($this->adapter);

        return new StripeLatePaymentRefunder($factory, $this->contracts, new NullLogger());
    }

    private function intent(string $status): StripePaymentIntentDto
    {
        return new StripePaymentIntentDto('pi_1', $status, 4200, 'eur', 0, null);
    }

    private function contractThatReplacedAStripeAttempt(): PaymentContract
    {
        $contract = new PaymentContract(1, 'u-1', BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 42.0, 'totalNet' => 42.0, 'totalVat' => 0.0, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'cs_1');
        $contract->reopenForNewAttempt(AttemptClosure::Closed, $contract->getBasketSnapshot(), [ContractCondition::TYPE_PAYMENT_AUTHORIZED]);

        return $contract;
    }
}
