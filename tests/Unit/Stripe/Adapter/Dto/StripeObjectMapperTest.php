<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Dto;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCheckoutSessionDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeCustomerDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripePaymentIntentDto;
use OxidEsales\Payments\Stripe\Adapter\Dto\StripeRefundDto;
use OxidEsales\Payments\Stripe\Adapter\StripeObjectMapper;
use PHPUnit\Framework\TestCase;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * Sprint 114.10b: Characterization tests for StripeObjectMapper.
 *
 * Feeds recorded SDK-shape fixtures into Stripe::constructFrom() and
 * asserts the resulting DTOs carry the correct fields. No network calls.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Adapter\StripeObjectMapper::class)]
final class StripeObjectMapperTest extends TestCase
{
    // -------------------------------------------------------------------------
    // fromCheckoutSession
    // -------------------------------------------------------------------------

    public function testFromCheckoutSessionWithPaidAutomaticCapture(): void
    {
        // Arrange — paid session, payment_intent is a string ID
        $session = Session::constructFrom([
            'id'              => 'cs_test_abc123',
            'payment_status'  => 'paid',
            'payment_intent'  => 'pi_test_xyz',
            'metadata'        => ['contract_id' => 'contract_42'],
            'amount_total'    => 19900,
            'currency'        => 'eur',
        ]);

        // Act
        $dto = StripeObjectMapper::fromCheckoutSession($session);

        // Assert
        self::assertInstanceOf(StripeCheckoutSessionDto::class, $dto);
        self::assertSame('cs_test_abc123', $dto->id);
        self::assertSame('paid', $dto->paymentStatus);
        self::assertSame('pi_test_xyz', $dto->paymentIntentId);
        self::assertSame('unknown', $dto->paymentIntentStatus);
        self::assertSame(['contract_id' => 'contract_42'], $dto->metadata);
        self::assertSame(19900, $dto->amountTotal);
        self::assertSame('eur', $dto->currency);
    }

    public function testFromCheckoutSessionWithExpandedPaymentIntentObject(): void
    {
        // Arrange — manual capture: payment_intent is an expanded object
        $session = Session::constructFrom([
            'id'             => 'cs_test_def456',
            'payment_status' => 'unpaid',
            'payment_intent' => [
                'id'     => 'pi_test_manual',
                'status' => 'requires_capture',
                'object' => 'payment_intent',
            ],
            'metadata'       => ['contract_id' => 'contract_99'],
            'amount_total'   => 5000,
            'currency'       => 'usd',
        ]);

        // Act
        $dto = StripeObjectMapper::fromCheckoutSession($session);

        // Assert
        self::assertSame('cs_test_def456', $dto->id);
        self::assertSame('unpaid', $dto->paymentStatus);
        self::assertSame('pi_test_manual', $dto->paymentIntentId);
        self::assertSame('requires_capture', $dto->paymentIntentStatus);
        self::assertSame(5000, $dto->amountTotal);
        self::assertSame('usd', $dto->currency);
    }

    public function testFromCheckoutSessionWithNoPaymentIntent(): void
    {
        // Arrange — session without payment_intent set
        $session = Session::constructFrom([
            'id'             => 'cs_test_empty',
            'payment_status' => 'unpaid',
            'payment_intent' => null,
            'metadata'       => [],
            'amount_total'   => 0,
            'currency'       => 'eur',
        ]);

        // Act
        $dto = StripeObjectMapper::fromCheckoutSession($session);

        // Assert — empty string for missing PI id
        self::assertSame('', $dto->paymentIntentId);
        self::assertSame('unknown', $dto->paymentIntentStatus);
    }

    // -------------------------------------------------------------------------
    // fromPaymentIntent
    // -------------------------------------------------------------------------

    public function testFromPaymentIntentWithoutExpandedCharge(): void
    {
        // Arrange — PI with latest_charge as string ID (not expanded)
        $pi = PaymentIntent::constructFrom([
            'id'             => 'pi_test_123',
            'status'         => 'succeeded',
            'amount'         => 10000,
            'amount_received'   => 10000,
            'amount_capturable' => 0,
            'currency'       => 'eur',
            'latest_charge'  => 'ch_test_abc',
            'client_secret'  => 'pi_test_123_secret_xyz',
            'metadata'       => ['order_id' => 'order_1'],
            'created'        => 1700000000,
        ]);

        // Act
        $dto = StripeObjectMapper::fromPaymentIntent($pi);

        // Assert
        self::assertInstanceOf(StripePaymentIntentDto::class, $dto);
        self::assertSame('pi_test_123', $dto->id);
        self::assertSame('succeeded', $dto->status);
        self::assertSame(10000, $dto->amount);
        self::assertSame('eur', $dto->currency);
        self::assertSame(1700000000, $dto->created);
        self::assertSame('ch_test_abc', $dto->latestChargeId);
        self::assertNull($dto->charge);
    }

