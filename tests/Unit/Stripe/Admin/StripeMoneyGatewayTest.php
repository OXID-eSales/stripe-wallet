<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Response\CancellationResponse;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\PaymentBase\Admin\Panel\AdminActionRefusedException;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractCondition;
use OxidEsales\PaymentBase\Contract\PaymentContract;
use OxidEsales\PaymentBase\Payment\CancelGatewayInterface;
use OxidEsales\PaymentBase\Payment\CancelRequest;
use OxidEsales\PaymentBase\Payment\CaptureGatewayInterface;
use OxidEsales\PaymentBase\Payment\CaptureRequest;
use OxidEsales\PaymentBase\Payment\RefundGatewayInterface;
use OxidEsales\PaymentBase\Payment\RefundRequest;
use OxidEsales\PaymentBase\Payment\RefundResult;
use OxidEsales\Payments\Stripe\Admin\StripeMoneyGateway;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationServiceInterface;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * VR-22 A5: Stripe's side of the one admin money path. The runner owns policy, idempotency, lock and booking; this
 * class only talks to Stripe and turns a Stripe refusal into a sentence the admin may read.
 */
final class StripeMoneyGatewayTest extends TestCase
{
    private CaptureServiceInterface&MockObject $captures;
    private RefundServiceInterface&MockObject $refunds;
    private CancelAuthorizationServiceInterface&MockObject $cancellations;

    protected function setUp(): void
    {
        $this->captures = $this->createMock(CaptureServiceInterface::class);
        $this->refunds = $this->createMock(RefundServiceInterface::class);
        $this->cancellations = $this->createMock(CancelAuthorizationServiceInterface::class);
    }

    public function testItIsTheStripeGatewayForAllThreeOperations(): void
    {
        $gateway = $this->gateway();

        self::assertInstanceOf(CaptureGatewayInterface::class, $gateway);
        self::assertInstanceOf(RefundGatewayInterface::class, $gateway);
        self::assertInstanceOf(CancelGatewayInterface::class, $gateway);
        self::assertSame('stripe', $gateway->providerName());
    }

    public function testCaptureAsksStripeForTheAmountAndReturnsTheCaptureUnderThePaymentIntentId(): void
    {
        $this->captures->expects($this->once())->method('captureAtStripe')
            ->with('pi_1', 40.0, 'EUR', ['initiator' => 'admin', 'contract_id' => 'ctr-1', 'reason' => 'shipped'])
            ->willReturn(CaptureResponse::success('pi_1', 'ch_1', 40.0, 'EUR', 'captured', new DateTimeImmutable()));

        $result = $this->gateway()->capture(new CaptureRequest($this->contract(), 40.0, 'EUR', 'key-1', 'shipped'));

        // Stripe captures a PaymentIntent once; the PaymentIntent id is the capture id every path books under
        self::assertSame('pi_1', $result->providerCaptureId);
        self::assertSame(40.0, $result->amount);
    }

    public function testCaptureOmitsAbsentReasonFromTheMetadata(): void
    {
        $this->captures->expects($this->once())->method('captureAtStripe')
            ->with('pi_1', 100.0, 'EUR', ['initiator' => 'admin', 'contract_id' => 'ctr-1'])
            ->willReturn(CaptureResponse::success('pi_1', 'ch_1', 100.0, 'EUR', 'captured', new DateTimeImmutable()));

        $this->gateway()->capture(new CaptureRequest($this->contract(), 100.0, 'EUR', 'key-1'));
    }

    public function testAStripeRefusalOfTheCaptureIsAnAdminSentenceWithoutIdsOrStripeText(): void
    {
        $this->captures->method('captureAtStripe')->willReturn(
            CaptureResponse::failure('PaymentIntent pi_1 of acct sk_live_secret could not be captured', 'payment_intent_unexpected_state')
        );

        try {
            $this->gateway()->capture(new CaptureRequest($this->contract(), 40.0, 'EUR', 'key-1'));
            self::fail('A refusal was expected');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('payment_intent_unexpected_state', $refusal->refusalCode());
            self::assertStringContainsString('Stripe', $refusal->getMessage());
            self::assertStringNotContainsString('pi_1', $refusal->getMessage());
            self::assertStringNotContainsString('sk_live', $refusal->getMessage());
        }
    }

