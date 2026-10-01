<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Core;

use PHPUnit\Framework\TestCase;

/**
 * MOL-10 (shared Help) — admin templates see the shop's oViewConf, so Stripe's column of the
 * contract-state Help is handed out by the ViewConfig extension. Asserted on the class source: the
 * real class chains to ViewConfig_parent, which needs the shop's module chain (see ViewConfigDebugTest).
 */
final class ViewConfigContractStateHelpTest extends TestCase
{
    public function testViewConfigDeclaresTheHelpAccessor(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Stripe/Core/ViewConfig.php');

        self::assertMatchesRegularExpression(
            '/public function getStripeContractStateHelp\(\): StripeContractStateHelp/',
            $source
        );
        self::assertStringContainsString('return new StripeContractStateHelp();', $source);
    }
}
