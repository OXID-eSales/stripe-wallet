<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use PHPUnit\Framework\TestCase;

/**
 * MOL-10 (shared Help) — Stripe's Settings tab ends with payment-base's Help group carrying Stripe's
 * column, and the order panel shows "OXID Contract Status" with payment-base's "?" hint. Source
 * assertions: the Unit suite renders no admin templates.
 */
final class StripeAdminHelpTemplatesGuardTest extends TestCase
{
    public function testModuleConfigAddsTheSharedHelpGroupAfterTheLastGroupForStripeOnly(): void
    {
        $t = $this->source('views/twig/extensions/themes/admin_twig/module_config.html.twig');

        self::assertSame(1, preg_match('/{% block admin_module_config_group %}(.*?){% endblock %}/s', $t, $m));
        self::assertStringContainsString('{{ parent() }}', $m[1]);
        self::assertStringContainsString('loop.last', $m[1]);
        self::assertStringContainsString("getEditObjectId() == 'oe_payments_stripe_wallet'", $m[1]);
        self::assertStringContainsString("'PAYMENT_ADMIN_HELP'", $m[1]);
        self::assertStringContainsString('@oe_payment_base/admin/help/contract_state_table.html.twig', $m[1]);
        self::assertStringContainsString('oViewConf.getStripeContractStateHelp()', $m[1]);
        self::assertStringContainsString('providerStatuses: help.providerStatuses', $m[1]);
    }

    public function testPanelShowsTheContractStateWithTheSharedHint(): void
    {
        $t = $this->source('views/twig/admin/panel/stripe_panel.html.twig');

        self::assertSame(1, preg_match('/<td class="s-label">\s*{{ translate\(\{ ident: "STRIPE_CONTRACT_STATE" \}\) }}(.*?)<\/td>/s', $t, $m), 'an OXID Contract Status row exists');
        self::assertStringContainsString('@oe_payment_base/admin/help/contract_state_hint.html.twig', $m[1]);
        self::assertStringContainsString('providerStatuses: help.providerStatuses', $m[1]);
        self::assertStringContainsString('contractState', $t, 'the value comes from the view data');
    }

    private function source(string $relative): string
    {
        $path = dirname(__DIR__, 4) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