    public function testFromPaymentIntentWithExpandedCharge(): void
    {
        // Arrange — PI with expanded Charge object (no refunds)
        $pi = PaymentIntent::constructFrom([
            'id'             => 'pi_test_456',
            'status'         => 'requires_capture',
            'amount'         => 39700,
            'amount_received'   => 0,
            'amount_capturable' => 39700,
            'currency'       => 'eur',
            'latest_charge'  => [
                'id'              => 'ch_test_expand',
                'amount'          => 39700,
                'amount_captured' => 10000,
                'amount_refunded' => 29700,
                'currency'        => 'eur',
                'captured'        => true,
                'created'         => 1700000100,
                'object'          => 'charge',
                'refunds'         => [
                    'object' => 'list',
                    'data'   => [],
                ],
            ],
            'client_secret'  => null,
            'metadata'       => [],
            'created'        => 1700000000,
        ]);

        // Act
        $dto = StripeObjectMapper::fromPaymentIntent($pi);

        // Assert PI fields
        self::assertSame('pi_test_456', $dto->id);
        self::assertSame('requires_capture', $dto->status);

        // Assert expanded charge
        $charge = $dto->charge;
        self::assertNotNull($charge);
        self::assertInstanceOf(StripeChargeDto::class, $charge);
        self::assertSame('ch_test_expand', $charge->id);
        self::assertSame(39700, $charge->amount);
        self::assertSame(10000, $charge->amountCaptured);
        self::assertSame(29700, $charge->amountRefunded);
        self::assertSame('eur', $charge->currency);
        self::assertTrue($charge->captured);
        self::assertSame(1700000100, $charge->created);
        self::assertSame([], $charge->refunds);
    }

    // -------------------------------------------------------------------------
    // fromCharge
    // -------------------------------------------------------------------------

    public function testFromChargeWithRefunds(): void
    {
        // Arrange — charge with two refunds
        $charge = Charge::constructFrom([
            'id'              => 'ch_refund_test',
            'amount'          => 10000,
            'amount_captured' => 10000,
            'amount_refunded' => 5000,
            'currency'        => 'eur',
            'captured'        => true,
            'created'         => 1700000200,
            'refunds'         => [
                'object' => 'list',
                'data'   => [
                    [
                        'id'       => 'rf_test_1',
                        'amount'   => 3000,
                        'currency' => 'eur',
                        'status'   => 'succeeded',
                        'reason'   => null,
                        'created'  => 1700000300,
                        'object'   => 'refund',
                    ],
                    [
                        'id'       => 'rf_test_2',
                        'amount'   => 2000,
                        'currency' => 'eur',
                        'status'   => 'pending',
                        'reason'   => 'requested_by_customer',
                        'created'  => 1700000400,
                        'object'   => 'refund',
                    ],
                ],
            ],
        ]);

        // Act
        $dto = StripeObjectMapper::fromCharge($charge);

        // Assert charge fields
        self::assertInstanceOf(StripeChargeDto::class, $dto);
        self::assertSame('ch_refund_test', $dto->id);
        self::assertSame(10000, $dto->amount);
        self::assertSame(10000, $dto->amountCaptured);
        self::assertSame(5000, $dto->amountRefunded);
        self::assertSame('eur', $dto->currency);
        self::assertTrue($dto->captured);
        self::assertSame(1700000200, $dto->created);

        // Assert refund sub-objects
        self::assertCount(2, $dto->refunds);

        $r1 = $dto->refunds[0];
        self::assertInstanceOf(StripeRefundDto::class, $r1);
        self::assertSame('rf_test_1', $r1->id);
        self::assertSame(3000, $r1->amount);
        self::assertSame('eur', $r1->currency);
        self::assertSame('succeeded', $r1->status);
        self::assertNull($r1->reason);
        self::assertSame(1700000300, $r1->createdAt);

        $r2 = $dto->refunds[1];
        self::assertSame('rf_test_2', $r2->id);
        self::assertSame(2000, $r2->amount);
        self::assertSame('pending', $r2->status);
        self::assertSame('requested_by_customer', $r2->reason);
    }

