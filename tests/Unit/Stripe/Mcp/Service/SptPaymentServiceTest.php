<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Adapter\Response\PaymentResponse;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentResult;
use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentService;
use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SptPaymentService.
 *
 * Tests the Shared Payment Token (SPT) confirmation flow which creates
 * a Stripe PaymentIntent from a granted SPT token.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentService
 */
class SptPaymentServiceTest extends TestCase
{
    private StripeAdapterInterface&MockObject $stripeAdapter;
    private ShopAdapterInterface&MockObject $shopAdapter;
    private FileLoggerInterface&MockObject $requestLogger;

    protected function setUp(): void
    {
        $this->stripeAdapter = $this->createMock(StripeAdapterInterface::class);
        $this->shopAdapter = $this->createMock(ShopAdapterInterface::class);
        $this->shopAdapter->method('getShopId')->willReturn('1');
        $this->requestLogger = $this->createMock(FileLoggerInterface::class);
    }

    private function createService(): SptPaymentService
    {
        return new SptPaymentService(
            $this->stripeAdapter,
            $this->shopAdapter,
            $this->requestLogger
        );
    }

    private function createContractMock(
        string $id = 'contract_123',
        float $amount = 49.99,
        string $currency = 'EUR',
        ?string $orderId = 'order_456'
    ): PaymentContractInterface&MockObject {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($id);
        $contract->method('getAmount')->willReturn($amount);
        $contract->method('getCurrency')->willReturn($currency);
        $contract->method('getOrderId')->willReturn($orderId);

        return $contract;
    }

    public function testImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(SptPaymentServiceInterface::class, $service);
    }

    public function testSuccessfulPaymentReturnsSuccess(): void
    {
        $contract = $this->createContractMock();
        $sptToken = 'spt_granted_abc123';

        $paymentResponse = new PaymentResponse(
            providerPaymentId: 'pi_success_123',
            status: 'succeeded',
            amount: 49.99,
            currency: 'eur'
        );

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createPayment')
            ->willReturn($paymentResponse);

        $service = $this->createService();
        $result = $service->confirmWithSpt($contract, $sptToken);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('pi_success_123', $result->getPaymentIntentId());
        $this->assertSame('succeeded', $result->getStatus());
        $this->assertNull($result->getErrorMessage());
    }

    public function testRequiresCaptureReturnsSuccess(): void
    {
        $contract = $this->createContractMock();
        $sptToken = 'spt_granted_manual_capture';

        $paymentResponse = new PaymentResponse(
            providerPaymentId: 'pi_manual_456',
            status: 'requires_capture',
            amount: 49.99,
            currency: 'eur'
        );

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createPayment')
            ->willReturn($paymentResponse);

        $service = $this->createService();
        $result = $service->confirmWithSpt($contract, $sptToken);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('pi_manual_456', $result->getPaymentIntentId());
        $this->assertSame('requires_capture', $result->getStatus());
        $this->assertNull($result->getErrorMessage());
    }

    public function testUnexpectedStatusReturnsFailed(): void
    {
        $contract = $this->createContractMock();
        $sptToken = 'spt_granted_3ds_needed';

        $paymentResponse = new PaymentResponse(
            providerPaymentId: 'pi_3ds_789',
            status: 'requires_action',
            amount: 49.99,
            currency: 'eur'
        );

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createPayment')
            ->willReturn($paymentResponse);

        $service = $this->createService();
        $result = $service->confirmWithSpt($contract, $sptToken);

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('pi_3ds_789', $result->getPaymentIntentId());
        $this->assertSame('Unexpected payment status: requires_action', $result->getErrorMessage());
    }

    public function testExceptionReturnsFailed(): void
    {
        $contract = $this->createContractMock();
        $sptToken = 'spt_granted_will_fail';

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createPayment')
            ->willThrowException(new \RuntimeException('Stripe API error: card_declined'));

        $service = $this->createService();
        $result = $service->confirmWithSpt($contract, $sptToken);

        $this->assertFalse($result->isSuccessful());
        $this->assertNull($result->getPaymentIntentId());
        $this->assertSame('Stripe API error: card_declined', $result->getErrorMessage());
    }

    public function testLoggerIsCalledOnSuccess(): void
    {
        $contract = $this->createContractMock();
        $sptToken = 'spt_granted_logged';

        $paymentResponse = new PaymentResponse(
            providerPaymentId: 'pi_logged_123',
            status: 'succeeded',
            amount: 49.99,
            currency: 'eur'
        );

        $this->stripeAdapter
            ->method('createPayment')
            ->willReturn($paymentResponse);

        $this->requestLogger
            ->expects($this->exactly(2))
            ->method('log');

        $service = $this->createService();
        $service->confirmWithSpt($contract, $sptToken);
    }

    public function testLoggerIsCalledOnException(): void
    {
        $contract = $this->createContractMock();
        $sptToken = 'spt_granted_error_logged';

        $this->stripeAdapter
            ->method('createPayment')
            ->willThrowException(new \RuntimeException('Connection timeout'));

        $this->requestLogger
            ->expects($this->exactly(2))
            ->method('log');

        $service = $this->createService();
        $service->confirmWithSpt($contract, $sptToken);
    }

    public function testServiceWorksWithoutLogger(): void
    {
        $service = new SptPaymentService($this->stripeAdapter, $this->shopAdapter);

        $contract = $this->createContractMock();

        $this->stripeAdapter
            ->method('createPayment')
            ->willThrowException(new \RuntimeException('No logger test'));

        $result = $service->confirmWithSpt($contract, 'spt_granted_no_logger');

        $this->assertFalse($result->isSuccessful());
        $this->assertSame('No logger test', $result->getErrorMessage());
    }

    public function testCurrencyIsLowercased(): void
    {
        $contract = $this->createContractMock(currency: 'USD');
        $sptToken = 'spt_granted_usd';

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createPayment')
            ->with($this->callback(function ($request) {
                return $request->currency === 'usd';
            }))
            ->willReturn(new PaymentResponse(
                providerPaymentId: 'pi_usd',
                status: 'succeeded',
                amount: 49.99,
                currency: 'usd'
            ));

        $service = $this->createService();
        $result = $service->confirmWithSpt($contract, $sptToken);

        $this->assertTrue($result->isSuccessful());
    }

    public function testContractWithNullOrderIdUsesEmptyString(): void
    {
        $contract = $this->createContractMock(orderId: null);
        $sptToken = 'spt_granted_no_order';

        $this->stripeAdapter
            ->expects($this->once())
            ->method('createPayment')
            ->with($this->callback(function ($request) {
                return $request->orderId === '';
            }))
            ->willReturn(new PaymentResponse(
                providerPaymentId: 'pi_no_order',
                status: 'succeeded',
                amount: 49.99,
                currency: 'eur'
            ));

        $service = $this->createService();
        $result = $service->confirmWithSpt($contract, $sptToken);

        $this->assertTrue($result->isSuccessful());
    }
}
