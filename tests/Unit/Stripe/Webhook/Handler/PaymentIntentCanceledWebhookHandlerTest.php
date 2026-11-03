<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook\Handler;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentCanceledWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\Handler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentCanceledWebhookHandler::class)]
final class PaymentIntentCanceledWebhookHandlerTest extends TestCase
{
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private PaymentIntentCanceledWebhookHandler $handler;

    protected function setUp(): void
    {
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->handler = new PaymentIntentCanceledWebhookHandler(
            new StripeWebhookEventParser(),
            $this->fulfillmentHandler,
            $this->contractRepository,
            $this->createMock(LoggerInterface::class)
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function supportsPaymentIntentCanceledEventType(): void
    {
        $this->assertTrue($this->handler->supports('payment_intent.canceled'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('payment_intent.payment_failed'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function missingPaymentIntentId_returnsFailure(): void
    {
        $outcome = $this->handler->handle($this->makeEvent([]));

        $this->assertFalse($outcome->result->isSuccess());
        $this->assertNull($outcome->contractId);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function handlerReturnsTrue_propagatesCancellationReason_returnsContractCancelled(): void
    {
        $event = $this->makeEvent([
            'id' => 'pi_cancel',
            'cancellation_reason' => 'requested_by_customer',
        ]);

        $this->fulfillmentHandler->method('handlePaymentCanceled')
            ->with('pi_cancel', 'requested_by_customer')
            ->willReturn(true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-cancel');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        $outcome = $this->handler->handle($event);

        $this->assertSame('contract_cancelled', $outcome->result->action);
        $this->assertSame('ctr-cancel', $outcome->contractId);
    }

    /**
     * VR-23 T3 (S-2 / L-2): a Checkout Session contract still carries the session id as its provider order id
     * when payment_intent.canceled arrives, so the intent id finds nothing. Stripe echoes the contract id in the intent's
     * metadata: the contract is resolved by it, never left in flight for the stale sweep to cancel alone.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function resolvesContractViaMetadataWhileProviderIdIsCheckoutSession(): void
    {
        $contract = $this->contractWithProviderId('cs_1');
        $this->contractRepository->method('findByProviderOrderId')->willReturnMap([['pi_1', null], ['cs_1', $contract]]);
        $this->contractRepository->method('findById')->with('ctr-1')->willReturn($contract);
        $this->fulfillmentHandler->method('handlePaymentCanceled')->willReturnMap([
            ['pi_1', 'requested_by_customer', null],
            ['cs_1', 'requested_by_customer', true],
        ]);

        $outcome = $this->handler->handle($this->makeEvent([
            'id' => 'pi_1',
            'cancellation_reason' => 'requested_by_customer',
            'metadata' => ['contract_id' => 'ctr-1'],
        ]));

        $this->assertSame('contract_cancelled', $outcome->result->action);
        $this->assertSame('ctr-1', $outcome->contractId);
    }

    /**
     * The customer changed the payment method: this event is of a replaced Stripe attempt and must not end the
     * contract, which continues with another one.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function anEventOfAReplacedAttemptDoesNotTouchTheContract(): void
    {
        $contract = $this->contractWithProviderId('cs_2');
        $contract->reopenForNewAttempt(
            \OxidEsales\PaymentBase\Contract\AttemptClosure::Closed,
            $contract->getBasketSnapshot(),
            [\OxidEsales\PaymentBase\Contract\ContractCondition::TYPE_PAYMENT_AUTHORIZED]
        );
        $this->contractRepository->method('findByProviderOrderId')->willReturn(null);
        $this->contractRepository->method('findById')->with('ctr-1')->willReturn($contract);
        $this->fulfillmentHandler->expects($this->once())->method('handlePaymentCanceled')->with('pi_1')->willReturn(null);

        $outcome = $this->handler->handle($this->makeEvent([
            'id' => 'pi_1',
            'cancellation_reason' => 'requested_by_customer',
            'metadata' => ['contract_id' => 'ctr-1', 'contract_attempt' => '0'],
        ]));

        $this->assertSame('skipped', $outcome->result->action);
    }

    private function contractWithProviderId(string $providerOrderId): \OxidEsales\PaymentBase\Contract\PaymentContract
    {
        $contract = new \OxidEsales\PaymentBase\Contract\PaymentContract(1, 'user-1', \OxidEsales\PaymentBase\Contract\BasketSnapshot::fromArray([
            'items' => [], 'discounts' => [], 'totalGross' => 100.0, 'totalNet' => 84.03, 'totalVat' => 15.97, 'currency' => 'EUR',
        ]), 'ctr-1');
        $contract->addCondition(new \OxidEsales\PaymentBase\Contract\ContractCondition(\OxidEsales\PaymentBase\Contract\ContractCondition::TYPE_PAYMENT_AUTHORIZED));
        $contract->transitionToNotFinished('order-1');
        $contract->transitionToPending();
        $contract->setProvider('stripe', $providerOrderId);

        return $contract;
    }

    /**
     * @param array<string, mixed> $objectData
     */
    private function makeEvent(array $objectData): WebhookEvent
    {
        return new WebhookEvent(
            id: 'evt_pi_cancel_' . substr(md5(json_encode($objectData) ?: ''), 0, 8),
            type: 'payment_intent.canceled',
            data: ['object' => $objectData],
            created: time()
        );
    }
}
