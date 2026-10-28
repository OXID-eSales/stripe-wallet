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

    public function testStoreEndpoint_KeepsIdAndSecretPerModeAndTheLegacySettingsForTheForm(): void
    {
        $this->state()->storeEndpoint('live', 'we_live', 'whsec_livesecret1', 'https://shop.example/hook');

        self::assertSame('we_live', $this->vars->values['sStripeWebhookEndpointIdLive']);
        self::assertSame('whsec_livesecret1', $this->vars->values['sStripeWebhookEndpointSecretLive']);
        self::assertSame('https://shop.example/hook', $this->module->values['sStripeWebhookEndpoint']);
        self::assertSame('whsec_livesecret1', $this->module->values['sStripeWebhookEndpointSecret']);
        self::assertArrayNotHasKey('sStripeWebhookEndpointIdTest', $this->vars->values);
    }

    public function testStoreEndpoint_WithoutASecret_KeepsTheStoredOne(): void
    {
        $this->vars->values = ['sStripeWebhookEndpointSecretTest' => 'whsec_keep'];

        $this->state()->storeEndpoint('test', 'we_t', null, 'https://shop.example/hook');

        self::assertSame('whsec_keep', $this->vars->values['sStripeWebhookEndpointSecretTest']);
        self::assertSame('we_t', $this->vars->values['sStripeWebhookEndpointIdTest']);
        self::assertArrayNotHasKey('sStripeWebhookEndpointSecret', $this->module->values);
    }

    public function testStoreSecret_IsPerMode(): void
    {
        $this->state()->storeSecret('test', 'whsec_pasted12');

        self::assertSame('whsec_pasted12', $this->vars->values['sStripeWebhookEndpointSecretTest']);
    }

    public function testForgetAll_ClearsBothModesAndTheLegacySettings(): void
    {
        $this->vars->values = [
            'sStripeWebhookEndpointIdTest' => 'a', 'sStripeWebhookEndpointSecretTest' => 'b',
            'sStripeWebhookEndpointIdLive' => 'c', 'sStripeWebhookEndpointSecretLive' => 'd',
        ];
        $this->module->values = ['sStripeWebhookEndpoint' => 'u', 'sStripeWebhookEndpointSecret' => 's'];

        $this->state()->forgetAll();

        self::assertSame(['', '', '', ''], array_values($this->vars->values));
        self::assertSame(['', ''], array_values($this->module->values));
        self::assertFalse($this->state()->hasSecret('test'));
    }

    private function state(): OxidStripeWebhookState
    {
        return new OxidStripeWebhookState($this->vars, $this->module);
    }
}
