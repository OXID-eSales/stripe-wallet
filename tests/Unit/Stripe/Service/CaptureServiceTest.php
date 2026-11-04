<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Adapter\Response\CaptureResponse;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Payment\CaptureBooking;
use OxidEsales\PaymentBase\Payment\ProviderCaptureLedgerInterface;
use OxidEsales\PaymentBase\Service\ContractFulfillmentServiceInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\CaptureService;
use OxidEsales\Payments\Stripe\Service\CaptureServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CaptureService.
 *
 * Sprint 9: Tests for the extracted capture business logic.
 * Sprint 26: Updated to use factory instead of direct adapter injection.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\CaptureService::class)]
class CaptureServiceTest extends TestCase
{
    private StripeAdapterInterface&MockObject $adapter;
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private ContractRepositoryInterface&MockObject $repository;
    private ContractFulfillmentServiceInterface&MockObject $fulfillmentService;
    private ProviderCaptureLedgerInterface&MockObject $captureLedger;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(StripeAdapterInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapterFactory->method('getStripeAdapter')->willReturn($this->adapter);
        $this->repository = $this->createMock(ContractRepositoryInterface::class);
        $this->fulfillmentService = $this->createMock(ContractFulfillmentServiceInterface::class);
        $this->captureLedger = $this->createMock(ProviderCaptureLedgerInterface::class);
        $this->captureLedger->method('book')->willReturn(CaptureBooking::booked(0.0));
    }

    private function createService(): CaptureService
    {
        return new CaptureService(
            $this->adapterFactory,
            $this->repository,
            $this->fulfillmentService,
            $this->captureLedger,
            new NullLogger(),
            new \OxidEsales\PaymentBase\Logging\ThrowableSummary([])
        );
    }

    /**
     * The ledger saves the contract on its own instance; the service reads it back before it moves the state.
     */
    private function ledgerKeeps(PaymentContractInterface $contract): void
    {
        $this->repository->method('findById')->willReturn($contract);
    }

