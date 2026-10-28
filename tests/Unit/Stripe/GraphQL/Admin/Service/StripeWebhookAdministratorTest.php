<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookAdministrator;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\WebhookEndpointRegistrationResult;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ConfigFixtures;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\CountingStepUp;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\FakeSuperadminCheck;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryStripeSettings;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryWebhookState;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\RecordingStripeAudit;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ScriptedWebhookRegistrar;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class StripeWebhookAdministratorTest extends TestCase
{
    private InMemoryStripeSettings $settings;
    private InMemoryWebhookState $state;
    private CountingStepUp $stepUp;
    private FakeSuperadminCheck $superadmin;
    private RecordingStripeAudit $audit;

    protected function setUp(): void
    {
        $this->settings = new InMemoryStripeSettings(ConfigFixtures::snapshot());
        $this->state = new InMemoryWebhookState();
        $this->stepUp = new CountingStepUp();
        $this->superadmin = new FakeSuperadminCheck();
        $this->audit = new RecordingStripeAudit();
    }

    public function testStatus_NothingRegistered_IsNotConfigured(): void
    {
        $status = $this->administrator()->status();

        self::assertSame('test', $status->mode);
        self::assertFalse($status->configured);
        self::assertSame('', $status->endpointId);
        self::assertSame('https://shop.example/index.php?cl=stripewebhook', $status->url);
    }

    public function testStatus_AnEndpointWithASecret_IsConfigured(): void
    {
        $this->state = new InMemoryWebhookState(['test' => 'we_123'], ['test' => 'whsec_stored']);

        $status = $this->administrator()->status();

        self::assertTrue($status->configured);
        self::assertSame('we_123', $status->endpointId);
    }

    public function testStatus_NeedsNeitherAStepUpNorAnAudit(): void
    {
        $this->administrator()->status();

        self::assertSame(0, $this->stepUp->required);
        self::assertSame([], $this->audit->rows);
    }

    public function testRegister_RegistersWithTheKeysOfTheModeAndStoresIdAndSecret(): void
    {
        $registrar = new ScriptedWebhookRegistrar();

        $result = $this->administrator($registrar)->register();

        self::assertNull($result->refusal());
        self::assertSame(
            [[
                'accountKey' => 'sk_test_AAAAAAAAAAstored',
                'platformKey' => 'sk_test_PLATFORMstored',
                'url' => 'https://shop.example/index.php?cl=stripewebhook',
                'existing' => null,
                'description' => 'Stripe module',
            ]],
            $registrar->registered,
        );
        self::assertSame('we_new', $this->state->endpointId('test'));
        self::assertTrue($this->state->hasSecret('test'));
        self::assertSame('https://shop.example/index.php?cl=stripewebhook', $this->state->registeredUrl);
        self::assertSame('we_new', $result->status()->endpointId);
    }

    public function testRegister_FromANonSuperadmin_IsRefusedBeforeTheStepUpAndNothingIsRegistered(): void
    {
        $this->superadmin = new FakeSuperadminCheck(false);
        $registrar = new ScriptedWebhookRegistrar();

        $result = $this->administrator($registrar)->register();

        self::assertSame('superadmin_required', $result->refusal()?->code);
        self::assertSame('webhook', $result->refusal()->field);
        self::assertSame(0, $this->stepUp->required);
        self::assertSame([], $registrar->registered);
        self::assertSame('superadmin_required', $this->audit->rows[0]['outcome']);
    }

    public function testClearAll_FromANonSuperadmin_StaysAsItWas(): void
    {
        $this->superadmin = new FakeSuperadminCheck(false);

        $result = $this->administrator(new ScriptedWebhookRegistrar())->clearAll();

        self::assertNotSame('superadmin_required', $result->refusal()?->code, 'clear-all deletes endpoints, not keys');
        self::assertSame(0, $this->superadmin->asked);
    }

    public function testRegister_TheAnswerNeverCarriesTheSigningSecret(): void
    {
        $result = $this->administrator(new ScriptedWebhookRegistrar())->register();

        self::assertStringNotContainsString('whsec_', (string) json_encode(get_object_vars($result->status())));
        self::assertStringNotContainsString('brandnewsecret', (string) json_encode($this->audit->rows));
    }

    public function testRegister_AnExistingEndpoint_IsUpdatedAndItsSecretIsKept(): void
    {
        $this->state = new InMemoryWebhookState(['test' => 'we_old'], ['test' => 'whsec_keepme']);
        $registrar = new ScriptedWebhookRegistrar(new WebhookEndpointRegistrationResult('we_old', null));

        $this->administrator($registrar)->register();

        self::assertSame('we_old', $registrar->registered[0]['existing']);
        self::assertSame('whsec_keepme', $this->state->secrets['test']);
    }

    public function testRegister_NeedsAStepUpBeforeAnythingHappens(): void
    {
        $this->stepUp = new CountingStepUp(proven: false);
        $registrar = new ScriptedWebhookRegistrar();

        try {
            $this->administrator($registrar)->register();
            self::fail('the step-up must stop the registration');
        } catch (RuntimeException $stepUpRequired) {
            self::assertSame('step_up_required', $stepUpRequired->getMessage());
        }

        self::assertSame([], $registrar->registered);
        self::assertSame([['operation' => 'adminStripeWebhookRegister', 'fields' => ['webhookEndpoint'], 'outcome' => RuntimeException::class]], $this->audit->rows);
    }

    public function testRegister_WithoutASecretKeyForTheMode_IsRefused(): void
    {
        $this->settings = new InMemoryStripeSettings(ConfigFixtures::snapshot(), ['test' => '', 'live' => '']);
        $registrar = new ScriptedWebhookRegistrar();

        $result = $this->administrator($registrar)->register();

        self::assertSame('token_missing', $result->refusal()?->code);
        self::assertSame('token', $result->refusal()->field);
        self::assertSame([], $registrar->registered);
    }

    public function testRegister_WhenStripeRefuses_IsARefusalWithTheMessageWithoutKeys(): void
    {
        $failure = new WebhookRegistrationException('Invalid API Key provided: sk_test_****abcd (whsec_abcdef123456)');
        $registrar = new ScriptedWebhookRegistrar(null, $failure);

        $result = $this->administrator($registrar)->register();

        self::assertSame('registration_failed', $result->refusal()?->code);
        self::assertSame('webhook', $result->refusal()->field);
        self::assertStringNotContainsString('sk_test_', $result->refusal()->message);
        self::assertStringNotContainsString('whsec_', $result->refusal()->message);
        self::assertStringContainsString('Invalid API Key provided', $result->refusal()->message);
        self::assertSame('', $this->state->endpointId('test'), 'nothing is stored after a failure');
    }

    public function testClearAll_DeletesTheEndpointsOfThisShopAndForgetsTheStoredState(): void
    {
        $this->state = new InMemoryWebhookState(['test' => 'we_123'], ['test' => 'whsec_stored']);
        $registrar = new ScriptedWebhookRegistrar(null, null, 3);

        $result = $this->administrator($registrar)->clearAll();

        self::assertNull($result->refusal());
        self::assertSame(3, $result->deleted());
        self::assertSame([['sk_test_PLATFORMstored', 'https://shop.example/index.php?cl=stripewebhook']], $registrar->cleared);
        self::assertSame('', $this->state->endpointId('test'));
        self::assertFalse($result->status()->configured);
        self::assertSame(1, $this->stepUp->required);
    }

    public function testClearAll_NeedsAStepUpBeforeAnythingHappens(): void
    {
        $this->stepUp = new CountingStepUp(proven: false);
        $registrar = new ScriptedWebhookRegistrar();

        $this->expectException(RuntimeException::class);
        try {
            $this->administrator($registrar)->clearAll();
        } finally {
            self::assertSame([], $registrar->cleared);
        }
    }

    public function testClearAll_WithoutAPlatformKey_IsRefusedAndNothingIsDeleted(): void
    {
        $this->settings = new InMemoryStripeSettings(ConfigFixtures::snapshot(), ['test' => 'sk_test_AAAAAAAAAAstored'], ['test' => '']);
        $registrar = new ScriptedWebhookRegistrar();

        $result = $this->administrator($registrar)->clearAll();

        self::assertSame('platform_key_missing', $result->refusal()?->code);
        self::assertSame([], $registrar->cleared);
    }

    public function testClearAll_WhenStripeFails_IsARefusalAndTheStoredStateStays(): void
    {
        $this->state = new InMemoryWebhookState(['test' => 'we_123'], ['test' => 'whsec_stored']);
        $registrar = new ScriptedWebhookRegistrar(null, new WebhookRegistrationException('rate limited'));

        $result = $this->administrator($registrar)->clearAll();

        self::assertSame('clear_failed', $result->refusal()?->code);
        self::assertSame('we_123', $this->state->endpointId('test'));
    }

    public function testEveryRegisterAndClearIsAuditedOnceByTheNameOfTheThingNeverByValue(): void
    {
        $registrar = new ScriptedWebhookRegistrar();
        $this->administrator($registrar)->register();
        $this->administrator($registrar)->clearAll();

        self::assertSame(
            [
                ['operation' => 'adminStripeWebhookRegister', 'fields' => ['webhookEndpoint'], 'outcome' => 'ok'],
                ['operation' => 'adminStripeWebhookClearAll', 'fields' => ['webhookEndpoint'], 'outcome' => 'ok'],
            ],
            $this->audit->rows,
        );
    }

    private function administrator(?ScriptedWebhookRegistrar $registrar = null): StripeWebhookAdministrator
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('getModuleDescription')->willReturn('Stripe module');

        return new StripeWebhookAdministrator(
            $this->settings,
            $this->state,
            $registrar ?? new ScriptedWebhookRegistrar(),
            $config,
            $this->superadmin,
            $this->stepUp,
            $this->audit,
            new NullLogger(),
        );
    }
}
