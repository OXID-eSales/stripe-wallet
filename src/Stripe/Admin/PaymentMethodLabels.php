<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Admin;

/**
 * Raw Stripe payment-method code → admin language key.
 *
 * Sprint 136: the admin "Payment" tab shows the method the customer actually
 * paid with. Stripe reports it as a lowercase code in
 * `charge.payment_method_details.type` (or `card.wallet.type` for wallets);
 * this map turns that code into a translatable ident.
 *
 * Stateless, deterministic, dependency-free — hence `final` + static rather
 * than an injected service (see the module's static-for-pure-utilities rule).
 * It lives under `Admin/` and not `Service/`, which `services.yaml` sweeps as
 * a DI resource.
 *
 * An unmapped code deliberately returns null instead of an "unknown" key:
 * Stripe adds methods faster than this map grows, and showing the operator
 * `boleto` is strictly more useful than showing them "Unknown".
 */
final class PaymentMethodLabels
{
    /**
     * @var array<string, string>
     */
    private const LABEL_KEYS = [
        // Cards and wallets
        'card'             => 'STRIPE_PAYMENT_METHOD_CARD',
        'apple_pay'        => 'STRIPE_PAYMENT_METHOD_APPLE_PAY',
        'google_pay'       => 'STRIPE_PAYMENT_METHOD_GOOGLE_PAY',
        'link'             => 'STRIPE_PAYMENT_METHOD_LINK',
        // Buy now, pay later
        'klarna'           => 'STRIPE_PAYMENT_METHOD_KLARNA',
        'afterpay_clearpay' => 'STRIPE_PAYMENT_METHOD_AFTERPAY_CLEARPAY',
        // Wallets / accounts
        'paypal'           => 'STRIPE_PAYMENT_METHOD_PAYPAL',
        'revolut_pay'      => 'STRIPE_PAYMENT_METHOD_REVOLUT_PAY',
        'alipay'           => 'STRIPE_PAYMENT_METHOD_ALIPAY',
        'wechat_pay'       => 'STRIPE_PAYMENT_METHOD_WECHAT_PAY',
        'twint'            => 'STRIPE_PAYMENT_METHOD_TWINT',
        // Bank debits and transfers
        'sepa_debit'       => 'STRIPE_PAYMENT_METHOD_SEPA_DEBIT',
        'us_bank_account'  => 'STRIPE_PAYMENT_METHOD_US_BANK_ACCOUNT',
        'customer_balance' => 'STRIPE_PAYMENT_METHOD_CUSTOMER_BALANCE',
        'multibanco'       => 'STRIPE_PAYMENT_METHOD_MULTIBANCO',
        // Bank redirects
        'sofort'           => 'STRIPE_PAYMENT_METHOD_SOFORT',
        'giropay'          => 'STRIPE_PAYMENT_METHOD_GIROPAY',
        'eps'              => 'STRIPE_PAYMENT_METHOD_EPS',
        'p24'              => 'STRIPE_PAYMENT_METHOD_P24',
        'ideal'            => 'STRIPE_PAYMENT_METHOD_IDEAL',
        'bancontact'       => 'STRIPE_PAYMENT_METHOD_BANCONTACT',
    ];

    /**
     * Language key for a raw Stripe method or wallet code, or null when the
     * code is unmapped (caller shows the raw code) or empty.
     */
    public static function keyFor(string $rawType): ?string
    {
        return self::LABEL_KEYS[$rawType] ?? null;
    }
}