    public function testImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(CaptureServiceInterface::class, $service);
    }

    /**
     * Sprint 121 Phase E (STRP-129): defense-in-depth at the convergence
     * point — no caller (panel, opalreturns, future) can push a non-positive
     * partial amount to the Stripe API.
     */
    public function testNonPositiveCaptureAmountIsRejectedBeforeAnyAdapterCall(): void
    {
        $this->adapter->expects($this->never())->method('capturePayment');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );

        $service = $this->createService();

        foreach ([-5.0, 0.0] as $amount) {
            $result = $service->processCapture($contract, $amount, []);

            $this->assertFalse($result->successful, "Amount {$amount} must be rejected");
            $this->assertStringContainsString('greater than zero', (string) $result->errorMessage);
        }
    }

    public function testNonPositiveCaptureAtStripeAmountIsRejected(): void
    {
        $this->adapter->expects($this->never())->method('capturePayment');

        $result = $this->createService()->captureAtStripe('pi_123', -1.0, 'EUR', []);

        $this->assertFalse($result->successful);
    }

    public function testProcessCaptureWithContractReturnsSuccess(): void
    {
        $capturedAt = new \DateTimeImmutable();
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_123',
            captureId: 'ch_123',
            amountCaptured: 10.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: $capturedAt
        );

        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $this->ledgerKeeps($contract);
        // Sprint 82: transitionContractState() now checks state before calling transition
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );
        // Sprint 127: over-capture guard reads getAmount()/getCapturedAmount().
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(null);
        $contract->expects($this->once())->method('captureAuthorization');

        $this->repository->expects($this->once())->method('save')->with($contract);

        $service = $this->createService();

        $result = $service->processCapture($contract, 10.00, ['initiator' => 'admin']);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('ch_123', $result->captureId);
        $this->assertSame(10.00, $result->amountCaptured);
        $this->assertSame('eur', $result->currency);
    }

    public function testProcessCaptureTransitionsContractState(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_123',
            captureId: 'ch_123',
            amountCaptured: 10.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $this->ledgerKeeps($contract);
        // Sprint 82: transitionContractState() now checks state before calling transition
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );
        $contract->expects($this->once())->method('captureAuthorization');

        $this->repository->expects($this->once())->method('save');

        $service = $this->createService();

        $service->processCapture($contract, null, []);
    }

    public function testProcessCapturePassesAmountAsFloat(): void
    {
        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) {
                return $request->amount === 10.50; // Amount in major units
            }))
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_123',
                captureId: 'ch_123',
                amountCaptured: 10.50,
                currency: 'eur',
                status: 'succeeded',
                capturedAt: new \DateTimeImmutable()
            ));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());
        // Sprint 127: over-capture guard reads getAmount()/getCapturedAmount().
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(null);

        $service = $this->createService();

        $service->processCapture($contract, 10.50, []);
    }

    public function testProcessCaptureWithNullAmountDoesFullCapture(): void
    {
        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) {
                return $request->amount === null;
            }))
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_123',
                captureId: 'ch_123',
                amountCaptured: 50.00,
                currency: 'eur',
                status: 'succeeded',
                capturedAt: new \DateTimeImmutable()
            ));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());

        $service = $this->createService();

        $result = $service->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }

    public function testProcessCaptureReturnsFailureOnException(): void
    {
        $this->adapter->method('capturePayment')
            ->willThrowException(new \Exception('API Error'));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());
        // Sprint 127: over-capture guard reads getAmount()/getCapturedAmount().
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(null);

        $this->repository->expects($this->never())->method('save');

        $service = $this->createService();

        $result = $service->processCapture($contract, 10.00, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('API Error', $result->errorMessage);
    }

    public function testAFailedCaptureLogsTheThrowableSummaryNotItsMessage(): void
    {
        $this->adapter->method('capturePayment')
            ->willThrowException(new \RuntimeException('No such customer: buyer@example.com', 7));
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(null);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logged = [];
        $logger->method('error')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
            $logged = $context;
        });
        $service = new CaptureService(
            $this->adapterFactory,
            $this->repository,
            $this->fulfillmentService,
            $this->captureLedger,
            $logger,
            new \OxidEsales\PaymentBase\Logging\ThrowableSummary([])
        );

        $service->processCapture($contract, 10.00, []);

        $this->assertSame(\RuntimeException::class, $logged['throwable']['class']);
        $this->assertSame(7, $logged['throwable']['code']);
        $this->assertStringNotContainsString('buyer@example.com', (string) json_encode($logged));
    }

    public function testProcessCaptureReturnsFailureWhenNoPaymentIntentId(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());
        // Sprint 127: over-capture guard reads getAmount()/getCapturedAmount().
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(null);

        $this->adapter->expects($this->never())->method('capturePayment');
        $this->repository->expects($this->never())->method('save');

        $service = $this->createService();

        $result = $service->processCapture($contract, 10.00, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('PaymentIntent ID', $result->errorMessage);
    }

    public function testThePaymentIntentIdIsNeverTakenFromContractMetadata(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn(null);
        $contract->method('getMetadata')->with('payment_intent_id')->willReturn('pi_from_metadata');
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(null);

        $this->adapter->expects($this->never())->method('capturePayment');

        $result = $this->createService()->processCapture($contract, 10.00, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('PaymentIntent ID', $result->errorMessage);
    }

    public function testProcessCaptureReturnsFailureWhenEmptyPaymentIntentId(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('');
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());

        $this->adapter->expects($this->never())->method('capturePayment');

        $service = $this->createService();

        $result = $service->processCapture($contract, 10.00, []);

        $this->assertFalse($result->isSuccessful());
    }

    public function testCaptureAtStripeOnlyAsksStripe(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_123',
            captureId: 'ch_123',
            amountCaptured: 10.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->willReturn($response);

        $this->repository->expects($this->never())->method('save');
        $this->captureLedger->expects($this->never())->method('book');

        $service = $this->createService();

        $result = $service->captureAtStripe('pi_123', 10.00, 'EUR', ['order_id' => 'order_123']);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('ch_123', $result->captureId);
    }

    public function testCaptureAtStripeReturnsFailureOnException(): void
    {
        $this->adapter->method('capturePayment')
            ->willThrowException(new \Exception('Direct capture failed'));

        $service = $this->createService();

        $result = $service->captureAtStripe('pi_123', 10.00, 'EUR', []);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('Direct capture failed', $result->errorMessage);
    }

    public function testCaptureAtStripeFailureCarriesStripesErrorCode(): void
    {
        $this->adapter->method('capturePayment')
            ->willThrowException(new \OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException('declined', 'payment_intent_unexpected_state'));

        $result = $this->createService()->captureAtStripe('pi_123', 10.00, 'EUR', []);

        $this->assertSame('payment_intent_unexpected_state', $result->errorCode);
    }

    // --- Sprint 82: Manual capture fix — COMMITTED state support ---

    /**
     * Sprint 82: When contract is in COMMITTED state (manual capture order that
     * skipped AUTHORIZED), capture should use ContractFulfillmentService to
     * dispatch ContractFulfilledEvent (which triggers OXPAID update).
     */
    public function testProcessCaptureCallsFulfillmentServiceForCommittedContract(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_committed',
            captureId: 'ch_committed',
            amountCaptured: 130.39,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_committed');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::committed()
        );

        // Must use ContractFulfillmentService (dispatches event + updates OXPAID)
        $this->fulfillmentService->expects($this->once())
            ->method('fulfill')
            ->with($contract)
            ->willReturn(true);

        // captureAuthorization must NOT be called for committed contracts
        $contract->expects($this->never())->method('captureAuthorization');

        // Repository save is handled by ContractFulfillmentService, not directly
        $this->repository->expects($this->never())->method('save');

        $service = $this->createService();

        $result = $service->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }

    /**
     * CFG-1 S8: the contract (what the admin app's Payment tab sums up) must carry the captured amount before the
     * fulfilment saves it. VR-22 A5: payment-base's ProviderCaptureLedger books it, under the PaymentIntent id.
     */
    public function testProcessCaptureBooksTheCaptureOnTheLedgerBeforeTheContractIsFulfilled(): void
    {
        $this->adapter->method('capturePayment')->willReturn(CaptureResponse::success(
            providerPaymentId: 'pi_committed',
            captureId: 'ch_committed',
            amountCaptured: 131.0,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        ));
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('ctr-1');
        $contract->method('getProviderOrderId')->willReturn('pi_committed');
        $contract->method('getAmount')->willReturn(262.0);
        $contract->method('getCapturedAmount')->willReturn(null);
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::committed());
        $this->ledgerKeeps($contract);
        $recorded = [];
        $this->captureLedger->expects($this->once())->method('book')->with('ctr-1', 'pi_committed', 131.0, 'eur', 'event')
            ->willReturnCallback(static function () use (&$recorded): CaptureBooking {
                $recorded[] = 'booked';

                return CaptureBooking::booked(131.0);
            });
        $this->fulfillmentService->expects($this->once())->method('fulfill')->with($contract)
            ->willReturnCallback(static function () use (&$recorded): bool {
                $recorded[] = 'fulfilled';

                return true;
            });

        $this->createService()->processCapture($contract, 131.0, []);

        $this->assertSame(['booked', 'fulfilled'], $recorded);
    }

    public function testTheStateMovesOnTheContractTheLedgerSaved(): void
    {
        $this->adapter->method('capturePayment')->willReturn(CaptureResponse::success(
            providerPaymentId: 'pi_1',
            captureId: 'ch_1',
            amountCaptured: 10.0,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        ));
        $stale = $this->createMock(PaymentContractInterface::class);
        $stale->method('getId')->willReturn('ctr-1');
        $stale->method('getProviderOrderId')->willReturn('pi_1');
        $stale->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());
        $stale->expects($this->never())->method('captureAuthorization');
        $fresh = $this->createMock(PaymentContractInterface::class);
        $fresh->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());
        $fresh->expects($this->once())->method('captureAuthorization');
        $this->captureLedger->method('book')->willReturn(CaptureBooking::booked(10.0));
        $this->repository->method('findById')->with('ctr-1')->willReturn($fresh);
        $this->repository->expects($this->once())->method('save')->with($fresh);

        $this->createService()->processCapture($stale, null, []);
    }

    /**
     * Sprint 82: Authorized contracts still use captureAuthorization() (unchanged behavior).
     */
    public function testProcessCaptureCallsCaptureAuthorizationForAuthorizedContract(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_authorized',
            captureId: 'ch_authorized',
            amountCaptured: 50.00,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        );

        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_authorized');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );

        // Must call captureAuthorization() for authorized contracts
        $contract->expects($this->once())->method('captureAuthorization');
        $contract->expects($this->never())->method('fulfill');

        $this->repository->expects($this->once())->method('save');

        $service = $this->createService();

        $result = $service->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }

    // --- Sprint 84 / VR-22 A5: capture booking ---

    public function testProcessCaptureBooksTheCaptureUnderThePaymentIntentId(): void
    {
        $this->adapter->method('capturePayment')->willReturn(CaptureResponse::success(
            providerPaymentId: 'pi_record',
            captureId: 'ch_record',
            amountCaptured: 99.99,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable()
        ));
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_record');
        $contract->method('getId')->willReturn('contract_rec');
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::committed());
        $this->ledgerKeeps($contract);
        $this->captureLedger->expects($this->once())->method('book')
            ->with('contract_rec', 'pi_record', 99.99, 'eur', 'event')
            ->willReturn(CaptureBooking::booked(99.99));

        $this->createService()->processCapture($contract, null, []);
    }

    public function testAFailedCaptureIsNotBooked(): void
    {
        $this->adapter->method('capturePayment')
            ->willThrowException(new \Exception('Capture failed'));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_fail');

        $this->captureLedger->expects($this->never())->method('book');

        $service = $this->createService();
        $service->processCapture($contract, 10.00, []);
    }

    public function testProcessCapturePassesMetadataToAdapter(): void
    {
        $expectedMetadata = [
            'initiator' => 'admin',
            'contract_id' => 'contract_abc',
        ];

        $this->adapter->expects($this->once())
            ->method('capturePayment')
            ->with($this->callback(function (CapturePaymentRequest $request) use ($expectedMetadata) {
                return $request->metadata === $expectedMetadata;
            }))
            ->willReturn(CaptureResponse::success(
                providerPaymentId: 'pi_123',
                captureId: 'ch_123',
                amountCaptured: 10.00,
                currency: 'eur',
                status: 'succeeded',
                capturedAt: new \DateTimeImmutable()
            ));

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(\OxidEsales\PaymentBase\Contract\ContractState::authorized());
        // Sprint 127: over-capture guard reads getAmount()/getCapturedAmount().
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(null);

        $service = $this->createService();

        $service->processCapture($contract, 10.00, $expectedMetadata);
    }

    // --- Issue 2 (STRP-15123): Over-capture guard ---
    /**
     * Repro: contract authorized 100.00, already captured 60.00 → remaining 40.00.
     * Submitting 50.00 must be rejected before any Stripe API call.
     * RED today — CaptureService has no remaining-capturable guard.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testProcessCaptureRejectsAmountAboveRemainingCapturable(): void
    {
        $this->adapter->expects($this->never())->method('capturePayment');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(60.0);

        $service = $this->createService();
        $result  = $service->processCapture($contract, 50.0, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('exceeds remaining capturable', (string) $result->errorMessage);
    }

    /**
     * Edge: amount exactly equal to remaining (40.00) must be allowed through.
     * RED today — no guard, adapter not yet called (guard check with 40.0 must reach the adapter).
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testProcessCaptureAllowsAmountEqualToRemainingCapturable(): void
    {
        $capturedAt = new \DateTimeImmutable();
        $response   = CaptureResponse::success(
            providerPaymentId: 'pi_123',
            captureId: 'ch_eq',
            amountCaptured: 40.0,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: $capturedAt,
        );
        $this->adapter->expects($this->once())->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );
        $contract->method('getAmount')->willReturn(100.0);
        $contract->method('getCapturedAmount')->willReturn(60.0);
        $contract->expects($this->once())->method('captureAuthorization');
        $this->repository->expects($this->once())->method('save');

        $service = $this->createService();
        $result  = $service->processCapture($contract, 40.0, []);

        $this->assertTrue($result->isSuccessful());
    }

    /**
     * Edge: null amount (full capture) must bypass the remaining-capturable guard entirely.
     * Existing tests cover this behavior; this is an explicit guard against regression.
     */
    #[\PHPUnit\Framework\Attributes\Group('strp-15123')]
    public function testProcessCaptureWithNullAmountBypassesOverCaptureGuard(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_full',
            captureId: 'ch_full',
            amountCaptured: 40.0,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable(),
        );
        $this->adapter->expects($this->once())->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_full');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );
        // getAmount/getCapturedAmount intentionally NOT mocked — guard must not be reached
        $contract->expects($this->once())->method('captureAuthorization');
        $this->repository->expects($this->once())->method('save');

        $result = $this->createService()->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }

    // --- Sprint 114.11b (S3): Capturable-state policy moved from handler to service ---

    /**
     * S3: processCapture() must reject a PENDING contract — state policy lives in the service.
     */
    public function testProcessCaptureReturnsFailureWhenContractIsPending(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::pending()
        );
        $contract->method('getStateValue')->willReturn('pending');

        $this->adapter->expects($this->never())->method('capturePayment');

        $service = $this->createService();
        $result = $service->processCapture($contract, null, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('not in capturable state', $result->errorMessage ?? '');
        $this->assertStringContainsString('pending', $result->errorMessage ?? '');
    }

    /**
     * S3: processCapture() must reject a FULFILLED contract.
     */
    public function testProcessCaptureReturnsFailureWhenContractIsFulfilled(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_123');
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::fulfilled()
        );
        $contract->method('getStateValue')->willReturn('fulfilled');

        $this->adapter->expects($this->never())->method('capturePayment');

        $service = $this->createService();
        $result = $service->processCapture($contract, null, []);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('not in capturable state', $result->errorMessage ?? '');
    }

    /**
     * S3: processCapture() must accept an AUTHORIZED contract (unchanged behavior).
     */
    public function testProcessCaptureSucceedsForAuthorizedContract(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_auth',
            captureId: 'ch_auth',
            amountCaptured: 50.0,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable(),
        );
        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_auth');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::authorized()
        );
        $contract->expects($this->once())->method('captureAuthorization');
        $this->repository->expects($this->once())->method('save');

        $service = $this->createService();
        $result = $service->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }

    /**
     * S3: processCapture() must accept a COMMITTED contract (unchanged behavior).
     */
    public function testProcessCaptureSucceedsForCommittedContract(): void
    {
        $response = CaptureResponse::success(
            providerPaymentId: 'pi_committed',
            captureId: 'ch_committed',
            amountCaptured: 130.39,
            currency: 'eur',
            status: 'succeeded',
            capturedAt: new \DateTimeImmutable(),
        );
        $this->adapter->method('capturePayment')->willReturn($response);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getProviderOrderId')->willReturn('pi_committed');
        $this->ledgerKeeps($contract);
        $contract->method('getState')->willReturn(
            \OxidEsales\PaymentBase\Contract\ContractState::committed()
        );
        $this->fulfillmentService->expects($this->once())->method('fulfill')->willReturn(true);

        $service = $this->createService();
        $result = $service->processCapture($contract, null, []);

        $this->assertTrue($result->isSuccessful());
    }
}
