<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Webhook;

use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Webhook\Handler\ChargeDisputeCreatedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\ChargeRefundedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\CheckoutSessionCompletedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\CheckoutSessionExpiredWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentCanceledWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentFailedWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\Handler\PaymentIntentSucceededWebhookHandler;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventParser;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;
use OxidEsales\Payments\Stripe\WebhookHandler\WebhookContractFulfillmentHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Characterization tests for StripeWebhookProcessor::processEvent().
 *
 * Sprint 114.4a — safety net capturing the exact WebhookResult (success/skip/failure + action)
 * and the contract ID from getContractIdFromResult() for ALL 7 handled event types and their
 * significant sub-branches. These tests MUST remain green through the entire refactor.
 *
 * After Sprint 114.4b the processor uses a tagged handler registry; createProcessor() wires the
 * actual handler instances so behavior parity is verified against the real implementation.
 *
 * Event types covered:
 *   1. payment_intent.succeeded  (5 sub-branches)
 *   2. payment_intent.payment_failed (3 sub-branches)
 *   3. payment_intent.canceled  (3 sub-branches)
 *   4. charge.refunded  (4 sub-branches)
 *   5. charge.dispute.created  (1 sub-branch)
 *   6. checkout.session.completed  (5 sub-branches)
 *   7. checkout.session.expired  (3 sub-branches)
 *   8. unknown type → skipped default
 *
 * @covers \OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor
 */
final class StripeWebhookProcessorCharacterizationTest extends TestCase
{
    private WebhookLogRepositoryInterface&MockObject $logRepository;
    private LoggerInterface&MockObject $logger;
    private ModuleConfigurationServiceInterface&MockObject $config;
    private WebhookContractFulfillmentHandlerInterface&MockObject $fulfillmentHandler;
    private ContractRepositoryInterface&MockObject $contractRepository;

