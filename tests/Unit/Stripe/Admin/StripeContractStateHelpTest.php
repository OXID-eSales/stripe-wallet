<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\PaymentBase\Admin\Help\ContractStateHelp;
use OxidEsales\PaymentBase\Admin\Help\ContractStateHelpRow;
use OxidEsales\Payments\Stripe\Admin\StripeContractStateHelp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MOL-10 (shared Help) — Stripe's column of payment-base's contract-state Help: one PaymentIntent
 * status per shared row, no row without an answer.
 */
#[CoversClass(StripeContractStateHelp::class)]
final class StripeContractStateHelpTest extends TestCase
{
    public function testAnswersEveryPaymentBaseRowAndNothingElse(): void
    {
        $keys = array_map(static fn (ContractStateHelpRow $row): string => $row->key(), (new ContractStateHelp())->rows());

        self::assertSame($keys, array_keys((new StripeContractStateHelp())->providerStatuses()));
    }

    public function testMapsTheContractStatesToStripesPaymentIntentStatuses(): void
    {
        $statuses = (new StripeContractStateHelp())->providerStatuses();

        self::assertSame('requires_payment_method, requires_confirmation, requires_action', $statuses['not_finished']);
        self::assertSame('processing', $statuses['pending']);
        self::assertSame('requires_capture', $statuses['authorized']);
        self::assertSame('succeeded', $statuses['ready_to_commit']);
        self::assertSame('', $statuses['committed'], 'shop-internal, no Stripe status');
        self::assertStringStartsWith('canceled', $statuses['cancelled']);
        self::assertStringContainsString('checkout.session.expired', $statuses['expired']);
        self::assertStringContainsString('payment_intent.payment_failed', $statuses['failed']);
    }

    #[DataProvider('languages')]
    public function testItsIdentsAreTranslatedAndTheLabelReadsOxidContractStatus(string $language): void
    {
        $aLang = [];
        require dirname(__DIR__, 4) . "/views/admin_twig/{$language}/stripe_lang.php";
        $help = new StripeContractStateHelp();

        foreach ([$help->introIdent(), $help->columnIdent(), 'STRIPE_CONTRACT_STATE'] as $ident) {
            self::assertArrayHasKey($ident, $aLang, "$ident missing in $language");
            self::assertNotSame('', trim($aLang[$ident]));
        }
        self::assertSame($language === 'en' ? 'OXID Contract Status' : 'OXID-Vertragsstatus', $aLang['STRIPE_CONTRACT_STATE']);
    }

    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }
}
