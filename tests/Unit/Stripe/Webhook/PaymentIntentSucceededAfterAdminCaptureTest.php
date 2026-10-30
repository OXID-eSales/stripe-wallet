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
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Service\ContractCaptureRecorder;
use OxidEsales\Payments\Stripe\Service\ContractLinkedOrderUpdaterInterface;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandler;
use PHPUnit\Framework\TestCase;

/**
 * CFG-1 S8, capture side of the refund double booking: an admin capture records the captured amount and its `cap_`
 * row (CaptureService); the payment_intent.succeeded that follows must not book the capture again - neither the
 * amount nor a second capture row. Characterisation: the guard is the contract's captured amount.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(WebhookContractFulfillmentHandler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ContractCaptureRecorder::class)]
final class PaymentIntentSucceededAfterAdminCaptureTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function aWebhookAfterAnAdminCaptureLeavesTheCapturedAmountAndRowsAlone(): void
    {
        $contract = $this->contractCapturedByAdmin(40.0);
        $contracts = $this->createMock(ContractRepositoryInterface::class);
        $contracts->method('findByProviderOrderId')->willReturn($contract);
        $contracts->expects($this->never())->method('save');
        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->expects($this->never())->method('save');
        $fulfillment = $this->createMock(ContractFulfillmentServiceInterface::class);
        $fulfillment->method('fulfillByProviderOrderId')->willReturn(true);
        $shop = $this->createMock(ShopAdapterInterface::class);
        $shop->method('getShopId')->willReturn('1');

        $handler = new WebhookContractFulfillmentHandler(
            $contracts,
            $fulfillment,
            $this->createMock(ContractLinkedOrderUpdaterInterface::class),
            $transactions,
            $shop,
            new ContractCaptureRecorder($contracts, $transactions, $shop)
        );

        $handler->handlePaymentSucceeded('pi_capture', 40.0);

        $this->assertSame(40.0, $contract->getCapturedAmount());
    }

    private function contractCapturedByAdmin(float $captured): PaymentContract
    {
        $snapshot = BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97,
            'currency' => 'EUR',
        ]);
        $contract = new PaymentContract(1, 'user-1', $snapshot);
        $contract->addCondition(new ContractCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('stripe', 'pi_capture');
        $contract->fulfillCondition(ContractCondition::TYPE_PAYMENT_AUTHORIZED);
        $contract->commitToOrder('order-1');
        $contract->setCapturedAmount($captured);

        return $contract;
    }
}
