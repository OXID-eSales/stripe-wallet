<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use InvalidArgumentException;
use OxidEsales\PaymentBase\Adapter\Request\CapturePaymentRequest;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\Helper\PaymentIntentHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Charge;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;

/**
 * Sprint 148 (AW): a partial capture names its currency. Without one the amount was converted with two
 * decimals, which is 100 times too much for a zero-decimal currency such as JPY.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(PaymentIntentHelper::class)]
final class PaymentIntentHelperCaptureCurrencyTest extends TestCase
{
    private StripeClient&MockObject $stripeClient;
    private PaymentIntentService&MockObject $paymentIntents;
    private PaymentIntentHelper $helper;

    protected function setUp(): void
    {
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->paymentIntents = $this->createMock(PaymentIntentService::class);
        $this->stripeClient->paymentIntents = $this->paymentIntents;
        $this->helper = new PaymentIntentHelper($this->createMock(IdempotencyRepositoryInterface::class));
    }

    public function testCapturePaymentIntent_WhenPartialAndNoCurrency_ThrowsAndDoesNotCallStripe(): void
    {
        $this->paymentIntents->expects($this->never())->method('capture');

        $this->expectException(InvalidArgumentException::class);

        $this->helper->capturePaymentIntent($this->stripeClient, new CapturePaymentRequest('pi_1', 15.0));
    }

    public function testCapturePaymentIntent_WhenPartialAndEmptyCurrency_Throws(): void
    {
        $this->paymentIntents->expects($this->never())->method('capture');

        $this->expectException(InvalidArgumentException::class);

        $this->helper->capturePaymentIntent($this->stripeClient, new CapturePaymentRequest('pi_1', 15.0, [], ''));
    }

    public function testCapturePaymentIntent_WhenPartialInJpy_SendsTheAmountWithoutDecimals(): void
    {
        $this->paymentIntents->expects($this->once())->method('capture')
            ->with('pi_1', $this->callback(static fn (array $params): bool => $params['amount_to_capture'] === 1500))
            ->willReturn($this->captured(1500, 'jpy'));
        $this->paymentIntents->method('retrieve')->willReturn($this->captured(1500, 'jpy'));

        $this->helper->capturePaymentIntent($this->stripeClient, new CapturePaymentRequest('pi_1', 1500.0, [], 'JPY'));
    }

    public function testCapturePaymentIntent_WhenPartialInEur_SendsCents(): void
    {
        $this->paymentIntents->expects($this->once())->method('capture')
            ->with('pi_1', $this->callback(static fn (array $params): bool => $params['amount_to_capture'] === 1500))
            ->willReturn($this->captured(1500, 'eur'));
        $this->paymentIntents->method('retrieve')->willReturn($this->captured(1500, 'eur'));

        $this->helper->capturePaymentIntent($this->stripeClient, new CapturePaymentRequest('pi_1', 15.0, [], 'EUR'));
    }

    public function testCapturePaymentIntent_WhenFull_SendsNoAmountAndNeedsNoCurrency(): void
    {
        $this->paymentIntents->expects($this->once())->method('capture')
            ->with('pi_1', $this->callback(static fn (array $params): bool => !isset($params['amount_to_capture'])))
            ->willReturn($this->captured(5000, 'eur'));
        $this->paymentIntents->method('retrieve')->willReturn($this->captured(5000, 'eur'));

        $this->helper->capturePaymentIntent($this->stripeClient, new CapturePaymentRequest('pi_1'));
    }

    private function captured(int $amountReceived, string $currency): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => 'pi_1',
            'status' => 'succeeded',
            'amount' => $amountReceived,
            'amount_received' => $amountReceived,
            'currency' => $currency,
            'latest_charge' => Charge::constructFrom(['id' => 'ch_1', 'created' => 1700000000]),
        ]);
    }
}
