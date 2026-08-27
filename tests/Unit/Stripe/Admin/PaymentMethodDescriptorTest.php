<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;
use OxidEsales\Payments\Stripe\Admin\PaymentMethodDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 136 (STRP-TBD): the admin panel's answer to "what did the customer
 * actually pay with?", derived from the charge the PSP reported.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(PaymentMethodDescriptor::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-136')]
final class PaymentMethodDescriptorTest extends TestCase
{
    public function testNoChargeIsUnknown(): void
    {
        $descriptor = PaymentMethodDescriptor::fromCharge(null);

        self::assertFalse($descriptor->isKnown());
        self::assertNull($descriptor->rawType);
        self::assertNull($descriptor->labelKey());
        self::assertNull($descriptor->detail());
    }

    public function testChargeWithoutPaymentMethodTypeIsUnknown(): void
    {
        // A charge mapped from a response that carried no payment_method_details.
        $descriptor = PaymentMethodDescriptor::fromCharge($this->charge(null));

        self::assertFalse($descriptor->isKnown());
        self::assertNull($descriptor->labelKey());
    }

    public function testKlarnaHasALabelKeyAndNoCardDetail(): void
    {
        $descriptor = PaymentMethodDescriptor::fromCharge($this->charge('klarna'));

        self::assertTrue($descriptor->isKnown());
        self::assertSame('klarna', $descriptor->rawType);
        self::assertSame('STRIPE_PAYMENT_METHOD_KLARNA', $descriptor->labelKey());
        self::assertNull($descriptor->detail());
    }

    public function testCardCarriesBrandAndLast4AsDetail(): void
    {
        $descriptor = PaymentMethodDescriptor::fromCharge(
            $this->charge('card', 'visa', '4242')
        );

        self::assertSame('STRIPE_PAYMENT_METHOD_CARD', $descriptor->labelKey());
        self::assertSame('Visa •••• 4242', $descriptor->detail());
    }

    public function testCardBrandAliasesGetTheirProperName(): void
    {
        self::assertSame(
            'American Express •••• 0005',
            PaymentMethodDescriptor::fromCharge($this->charge('card', 'amex', '0005'))->detail()
        );
        self::assertSame(
            'Diners Club •••• 0004',
            PaymentMethodDescriptor::fromCharge($this->charge('card', 'diners', '0004'))->detail()
        );
    }

    public function testUnmappedCardBrandIsShownVerbatimNotSwallowed(): void
    {
        $descriptor = PaymentMethodDescriptor::fromCharge(
            $this->charge('card', 'cartes_bancaires', '1234')
        );

        self::assertSame('cartes_bancaires •••• 1234', $descriptor->detail());
    }

    public function testCardWithoutLast4FallsBackToBrandOnly(): void
    {
        $descriptor = PaymentMethodDescriptor::fromCharge($this->charge('card', 'visa', null));

        self::assertSame('Visa', $descriptor->detail());
    }

    public function testCardWithoutBrandFallsBackToLast4Only(): void
    {
        $descriptor = PaymentMethodDescriptor::fromCharge($this->charge('card', null, '4242'));

        self::assertSame('•••• 4242', $descriptor->detail());
    }

    /**
     * A wallet payment is not "a card payment" to the operator on the phone:
     * the wallet takes the label and the card is demoted into the detail.
     */
    public function testWalletTakesTheLabelAndDemotesTheCard(): void
    {
        $descriptor = PaymentMethodDescriptor::fromCharge(
            $this->charge('card', 'mastercard', '0007', 'apple_pay')
        );

        self::assertSame('apple_pay', $descriptor->displayType());
        self::assertSame('STRIPE_PAYMENT_METHOD_APPLE_PAY', $descriptor->labelKey());
        self::assertSame('Mastercard •••• 0007', $descriptor->detail());
    }

    public function testUnmappedMethodIsKnownButHasNoLabelKey(): void
    {
        // Stripe adds methods faster than this module maps them; the operator
        // must see the raw code rather than the word "unknown".
        $descriptor = PaymentMethodDescriptor::fromCharge($this->charge('boleto'));

        self::assertTrue($descriptor->isKnown());
        self::assertSame('boleto', $descriptor->displayType());
        self::assertNull($descriptor->labelKey());
    }

    private function charge(
        ?string $type,
        ?string $brand = null,
        ?string $last4 = null,
        ?string $wallet = null,
    ): StripeChargeDto {
        return new StripeChargeDto(
            id: 'ch_test',
            amount: 10000,
            amountCaptured: 10000,
            amountRefunded: 0,
            currency: 'eur',
            captured: true,
            created: 0,
            paymentMethodType: $type,
            cardBrand: $brand,
            cardLast4: $last4,
            walletType: $wallet,
        );
    }
}