    public function testFromChargeWithNoRefunds(): void
    {
        // Arrange — charge with empty refunds list
        $charge = Charge::constructFrom([
            'id'              => 'ch_no_refund',
            'amount'          => 5000,
            'amount_captured' => 5000,
            'amount_refunded' => 0,
            'currency'        => 'jpy',
            'captured'        => true,
            'created'         => 1700000500,
        ]);

        // Act
        $dto = StripeObjectMapper::fromCharge($charge);

        // Assert — JPY: minor units = whole yen (no division by 100)
        self::assertSame('jpy', $dto->currency);
        self::assertSame(5000, $dto->amountCaptured);
        self::assertSame([], $dto->refunds);
    }

    // -------------------------------------------------------------------------
    // fromRefund
    // -------------------------------------------------------------------------

    public function testFromRefund(): void
    {
        // Arrange
        $refund = Refund::constructFrom([
            'id'       => 'rf_standalone',
            'amount'   => 7500,
            'currency' => 'gbp',
            'status'   => 'succeeded',
            'reason'   => 'duplicate',
            'created'  => 1700000600,
        ]);

        // Act
        $dto = StripeObjectMapper::fromRefund($refund);

        // Assert
        self::assertInstanceOf(StripeRefundDto::class, $dto);
        self::assertSame('rf_standalone', $dto->id);
        self::assertSame(7500, $dto->amount);
        self::assertSame('gbp', $dto->currency);
        self::assertSame('succeeded', $dto->status);
        self::assertSame('duplicate', $dto->reason);
        self::assertSame(1700000600, $dto->createdAt);
    }

    // -------------------------------------------------------------------------
    // fromCustomer
    // -------------------------------------------------------------------------

    public function testFromCustomer(): void
    {
        // Arrange
        $customer = Customer::constructFrom([
            'id'       => 'cus_test_123',
            'email'    => 'test@example.com',
            'metadata' => ['shop_customer_id' => '42'],
        ]);

        // Act
        $dto = StripeObjectMapper::fromCustomer($customer);

        // Assert
        self::assertInstanceOf(StripeCustomerDto::class, $dto);
        self::assertSame('cus_test_123', $dto->id);
        self::assertSame('test@example.com', $dto->email);
        self::assertSame(['shop_customer_id' => '42'], $dto->metadata);
    }

    public function testFromCustomerWithNullEmail(): void
    {
        // Arrange — customer without email set
        $customer = Customer::constructFrom([
            'id'       => 'cus_no_email',
            'metadata' => [],
        ]);

        // Act
        $dto = StripeObjectMapper::fromCustomer($customer);

        // Assert
        self::assertNull($dto->email);
        self::assertSame([], $dto->metadata);
    }

    // -------------------------------------------------------------------------
    // JPY parity (zero-decimal currency)
    // -------------------------------------------------------------------------

    public function testFromChargeJpyAmountsAreIntegerMinorUnits(): void
    {
        // Arrange — JPY charge: Stripe stores yen directly, no division by 100
        $charge = Charge::constructFrom([
            'id'              => 'ch_jpy',
            'amount'          => 1000,
            'amount_captured' => 1000,
            'amount_refunded' => 0,
            'currency'        => 'jpy',
            'captured'        => true,
            'created'         => 1700000700,
        ]);

        // Act
        $dto = StripeObjectMapper::fromCharge($charge);

        // Assert — amounts stored as int minor units, no conversion in mapper
        self::assertSame(1000, $dto->amount);
        self::assertSame(1000, $dto->amountCaptured);
        self::assertSame(0, $dto->amountRefunded);
        self::assertSame('jpy', $dto->currency);
    }

    // -------------------------------------------------------------------------
    // Sprint 136 (STRP-TBD): payment_method_details survives the boundary
    // -------------------------------------------------------------------------