    public function testAContractWithoutAPaymentIntentIsRefusedBeforeStripeIsAsked(): void
    {
        $this->captures->expects($this->never())->method('captureAtStripe');

        $this->expectException(AdminActionRefusedException::class);

        $this->gateway()->capture(new CaptureRequest($this->contract('cs_1'), 40.0, 'EUR', 'key-1'));
    }

    public function testRefundBooksWhatStripeRefundedWithStripesOwnStatus(): void
    {
        $this->refunds->expects($this->once())->method('processRefund')
            ->with('order-1', 'pi_1', 'duplicate', 'double charge', 'admin', 25.5)
            ->willReturn(RefundResponse::success('pi_1', 're_1', 25.5, 'EUR', 'succeeded', new DateTimeImmutable()));

        $result = $this->gateway()->refund(new RefundRequest($this->contract(), 25.5, 'EUR', 'key-1', null, 'duplicate', 'double charge'));

        self::assertSame('re_1', $result->providerRefundId);
        self::assertSame(25.5, $result->amount);
        self::assertSame(RefundResult::SUCCEEDED, $result->status);
    }

    public function testAPendingStripeRefundIsPending(): void
    {
        $this->refunds->method('processRefund')
            ->willReturn(RefundResponse::success('pi_1', 're_1', 10.0, 'EUR', 'pending', new DateTimeImmutable()));

        $result = $this->gateway()->refund(new RefundRequest($this->contract(), 10.0, 'EUR', 'key-1'));

        self::assertSame(RefundResult::PENDING, $result->status);
    }

    public function testAStripeRefusalOfTheRefundIsAnAdminSentence(): void
    {
        $this->refunds->method('processRefund')->willReturn(RefundResponse::failure('Charge ch_1 is already fully refunded', 'charge_already_refunded'));

        try {
            $this->gateway()->refund(new RefundRequest($this->contract(), 10.0, 'EUR', 'key-1'));
            self::fail('A refusal was expected');
        } catch (AdminActionRefusedException $refusal) {
            self::assertSame('charge_already_refunded', $refusal->refusalCode());
            self::assertStringNotContainsString('ch_1', $refusal->getMessage());
        }
    }

    public function testAContractWithoutAnOrderCannotBeRefunded(): void
    {
        $this->refunds->expects($this->never())->method('processRefund');
        $contract = new PaymentContract(1, 'user-1', $this->basket(), 'ctr-1');
        $contract->setProvider('stripe', 'pi_1');

        $this->expectException(AdminActionRefusedException::class);

        $this->gateway()->refund(new RefundRequest($contract, 10.0, 'EUR', 'key-1'));
    }

    public function testCancelVoidsTheAuthorizationWithTheReason(): void
    {
        $this->cancellations->expects($this->once())->method('cancelAuthorization')
            ->with('pi_1', 'requested_by_customer')
            ->willReturn(CancellationResponse::success('pi_1', 'pi_1', 'canceled', new DateTimeImmutable()));

        $this->gateway()->cancel(new CancelRequest($this->contract(), 'key-1', 'requested_by_customer'));
    }

    public function testAStripeRefusalOfTheCancelIsAnAdminSentence(): void
    {
        $this->cancellations->method('cancelAuthorization')->willReturn(CancellationResponse::failure('pi_1 cannot be canceled', 'payment_intent_unexpected_state'));

        $this->expectException(AdminActionRefusedException::class);

        $this->gateway()->cancel(new CancelRequest($this->contract(), 'key-1'));
    }

    private function gateway(): StripeMoneyGateway
    {
        return new StripeMoneyGateway($this->captures, $this->refunds, $this->cancellations);
    }

    private function contract(string $providerOrderId = 'pi_1'): PaymentContract
    {
        $contract = new PaymentContract(1, 'user-1', $this->basket(), 'ctr-1');
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->setProvider('stripe', $providerOrderId);

        return $contract;
    }

    private function basket(): BasketSnapshot
    {
        return BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]);
    }
}
