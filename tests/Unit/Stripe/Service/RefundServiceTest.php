<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\PaymentBase\Adapter\Response\RefundResponse;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\RefundService;
use OxidEsales\Payments\Stripe\Service\RefundServiceInterface;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TDD Tests for RefundService.
 *
 * Sprint 21: Extract business logic from StripeRefundRequestHandler.
 * Sprint 24: Added StockRestorationService mock.
 */
class RefundServiceTest extends TestCase
{
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private StockRestorationServiceInterface&MockObject $stockRestorationService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->stockRestorationService = $this->createMock(StockRestorationServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->adapterFactory
            ->method('getStripeAdapter')
            ->willReturn($this->stripeAdapter);
    }

    private function createService(): RefundService
    {
        return new RefundService(
            $this->adapterFactory,
            $this->stockRestorationService,
            $this->logger
        );
    }

    // --- RefundResponse DTO Tests ---

    public function testRefundResponseSuccessCreation(): void
    {
        $refundedAt = new DateTimeImmutable();
        $result = RefundResponse::success(
            providerPaymentId: 'pi_123',
            refundId: 're_123',
            amountRefunded: 25.50,
            currency: 'eur',
            status: 'succeeded',
            refundedAt: $refundedAt
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('re_123', $result->refundId);
        $this->assertEquals(25.50, $result->amountRefunded);
        $this->assertEquals('eur', $result->currency);
        $this->assertEquals('succeeded', $result->status);
        $this->assertNull($result->errorMessage);
        $this->assertNull($result->errorCode);
    }

    public function testRefundResponseFailureCreation(): void
    {
        $result = RefundResponse::failure('Charge already refunded', 'charge_already_refunded');

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->refundId);
        $this->assertNull($result->amountRefunded);
        $this->assertEquals('Charge already refunded', $result->errorMessage);
        $this->assertEquals('charge_already_refunded', $result->errorCode);
    }

    public function testRefundResponsePendingStatus(): void
    {
        $result = RefundResponse::success(
            providerPaymentId: 'pi_pending',
            refundId: 're_pending',
            amountRefunded: 10.00,
            currency: 'usd',
            status: 'pending',
            refundedAt: new DateTimeImmutable()
        );

        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('pending', $result->status);
    }

    // --- Service Interface Tests ---

