<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OxidStripeWebhookState;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryConfVars;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryModuleSettings;
use PHPUnit\Framework\TestCase;

final class OxidStripeWebhookStateTest extends TestCase
{
    private InMemoryConfVars $vars;
    private InMemoryModuleSettings $module;

    protected function setUp(): void
    {
        $this->vars = new InMemoryConfVars();
        $this->module = new InMemoryModuleSettings();
    }

    public function testEndpointId_IsPerMode(): void
    {
        $this->vars->values = ['sStripeWebhookEndpointIdTest' => 'we_test', 'sStripeWebhookEndpointIdLive' => 'we_live'];

        self::assertSame('we_test', $this->state()->endpointId('test'));
        self::assertSame('we_live', $this->state()->endpointId('live'));
    }

    public function testHasSecret_PerModeSecret(): void
    {
        $this->vars->values = ['sStripeWebhookEndpointSecretTest' => 'whsec_a'];

        self::assertTrue($this->state()->hasSecret('test'));
        self::assertFalse($this->state()->hasSecret('live'));
    }

    public function testHasSecret_TheLegacySecretCountsWhileTheOtherModeHasNone(): void
    {
        $this->module->values['sStripeWebhookEndpointSecret'] = 'whsec_legacy';

        self::assertTrue($this->state()->hasSecret('test'));
        self::assertTrue($this->state()->hasSecret('live'));
    }

    public function testHasSecret_TheLegacySecretIsRefusedOnceTheOtherModeHasItsOwn(): void
    {
        $this->module->values['sStripeWebhookEndpointSecret'] = 'whsec_legacy';
        $this->vars->values = ['sStripeWebhookEndpointSecretLive' => 'whsec_live'];

        self::assertFalse($this->state()->hasSecret('test'), 'the legacy value belongs to the earlier era');
        self::assertTrue($this->state()->hasSecret('live'));
    }

    private function state(): OxidStripeWebhookState
    {
        return new OxidStripeWebhookState($this->vars, $this->module);
    }
}
