<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\Payments\Stripe\Admin\PaymentMethodLabels;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 136 (STRP-TBD): raw Stripe method code → admin lang key.
 *
 * One case per supported method, so a newly mapped method cannot silently
 * fall through to the null branch and a removed one cannot go unnoticed.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(PaymentMethodLabels::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-136')]
final class PaymentMethodLabelsTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function supportedMethods(): array
    {
        return [
            'card'            => ['card', 'STRIPE_PAYMENT_METHOD_CARD'],
            'klarna'          => ['klarna', 'STRIPE_PAYMENT_METHOD_KLARNA'],
            'paypal'          => ['paypal', 'STRIPE_PAYMENT_METHOD_PAYPAL'],
            'sepa_debit'      => ['sepa_debit', 'STRIPE_PAYMENT_METHOD_SEPA_DEBIT'],
            'sofort'          => ['sofort', 'STRIPE_PAYMENT_METHOD_SOFORT'],
            'giropay'         => ['giropay', 'STRIPE_PAYMENT_METHOD_GIROPAY'],
            'eps'             => ['eps', 'STRIPE_PAYMENT_METHOD_EPS'],
            'p24'             => ['p24', 'STRIPE_PAYMENT_METHOD_P24'],
            'ideal'           => ['ideal', 'STRIPE_PAYMENT_METHOD_IDEAL'],
            'bancontact'      => ['bancontact', 'STRIPE_PAYMENT_METHOD_BANCONTACT'],
            'link'            => ['link', 'STRIPE_PAYMENT_METHOD_LINK'],
            'us_bank_account' => ['us_bank_account', 'STRIPE_PAYMENT_METHOD_US_BANK_ACCOUNT'],
            'wechat_pay'      => ['wechat_pay', 'STRIPE_PAYMENT_METHOD_WECHAT_PAY'],
            'alipay'          => ['alipay', 'STRIPE_PAYMENT_METHOD_ALIPAY'],
            'revolut_pay'     => ['revolut_pay', 'STRIPE_PAYMENT_METHOD_REVOLUT_PAY'],
            'afterpay'        => ['afterpay_clearpay', 'STRIPE_PAYMENT_METHOD_AFTERPAY_CLEARPAY'],
            'multibanco'      => ['multibanco', 'STRIPE_PAYMENT_METHOD_MULTIBANCO'],
            'twint'           => ['twint', 'STRIPE_PAYMENT_METHOD_TWINT'],
            'apple_pay'       => ['apple_pay', 'STRIPE_PAYMENT_METHOD_APPLE_PAY'],
            'google_pay'      => ['google_pay', 'STRIPE_PAYMENT_METHOD_GOOGLE_PAY'],
            'customer_balance' => ['customer_balance', 'STRIPE_PAYMENT_METHOD_CUSTOMER_BALANCE'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('supportedMethods')]
    public function testSupportedMethodsMapToTheirLangKey(string $raw, string $expected): void
    {
        self::assertSame($expected, PaymentMethodLabels::keyFor($raw));
    }

    public function testUnmappedMethodHasNoKeySoTheRawCodeCanBeShown(): void
    {
        self::assertNull(PaymentMethodLabels::keyFor('some_new_stripe_method'));
    }

    public function testEmptyCodeHasNoKey(): void
    {
        self::assertNull(PaymentMethodLabels::keyFor(''));
    }

    /**
     * Every mapped key must exist in both admin lang files — a label key with
     * no translation renders as the raw ident in the admin panel.
     */
    public function testEveryMappedKeyExistsInBothAdminLangFiles(): void
    {
        $en = $this->langFile(__DIR__ . '/../../../../views/admin_twig/en/stripe_lang.php');
        $de = $this->langFile(__DIR__ . '/../../../../views/admin_twig/de/stripe_lang.php');

        foreach (self::supportedMethods() as [$raw, $key]) {
            self::assertArrayHasKey($key, $en, "missing EN translation for {$raw}");
            self::assertArrayHasKey($key, $de, "missing DE translation for {$raw}");
        }

        self::assertArrayHasKey('STRIPE_PAYMENT_METHOD_USED', $en);
        self::assertArrayHasKey('STRIPE_PAYMENT_METHOD_USED', $de);
    }

    /**
     * @return array<string, string>
     */
    private function langFile(string $path): array
    {
        self::assertFileExists($path);

        $aLang = [];
        require $path;

        /** @var array<string, string> $aLang */
        return $aLang;
    }
}
