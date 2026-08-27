<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

use OxidEsales\Payments\Stripe\Adapter\Dto\StripeChargeDto;

/**
 * What the customer actually paid with, as the admin panel needs to show it.
 *
 * Sprint 136: `OXPAYMENTTYPE` only ever says `oscstripe`. The real answer —
 * card, Klarna, PayPal, SEPA, a wallet — lives on the Stripe charge, and it
 * changes what an operator does next (settlement times and dispute channels
 * differ per method). This value object is the projection of that charge onto
 * the two strings the panel renders: a label and an optional card detail.
 *
 * "Unknown" is a real, expected state (nothing charged yet, or Stripe
 * unreachable) and renders as an em dash — never as a guess.
 */
final readonly class PaymentMethodDescriptor
{
    /**
     * Card brands are proper nouns, so they are cased rather than translated.
     * An unlisted brand is shown verbatim — the code Stripe sent beats a
     * prettier lie.
     *
     * @var array<string, string>
     */
    private const BRAND_NAMES = [
        'visa'       => 'Visa',
        'mastercard' => 'Mastercard',
        'amex'       => 'American Express',
        'discover'   => 'Discover',
        'diners'     => 'Diners Club',
        'jcb'        => 'JCB',
        'unionpay'   => 'UnionPay',
    ];

    private const MASK = '••••';

    private function __construct(
        public ?string $rawType,
        public ?string $cardBrand,
        public ?string $cardLast4,
        public ?string $walletType,
    ) {
    }

    public static function fromCharge(?StripeChargeDto $charge): self
    {
        if ($charge === null) {
            return new self(null, null, null, null);
        }

        return new self(
            $charge->paymentMethodType,
            $charge->cardBrand,
            $charge->cardLast4,
            $charge->walletType,
        );
    }

    /**
     * False when the PSP has not told us the method: no charge exists yet, the
     * charge carried no `payment_method_details`, or the API read failed.
     */
    public function isKnown(): bool
    {
        return $this->rawType !== null && $this->rawType !== '';
    }

    /**
     * The code that names this payment for the operator. A wallet outranks the
     * card it fronted: an Apple Pay payment reads as "Apple Pay", with the
     * underlying card demoted into {@see detail()}.
     */
    public function displayType(): ?string
    {
        if (!$this->isKnown()) {
            return null;
        }

        if ($this->walletType !== null && $this->walletType !== '') {
            return $this->walletType;
        }

        return $this->rawType;
    }

    /**
     * Language key for {@see displayType()}, or null when the method is unknown
     * or unmapped — the caller then shows the raw code verbatim.
     */
    public function labelKey(): ?string
    {
        $type = $this->displayType();

        if ($type === null) {
            return null;
        }

        return PaymentMethodLabels::keyFor($type);
    }

    /**
     * Card sub-detail: "Visa •••• 4242", "Visa", "•••• 4242" — or null for any
     * method that has no card behind it.
     */
    public function detail(): ?string
    {
        $brand = $this->brandName();
        $last4 = ($this->cardLast4 !== null && $this->cardLast4 !== '') ? $this->cardLast4 : null;

        if ($brand !== null && $last4 !== null) {
            return $brand . ' ' . self::MASK . ' ' . $last4;
        }

        if ($last4 !== null) {
            return self::MASK . ' ' . $last4;
        }

        return $brand;
    }

    private function brandName(): ?string
    {
        if ($this->cardBrand === null || $this->cardBrand === '') {
            return null;
        }

        return self::BRAND_NAMES[$this->cardBrand] ?? $this->cardBrand;
    }
}