    protected function setUp(): void
    {
        $this->logRepository = $this->createMock(WebhookLogRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $this->fulfillmentHandler = $this->createMock(WebhookContractFulfillmentHandlerInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
    }

    // =========================================================================
    // 1. payment_intent.succeeded
    // =========================================================================

    /** @test */
    public function paymentIntentSucceeded_missingPaymentIntentId_returnsFailure(): void
    {
        $event = $this->makeEvent('payment_intent.succeeded', []);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('invalid_event', $result->action);
        $this->assertSame('Missing payment intent ID', $result->error);
        $this->assertNull($contractId);
    }

    /** @test */
    public function paymentIntentSucceeded_fulfillmentHandlerReturnsTrue_returnsContractFulfilled(): void
    {
        $event = $this->makeEvent('payment_intent.succeeded', ['id' => 'pi_abc']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->with('pi_abc')->willReturn(true);

        $contract = $this->makeContractWithId('contract-1');
        $this->contractRepository->method('findByProviderOrderId')->with('pi_abc')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_fulfilled', $result->action);
        $this->assertSame('contract-1', $contractId);
    }

    /** @test */
    public function paymentIntentSucceeded_fulfillmentHandlerReturnsFalse_returnsSkippedTerminalState(): void
    {
        $event = $this->makeEvent('payment_intent.succeeded', ['id' => 'pi_abc_f']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->with('pi_abc_f')->willReturn(false);

        $contract = $this->makeContractWithId('contract-2');
        $this->contractRepository->method('findByProviderOrderId')->with('pi_abc_f')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract already fulfilled or not in COMMITTED state', $result->error);
        $this->assertSame('contract-2', $contractId);
    }

    /** @test */
    public function paymentIntentSucceeded_fulfillmentHandlerReturnsNull_noMetadata_returnsContractNotFound(): void
    {
        $event = $this->makeEvent('payment_intent.succeeded', ['id' => 'pi_unknown']);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->with('pi_unknown')->willReturn(null);
        // No contract found by metadata lookup
        $this->contractRepository->method('findById')->willReturn(null);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract not found', $result->error);
        $this->assertNull($contractId);
    }

    /** @test */
    public function paymentIntentSucceeded_fulfillmentHandlerReturnsNull_metadataContractFulfilled_returnsAlreadyFulfilled(): void
    {
        $event = $this->makeEvent('payment_intent.succeeded', [
            'id' => 'pi_meta',
            'metadata' => ['contract_id' => 'ctr-fulfilled'],
        ]);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->with('pi_meta')->willReturn(null);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-fulfilled');
        $contract->method('getState')->willReturn(ContractState::fulfilled());
        $this->contractRepository->method('findById')->with('ctr-fulfilled')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract already fulfilled', $result->error);
        $this->assertSame('ctr-fulfilled', $contractId);
    }

    /** @test */
    public function paymentIntentSucceeded_fulfillmentHandlerReturnsNull_metadataContractCommitted_fulfilledSuccessfully(): void
    {
        $event = $this->makeEvent('payment_intent.succeeded', [
            'id' => 'pi_meta2',
            'metadata' => ['contract_id' => 'ctr-committed'],
        ]);

        $this->fulfillmentHandler->expects($this->exactly(2))
            ->method('handlePaymentSucceeded')
            ->willReturnOnConsecutiveCalls(null, true);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-committed');
        $contract->method('getState')->willReturn(ContractState::committed());
        $this->contractRepository->method('findById')->with('ctr-committed')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_fulfilled', $result->action);
        $this->assertSame('ctr-committed', $contractId);
    }

    // =========================================================================
    // 2. payment_intent.payment_failed
    // =========================================================================

    /** @test */
    public function paymentIntentFailed_missingPaymentIntentId_returnsFailure(): void
    {
        $event = $this->makeEvent('payment_intent.payment_failed', []);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('invalid_event', $result->action);
        $this->assertNull($contractId);
    }

    /** @test */
    public function paymentIntentFailed_handlerReturnsTrue_returnsContractFailed(): void
    {
        $event = $this->makeEvent('payment_intent.payment_failed', [
            'id' => 'pi_failed',
            'last_payment_error' => ['message' => 'Card declined'],
        ]);

        $this->fulfillmentHandler->method('handlePaymentFailed')
            ->with('pi_failed', 'Card declined')
            ->willReturn(true);

        $contract = $this->makeContractWithId('ctr-failed');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_failed', $result->action);
        $this->assertSame('ctr-failed', $contractId);
    }

    /** @test */
    public function paymentIntentFailed_handlerReturnsFalse_returnsSkippedTerminalState(): void
    {
        $event = $this->makeEvent('payment_intent.payment_failed', ['id' => 'pi_failed2']);

        $this->fulfillmentHandler->method('handlePaymentFailed')->willReturn(false);

        $contract = $this->makeContractWithId('ctr-fail2');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract already in terminal state', $result->error);
        $this->assertSame('ctr-fail2', $contractId);
    }

    /** @test */
    public function paymentIntentFailed_handlerReturnsNull_returnsContractNotFound(): void
    {
        $event = $this->makeEvent('payment_intent.payment_failed', ['id' => 'pi_fail_nf']);

        $this->fulfillmentHandler->method('handlePaymentFailed')->willReturn(null);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract not found', $result->error);
        $this->assertNull($contractId);
    }

    // =========================================================================
    // 3. payment_intent.canceled
    // =========================================================================

    /** @test */
    public function paymentIntentCanceled_missingPaymentIntentId_returnsFailure(): void
    {
        $event = $this->makeEvent('payment_intent.canceled', []);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('invalid_event', $result->action);
        $this->assertNull($contractId);
    }

    /** @test */
    public function paymentIntentCanceled_handlerReturnsTrue_returnsContractCancelled(): void
    {
        $event = $this->makeEvent('payment_intent.canceled', [
            'id' => 'pi_cancel',
            'cancellation_reason' => 'requested_by_customer',
        ]);

        $this->fulfillmentHandler->method('handlePaymentCanceled')
            ->with('pi_cancel', 'requested_by_customer')
            ->willReturn(true);

        $contract = $this->makeContractWithId('ctr-cancel');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_cancelled', $result->action);
        $this->assertSame('ctr-cancel', $contractId);
    }

    /** @test */
    public function paymentIntentCanceled_handlerReturnsFalse_returnsSkippedTerminalState(): void
    {
        $event = $this->makeEvent('payment_intent.canceled', ['id' => 'pi_cancel2']);

        $this->fulfillmentHandler->method('handlePaymentCanceled')->willReturn(false);

        $contract = $this->makeContractWithId('ctr-cancel2');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract already in terminal state', $result->error);
        $this->assertSame('ctr-cancel2', $contractId);
    }

    /** @test */
    public function paymentIntentCanceled_handlerReturnsNull_returnsContractNotFound(): void
    {
        $event = $this->makeEvent('payment_intent.canceled', ['id' => 'pi_cancel_nf']);

        $this->fulfillmentHandler->method('handlePaymentCanceled')->willReturn(null);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract not found', $result->error);
        $this->assertNull($contractId);
    }

    // =========================================================================
    // 4. charge.refunded
    // =========================================================================

    /** @test */
    public function chargeRefunded_missingPaymentIntentId_returnsFailure(): void
    {
        $event = $this->makeEvent('charge.refunded', ['id' => 'ch_nopi']);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('invalid_event', $result->action);
        $this->assertNull($contractId);
    }

    /** @test */
    public function chargeRefunded_handlerReturnsTrue_returnsChargeRefunded(): void
    {
        $event = $this->makeEvent('charge.refunded', [
            'id' => 'ch_1',
            'payment_intent' => 'pi_chref',
            'amount_refunded' => 3000,
        ]);

        $this->fulfillmentHandler->method('handleChargeRefunded')
            ->with('pi_chref', 30.0)
            ->willReturn(true);

        $contract = $this->makeContractWithId('ctr-refund');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('charge_refunded', $result->action);
        $this->assertSame('ctr-refund', $contractId);
    }

    /** @test */
    public function chargeRefunded_handlerReturnsFalse_returnsSkippedNotFulfilled(): void
    {
        $event = $this->makeEvent('charge.refunded', [
            'id' => 'ch_2',
            'payment_intent' => 'pi_chref2',
            'amount_refunded' => 1000,
        ]);

        $this->fulfillmentHandler->method('handleChargeRefunded')->willReturn(false);

        $contract = $this->makeContractWithId('ctr-refund2');
        $this->contractRepository->method('findByProviderOrderId')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract not in FULFILLED state', $result->error);
        $this->assertSame('ctr-refund2', $contractId);
    }

    /** @test */
    public function chargeRefunded_handlerReturnsNull_returnsContractNotFound(): void
    {
        $event = $this->makeEvent('charge.refunded', [
            'id' => 'ch_3',
            'payment_intent' => 'pi_chref3',
            'amount_refunded' => 1000,
        ]);

        $this->fulfillmentHandler->method('handleChargeRefunded')->willReturn(null);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract not found', $result->error);
        $this->assertNull($contractId);
    }

    // =========================================================================
    // 5. charge.dispute.created
    // =========================================================================

    /** @test */
    public function chargeDisputeCreated_alwaysReturnsSuccessDisputeLogged(): void
    {
        $event = $this->makeEvent('charge.dispute.created', [
            'id' => 'dp_abc',
            'amount' => 5000,
            'reason' => 'fraudulent',
            'charge' => 'ch_xyz',
        ]);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('dispute_logged', $result->action);
        $this->assertNull($contractId);
    }

    // =========================================================================
    // 6. checkout.session.completed
    // =========================================================================

    /** @test */
    public function checkoutSessionCompleted_paymentStatusNotPaid_returnsSkipped(): void
    {
        $event = $this->makeEvent('checkout.session.completed', [
            'id' => 'cs_1',
            'payment_status' => 'unpaid',
            'payment_intent' => 'pi_cs',
        ]);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Checkout session not paid', $result->error);
        $this->assertNull($contractId);
    }

    /** @test */
    public function checkoutSessionCompleted_paidButNoPaymentIntentId_returnsSkipped(): void
    {
        $event = $this->makeEvent('checkout.session.completed', [
            'id' => 'cs_2',
            'payment_status' => 'paid',
            // no payment_intent
        ]);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('No payment intent ID in checkout session', $result->error);
        $this->assertNull($contractId);
    }

    /** @test */
    public function checkoutSessionCompleted_contractNotFound_returnsSkipped(): void
    {
        $event = $this->makeEvent('checkout.session.completed', [
            'id' => 'cs_3',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_cs3',
            'metadata' => ['contract_id' => 'ctr-missing'],
        ]);

        $this->contractRepository->method('findById')->with('ctr-missing')->willReturn(null);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract not found for checkout session', $result->error);
        $this->assertNull($contractId);
    }

    /** @test */
    public function checkoutSessionCompleted_contractFoundAndCommitted_returnsContractFulfilled(): void
    {
        $event = $this->makeEvent('checkout.session.completed', [
            'id' => 'cs_4',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_cs4',
            'metadata' => ['contract_id' => 'ctr-committed'],
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-committed');
        $contract->method('getState')->willReturn(ContractState::committed());
        $this->contractRepository->method('findById')->with('ctr-committed')->willReturn($contract);

        $this->fulfillmentHandler->method('handlePaymentSucceeded')->with('pi_cs4')->willReturn(true);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_fulfilled', $result->action);
        $this->assertSame('ctr-committed', $contractId);
    }

    /** @test */
    public function checkoutSessionCompleted_contractFoundNotCommitted_returnsContractUpdated(): void
    {
        $event = $this->makeEvent('checkout.session.completed', [
            'id' => 'cs_5',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_cs5',
            'metadata' => ['contract_id' => 'ctr-pending'],
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-pending');
        $contract->method('getState')->willReturn(ContractState::pending());
        $this->contractRepository->method('findById')->with('ctr-pending')->willReturn($contract);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('contract_updated', $result->action);
        $this->assertSame('ctr-pending', $contractId);
    }

    // =========================================================================
    // 7. checkout.session.expired
    // =========================================================================

    /** @test */
    public function checkoutSessionExpired_noContractIdInMetadata_returnsSkipped(): void
    {
        $event = $this->makeEvent('checkout.session.expired', [
            'id' => 'cs_exp1',
            // no metadata
        ]);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('No contract ID in session metadata', $result->error);
        $this->assertNull($contractId);
    }

    /** @test */
    public function checkoutSessionExpired_handlerReturnsTrue_returnsSessionExpired(): void
    {
        $event = $this->makeEvent('checkout.session.expired', [
            'id' => 'cs_exp2',
            'metadata' => ['contract_id' => 'ctr-expiry'],
        ]);

        $this->fulfillmentHandler->method('handleSessionExpired')->with('ctr-expiry')->willReturn(true);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('session_expired', $result->action);
        $this->assertSame('ctr-expiry', $contractId);
    }

    /** @test */
    public function checkoutSessionExpired_handlerReturnsFalse_returnsSkippedTerminalState(): void
    {
        $event = $this->makeEvent('checkout.session.expired', [
            'id' => 'cs_exp3',
            'metadata' => ['contract_id' => 'ctr-terminal'],
        ]);

        $this->fulfillmentHandler->method('handleSessionExpired')->with('ctr-terminal')->willReturn(false);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract already in terminal state', $result->error);
        $this->assertNull($contractId);
    }

    /** @test */
    public function checkoutSessionExpired_handlerReturnsNull_returnsContractNotFound(): void
    {
        $event = $this->makeEvent('checkout.session.expired', [
            'id' => 'cs_exp4',
            'metadata' => ['contract_id' => 'ctr-missing'],
        ]);

        $this->fulfillmentHandler->method('handleSessionExpired')->with('ctr-missing')->willReturn(null);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract not found', $result->error);
        $this->assertNull($contractId);
    }

    // =========================================================================
    // 8. Unknown event type — default branch
    // =========================================================================

    /** @test */
    public function unknownEventType_returnsSkippedWithUnhandledMessage(): void
    {
        $event = $this->makeEvent('customer.subscription.deleted', ['id' => 'sub_xyz']);

        [$result, $contractId] = $this->processAndCapture($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Unhandled event type: customer.subscription.deleted', $result->error);
        $this->assertNull($contractId);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<string, mixed> $objectData
     */
    private function makeEvent(string $type, array $objectData): WebhookEvent
    {
        return new WebhookEvent(
            id: 'evt_char_' . substr(md5($type . json_encode($objectData)), 0, 8),
            type: $type,
            data: ['object' => $objectData],
            created: time()
        );
    }

    private function makeContractWithId(string $id): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        return $contract;
    }

    private function createProcessor(): StripeWebhookProcessor
    {
        $parser = new StripeWebhookEventParser();

        $handlers = [
            new PaymentIntentSucceededWebhookHandler(
                $parser,
                $this->fulfillmentHandler,
                $this->contractRepository,
                $this->logger
            ),
            new PaymentIntentFailedWebhookHandler(
                $parser,
                $this->fulfillmentHandler,
                $this->contractRepository,
                $this->logger
            ),
            new PaymentIntentCanceledWebhookHandler(
                $parser,
                $this->fulfillmentHandler,
                $this->contractRepository,
                $this->logger
            ),
            new ChargeRefundedWebhookHandler(
                $parser,
                $this->fulfillmentHandler,
                $this->contractRepository,
                $this->logger
            ),
            new ChargeDisputeCreatedWebhookHandler(
                $parser,
                $this->fulfillmentHandler,
                $this->contractRepository,
                $this->logger
            ),
            new CheckoutSessionCompletedWebhookHandler(
                $parser,
                $this->fulfillmentHandler,
                $this->contractRepository,
                $this->logger
            ),
            new CheckoutSessionExpiredWebhookHandler(
                $parser,
                $this->fulfillmentHandler,
                $this->contractRepository,
                $this->logger
            ),
        ];

        return new StripeWebhookProcessor(
            $this->logRepository,
            $this->logger,
            $this->config,
            $handlers
        );
    }

    /**
     * Process the event and return both the result and the contract ID as a tuple.
     *
     * @return array{0: WebhookResult, 1: ?string}
     */
    private function processAndCapture(WebhookEvent $event): array
    {
        $processor = $this->createProcessor();
        $reflection = new ReflectionClass($processor);
        /** @var WebhookResult $result */
        $result = $reflection->getMethod('processEvent')->invoke($processor, $event);
        /** @var string|null $contractId */
        $contractId = $reflection->getMethod('getContractIdFromResult')->invoke($processor, $result);
        return [$result, $contractId];
    }
}