    public function testServiceImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(RefundServiceInterface::class, $service);
    }

    // --- processRefundByCharge Tests ---

    public function testProcessRefundByChargeSuccessful(): void
    {
        // Arrange
        $chargeId = 'ch_test_123';
        $reason = 'requested_by_customer';
        $metadata = ['order_id' => 'order_123'];

        $refund = $this->buildRefundDto('re_success_123', 5000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with($chargeId, null, $reason, $metadata)
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge($chargeId, $reason, $metadata);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('re_success_123', $result->refundId);
        $this->assertEquals(50.00, $result->amountRefunded); // Amount in major units
        $this->assertEquals('eur', $result->currency);
        $this->assertEquals('succeeded', $result->status);
    }

    public function testProcessRefundByChargeFullRefund(): void
    {
        // Arrange - always full refund (Sprint 22)
        $chargeId = 'ch_full_456';

        $refund = $this->buildRefundDto('re_full_456', 10000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with($chargeId, null, null, null)
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge($chargeId);

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(100.00, $result->amountRefunded); // Amount in major units
    }

    public function testProcessRefundByChargePendingStatus(): void
    {
        // Arrange
        $refund = $this->buildRefundDto('re_pending', 2500, 'usd', 'pending');

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test');

        // Assert - pending is still successful
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals('pending', $result->status);
    }

    public function testProcessRefundByChargeFailedStatus(): void
    {
        // Arrange
        $refund = $this->buildRefundDto('re_failed', 2500, 'usd', 'failed');

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('failed', $result->errorMessage ?? '');
    }

    public function testProcessRefundByChargeHandlesAdapterException(): void
    {
        // Arrange
        $exception = new PaymentAdapterException(
            'stripe',
            'charge_already_refunded',
            'Charge already refunded'
        );

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error');

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertEquals('Charge already refunded', $result->errorMessage);
        $this->assertEquals('charge_already_refunded', $result->errorCode);
    }

    // --- processRefund Tests ---

    public function testProcessFullRefundSuccess(): void
    {
        // Arrange
        $paymentIntent = $this->buildPiDto('pi_full_test', 'ch_full_test');
        $refund = $this->buildRefundDto('re_full', 10000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with(
                'ch_full_test',
                null, // Full refund = null amount
                'requested_by_customer',
                $this->anything()
            )
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefund(
            'order_full',
            'pi_full_test',
            'requested_by_customer'
        );

        // Assert - Full refund returns amount from Stripe response
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(100.00, $result->amountRefunded); // Amount in major units
    }

    public function testProcessPartialRefundPassesAmountInCents(): void
    {
        // Arrange
        $paymentIntent = $this->buildPiDto('pi_partial_test', 'ch_partial_test');
        $refund = $this->buildRefundDto('re_partial', 550, 'eur', 'succeeded');

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with(
                'ch_partial_test',
                550, // 5.50 EUR = 550 cents
                'requested_by_customer',
                $this->anything()
            )
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefund(
            'order_partial',
            'pi_partial_test',
            'requested_by_customer',
            null,
            'admin',
            5.50
        );

        // Assert
        $this->assertTrue($result->isSuccessful());
        $this->assertEquals(5.50, $result->amountRefunded);
    }

    public function testProcessFullRefundHandlesChargeObjectInPaymentIntent(): void
    {
        // Arrange — PI with expanded StripeChargeDto; service extracts charge->id
        $chargeDto = new StripeChargeDto(
            id: 'ch_from_object',
            amount: 5000,
            amountCaptured: 5000,
            amountRefunded: 0,
            currency: 'eur',
            captured: true,
            created: 1700000000,
        );
        $paymentIntent = new StripePaymentIntentDto(
            id: 'pi_expanded',
            status: 'succeeded',
            amount: 5000,
            currency: 'eur',
            created: 1700000000,
            latestChargeId: 'ch_from_object',
            charge: $chargeDto,
        );

        $refund = $this->buildRefundDto('re_from_object', 5000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with('ch_from_object', null, null, $this->anything())
            ->willReturn($refund);

        // Act
        $service = $this->createService();
        $result = $service->processRefund('order_obj', 'pi_expanded');

        // Assert
        $this->assertTrue($result->isSuccessful());
    }

    public function testProcessFullRefundFailsWhenNoCharge(): void
    {
        // Arrange
        $paymentIntent = $this->buildPiDto('pi_no_charge', null);

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        // Act
        $service = $this->createService();
        $result = $service->processRefund('order_no_charge', 'pi_no_charge');

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('No charge found', $result->errorMessage ?? '');
    }

    // --- Validation Tests ---

    public function testValidRefundReasons(): void
    {
        // Arrange
        $validReasons = ['duplicate', 'fraudulent', 'requested_by_customer'];

        // Act & Assert - each valid reason should pass through to adapter
        foreach ($validReasons as $reason) {
            $refund = $this->buildRefundDto('re_' . $reason, 1000, 'eur', 'succeeded');

            // Reset mocks for each iteration
            $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
            $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
            $this->stockRestorationService = $this->createMock(StockRestorationServiceInterface::class);
            $this->adapterFactory->method('getStripeAdapter')->willReturn($this->stripeAdapter);

            $this->stripeAdapter
                ->expects($this->once())
                ->method('createRefundByCharge')
                ->with('ch_test', null, $reason, null)
                ->willReturn($refund);

            $service = $this->createService();
            $result = $service->processRefundByCharge('ch_test', $reason);
            $this->assertTrue($result->isSuccessful(), "Reason '{$reason}' should be valid");
        }
    }

    // --- Sprint 121 Phase B (STRP-129): reason-whitelist regression pins ---

    public function testInvalidRefundReasonIsWhitelistedToNullOnProcessRefund(): void
    {
        // Pin: a forged/unknown refund_reason never reaches Stripe's enum param.
        $paymentIntent = $this->buildPiDto('pi_pin_test', 'ch_pin_test');
        $refund = $this->buildRefundDto('re_pin', 10000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($paymentIntent);

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with('ch_pin_test', null, null, $this->anything())
            ->willReturn($refund);

        $result = $this->createService()->processRefund('order_pin', 'pi_pin_test', '<script>not-a-reason');

        $this->assertTrue($result->isSuccessful());
    }

    public function testInvalidRefundReasonIsWhitelistedToNullOnProcessRefundByCharge(): void
    {
        // Pin: the by-charge path (chargeId-carrying events) must apply the
        // same whitelist — it previously passed the raw string through.
        $refund = $this->buildRefundDto('re_pin_charge', 1000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with('ch_pin_charge', null, null, null)
            ->willReturn($refund);

        $result = $this->createService()->processRefundByCharge('ch_pin_charge', 'garbage-reason');

        $this->assertTrue($result->isSuccessful());
    }

    public function testNonPositiveRefundAmountIsRejectedBeforeAnyAdapterCall(): void
    {
        // Sprint 121 Phase E (STRP-129): defense-in-depth at the convergence
        // point — applies to every caller, not just the admin panel gate.
        $this->stripeAdapter->expects($this->never())->method('retrievePaymentIntent');
        $this->stripeAdapter->expects($this->never())->method('createRefundByCharge');

        $service = $this->createService();

        foreach ([-5.0, 0.0] as $amount) {
            $result = $service->processRefund('order_x', 'pi_x', null, null, 'admin', $amount);

            $this->assertFalse($result->isSuccessful(), "Amount {$amount} must be rejected");
            $this->assertStringContainsString('greater than zero', (string) $result->errorMessage);
        }
    }

    // --- Logging Tests ---

    public function testSuccessfulRefundIsLogged(): void
    {
        // Arrange
        $refund = $this->buildRefundDto('re_logged', 3000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Sprint 24: Now logs twice - once for stock restoration (if orderId present), once for refund
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Refund processed'),
                $this->callback(function ($context) {
                    return isset($context['refund_id']) && $context['refund_id'] === 're_logged';
                })
            );

        // Act - no metadata means no orderId, so no stock restoration log
        $service = $this->createService();
        $service->processRefundByCharge('ch_test'); // Full refund (no amount parameter)
    }

    // --- Stock Restoration Tests (Sprint 24) ---

    public function testSuccessfulRefundCallsStockRestoration(): void
    {
        // Arrange
        $orderId = 'order_stock_test';
        $metadata = ['order_id' => $orderId];

        $refund = $this->buildRefundDto('re_stock', 5000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        $this->stockRestorationService
            ->expects($this->once())
            ->method('restoreStockForOrder')
            ->with($orderId)
            ->willReturn(2);

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test', null, $metadata);

        // Assert
        $this->assertTrue($result->isSuccessful());
    }

    public function testRefundWithoutOrderIdSkipsStockRestoration(): void
    {
        // Arrange - no orderId in metadata
        $refund = $this->buildRefundDto('re_no_stock', 3000, 'eur', 'succeeded');

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Stock restoration should NOT be called
        $this->stockRestorationService
            ->expects($this->never())
            ->method('restoreStockForOrder');

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test');

        // Assert
        $this->assertTrue($result->isSuccessful());
    }

    public function testFailedRefundDoesNotCallStockRestoration(): void
    {
        // Arrange
        $refund = $this->buildRefundDto('re_failed', 2500, 'eur', 'failed');

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturn($refund);

        // Stock restoration should NOT be called on failed refund
        $this->stockRestorationService
            ->expects($this->never())
            ->method('restoreStockForOrder');

        // Act
        $service = $this->createService();
        $result = $service->processRefundByCharge('ch_test', null, ['order_id' => 'order_123']);

        // Assert
        $this->assertFalse($result->isSuccessful());
    }

    public function testFailedRefundIsLoggedAsError(): void
    {
        // Arrange
        $exception = new PaymentAdapterException(
            'stripe',
            'test_code',
            'Test error'
        );

        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willThrowException($exception);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Refund failed'),
                $this->callback(function ($context) {
                    return isset($context['error']) && isset($context['charge_id']);
                })
            );

        // Act
        $service = $this->createService();
        $service->processRefundByCharge('ch_test');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildRefundDto(
        string $id,
        int $amount,
        string $currency,
        string $status,
        ?string $reason = null
    ): StripeRefundDto {
        return new StripeRefundDto(
            id: $id,
            amount: $amount,
            currency: $currency,
            status: $status,
            reason: $reason,
            createdAt: 1700000000,
        );
    }

    private function buildPiDto(
        string $id,
        ?string $latestChargeId,
        string $currency = 'eur'
    ): StripePaymentIntentDto {
        return new StripePaymentIntentDto(
            id: $id,
            status: 'succeeded',
            amount: 10000,
            currency: $currency,
            created: 1700000000,
            latestChargeId: $latestChargeId,
        );
    }

    // =========================================================================
    // Sprint 133 · Story 1 (F3) — partial-refund currency threading
    //
    // The pre-existing testProcessRefundJpyPreservesZeroDecimalAmount in
    // RefundServiceDtoCharacterizationTest only exercises the FULL-refund path
    // (amount === null), where no major->minor conversion happens at all, so it
    // could never catch this. The two-decimal regression is already covered by
    // the EUR partial test above (5.50 => 550), so it is not duplicated here.
    // =========================================================================

    public function testProcessRefund_WhenZeroDecimalCurrency_ConvertsWithoutMultiplier(): void
    {
        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($this->buildPiDto('pi_jpy', 'ch_jpy', 'jpy'));

        // JPY minor unit IS the yen: 1000 yen must reach Stripe as 1000, not 100000.
        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with('ch_jpy', 1000, $this->anything(), $this->anything())
            ->willReturn($this->buildRefundDto('re_jpy', 1000, 'jpy', 'succeeded'));

        $result = $this->createService()
            ->processRefund('order_jpy', 'pi_jpy', null, null, 'admin', 1000.0);

        $this->assertTrue($result->isSuccessful());
    }

    public function testProcessRefund_WhenThreeDecimalCurrency_UsesThousandths(): void
    {
        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($this->buildPiDto('pi_bhd', 'ch_bhd', 'bhd'));

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with('ch_bhd', 1234, $this->anything(), $this->anything())
            ->willReturn($this->buildRefundDto('re_bhd', 1234, 'bhd', 'succeeded'));

        $result = $this->createService()
            ->processRefund('order_bhd', 'pi_bhd', null, null, 'admin', 1.234);

        $this->assertTrue($result->isSuccessful());
    }

    public function testProcessRefund_WhenCurrencyUnresolvable_FailsInsteadOfGuessing(): void
    {
        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($this->buildPiDto('pi_nocur', 'ch_nocur', ''));

        // A partial amount cannot be converted without knowing the currency's
        // exponent. Guessing 2 decimals is what produced the 100x defect.
        $this->stripeAdapter
            ->expects($this->never())
            ->method('createRefundByCharge');

        $result = $this->createService()
            ->processRefund('order_nocur', 'pi_nocur', null, null, 'admin', 10.0);

        $this->assertFalse($result->isSuccessful());
        $this->assertStringContainsString('currency', (string) $result->errorMessage);
    }

    public function testProcessRefund_WhenFullRefundAndCurrencyUnresolvable_StillProceeds(): void
    {
        // Full refund sends no amount, so the currency is irrelevant -- the
        // guard must not turn an unrelated edge case into a refund outage.
        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn($this->buildPiDto('pi_nocur2', 'ch_nocur2', ''));

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createRefundByCharge')
            ->with('ch_nocur2', null, $this->anything(), $this->anything())
            ->willReturn($this->buildRefundDto('re_full', 10000, 'eur', 'succeeded'));

        $result = $this->createService()->processRefund('order_nocur2', 'pi_nocur2');

        $this->assertTrue($result->isSuccessful());
    }

    public function testProcessRefund_PartialRefundPassesPriorRefundedStateAsRequestReference(): void
    {
        // Charge already has 1000 minor units refunded; that pre-state is what
        // makes a *retry* of this submit identical and a *later* identical
        // partial refund distinct. See Sprint 133 Story 0 findings.
        $charge = new StripeChargeDto(
            id: 'ch_state',
            amount: 10000,
            amountCaptured: 10000,
            amountRefunded: 1000,
            currency: 'eur',
            captured: true,
            created: 1700000000,
        );

        $this->stripeAdapter
            ->method('retrievePaymentIntent')
            ->willReturn(new StripePaymentIntentDto(
                id: 'pi_state',
                status: 'succeeded',
                amount: 10000,
                currency: 'eur',
                created: 1700000000,
                latestChargeId: 'ch_state',
                charge: $charge,
            ));

        $seenReference = 'not-called';
        $this->stripeAdapter
            ->method('createRefundByCharge')
            ->willReturnCallback(function (
                string $chargeId,
                ?int $amount = null,
                ?string $reason = null,
                ?array $metadata = null,
                ?string $requestReference = null
            ) use (&$seenReference): StripeRefundDto {
                $seenReference = $requestReference;
                return $this->buildRefundDto('re_state', 500, 'eur', 'succeeded');
            });

        $result = $this->createService()
            ->processRefund('order_state', 'pi_state', null, null, 'admin', 5.0);

        $this->assertTrue($result->isSuccessful());
        $this->assertIsString($seenReference, 'A request reference must be threaded to the adapter.');
        $this->assertStringContainsString(
            '1000',
            $seenReference,
            'The reference must encode the prior refunded total.'
        );
    }
}