    public function testFromChargeMapsKlarnaPaymentMethodType(): void
    {
        // Arrange — a Klarna charge carries no card sub-object
        $charge = Charge::constructFrom([
            'id'                     => 'ch_klarna',
            'amount'                 => 12500,
            'amount_captured'        => 12500,
            'amount_refunded'        => 0,
            'currency'               => 'eur',
            'captured'               => true,
            'created'                => 1700001000,
            'payment_method_details' => [
                'type'   => 'klarna',
                'klarna' => ['payment_method_category' => 'pay_later'],
            ],
        ]);

        // Act
        $dto = StripeObjectMapper::fromCharge($charge);

        // Assert
        self::assertSame('klarna', $dto->paymentMethodType);
        self::assertNull($dto->cardBrand);
        self::assertNull($dto->cardLast4);
        self::assertNull($dto->walletType);
    }

    public function testFromChargeMapsCardBrandLast4AndWallet(): void
    {
        // Arrange — Apple Pay is a card charge with a wallet sub-object
        $charge = Charge::constructFrom([
            'id'                     => 'ch_card_wallet',
            'amount'                 => 4200,
            'amount_captured'        => 4200,
            'amount_refunded'        => 0,
            'currency'               => 'eur',
            'captured'               => true,
            'created'                => 1700001100,
            'payment_method_details' => [
                'type' => 'card',
                'card' => [
                    'brand'  => 'visa',
                    'last4'  => '4242',
                    'wallet' => ['type' => 'apple_pay'],
                ],
            ],
        ]);

        // Act
        $dto = StripeObjectMapper::fromCharge($charge);

        // Assert
        self::assertSame('card', $dto->paymentMethodType);
        self::assertSame('visa', $dto->cardBrand);
        self::assertSame('4242', $dto->cardLast4);
        self::assertSame('apple_pay', $dto->walletType);
    }

    public function testFromChargeMapsPlainCardWithoutWallet(): void
    {
        // Arrange
        $charge = Charge::constructFrom([
            'id'                     => 'ch_card_plain',
            'amount'                 => 9900,
            'amount_captured'        => 9900,
            'amount_refunded'        => 0,
            'currency'               => 'eur',
            'captured'               => true,
            'created'                => 1700001200,
            'payment_method_details' => [
                'type' => 'card',
                'card' => ['brand' => 'mastercard', 'last4' => '0007'],
            ],
        ]);

        // Act
        $dto = StripeObjectMapper::fromCharge($charge);

        // Assert
        self::assertSame('card', $dto->paymentMethodType);
        self::assertSame('mastercard', $dto->cardBrand);
        self::assertSame('0007', $dto->cardLast4);
        self::assertNull($dto->walletType);
    }

    public function testFromChargeWithoutPaymentMethodDetailsYieldsNulls(): void
    {
        // Arrange — a Charge shape that predates / omits the sub-object must not throw
        $charge = Charge::constructFrom([
            'id'              => 'ch_no_details',
            'amount'          => 1000,
            'amount_captured' => 1000,
            'amount_refunded' => 0,
            'currency'        => 'eur',
            'captured'        => true,
            'created'         => 1700001300,
        ]);

        // Act
        $dto = StripeObjectMapper::fromCharge($charge);

        // Assert
        self::assertNull($dto->paymentMethodType);
        self::assertNull($dto->cardBrand);
        self::assertNull($dto->cardLast4);
        self::assertNull($dto->walletType);
    }

    public function testPaymentMethodDetailsSurviveTheExpandedPaymentIntent(): void
    {
        // Arrange — this is the shape the admin panel actually receives:
        // getPaymentIntentWithRefunds() expands latest_charge.refunds, and
        // payment_method_details rides along inside the expanded Charge.
        $pi = PaymentIntent::constructFrom([
            'id'            => 'pi_expanded_pm',
            'status'        => 'succeeded',
            'amount'        => 12500,
            'currency'      => 'eur',
            'created'       => 1700001400,
            'latest_charge' => [
                'id'                     => 'ch_expanded_pm',
                'object'                 => 'charge',
                'amount'                 => 12500,
                'amount_captured'        => 12500,
                'amount_refunded'        => 0,
                'currency'               => 'eur',
                'captured'               => true,
                'created'                => 1700001400,
                'payment_method_details' => ['type' => 'paypal'],
            ],
        ]);

        // Act
        $dto = StripeObjectMapper::fromPaymentIntent($pi);

        // Assert — no extra API call and no expand-list change is needed
        self::assertNotNull($dto->charge);
        self::assertSame('paypal', $dto->charge->paymentMethodType);
    }
}
