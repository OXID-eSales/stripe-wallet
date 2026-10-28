<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin;

use OxidEsales\GraphQL\Admin\Shared\DataType\Error\AdminError;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Controller\StripeAdminConfigController;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Controller\StripeAdminWebhookController;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfigInput;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OxidStripeAdminSettings;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OxidStripeWebhookState;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChangeValidator;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigModifier;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookAdministrator;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidator;
use OxidEsales\Payments\Stripe\Service\Exception\WebhookRegistrationException;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\CountingStepUp;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryConfVars;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryModuleSettings;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\RecordingStripeAudit;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ScriptedWebhookRegistrar;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Field;

/**
 * The write-only rule, end to end through the real controllers, services and settings (only the storage and Stripe
 * are in memory): whatever a client asks, every value of every answer is searched for every secret the shop stores
 * or was just given, and for anything that looks like a Stripe secret.
 */
final class NoSecretInAnyAnswerTest extends TestCase
{
    private const STORED_TOKEN = 'sk_test_AAAAAAAAAAstoredtoken';
    private const STORED_PLATFORM_KEY = 'sk_test_PLATFORMstoredplatform';
    private const STORED_WEBHOOK_SECRET = 'whsec_storedsigningsecret';
    private const NEW_TOKEN = 'sk_test_AAAAAAAAAAbrandnewtoken';
    private const NEW_WEBHOOK_SECRET = 'whsec_brandnewsigningsecret';
    private const CREATED_WEBHOOK_SECRET = 'whsec_createdbystripe12';
    private const SECRET_LOOKING = '/(?:[sr]k_(?:test|live)_|whsec_)/';

    private InMemoryModuleSettings $module;
    private InMemoryConfVars $vars;

    protected function setUp(): void
    {
        $this->module = new InMemoryModuleSettings([
            'sStripeMode' => 'test', 'sStripeTestPk' => 'pk_test_AAAAAAAAAAbbbb',
            'sStripeTestToken' => self::STORED_TOKEN, 'sStripeTestKey' => self::STORED_PLATFORM_KEY,
        ]);
        $this->vars = new InMemoryConfVars(['sStripeWebhookEndpointSecretTest' => self::STORED_WEBHOOK_SECRET]);
    }

    public function testNoAnswerOfAnyOperationCarriesASecret(): void
    {
        $config = $this->configController();
        $answers = [$config->adminStripeConfig()];
        $answers[] = $config->adminStripeConfigModify($this->input($answers[0]->getVersion(), testToken: self::NEW_TOKEN, webhookSecret: self::NEW_WEBHOOK_SECRET));
        $answers[] = $config->adminStripeConfigModify($this->input('stale', testToken: self::NEW_TOKEN));
        $answers[] = $config->adminStripeConfigModify($this->input($answers[0]->getVersion(), testToken: 'sk_test_BBBBBBBBBBmismatch'));
        $answers[] = $config->adminStripeConfigModify($this->input($answers[0]->getVersion(), testToken: 'sk-leaky-not-a-key-format'));
        $answers[] = $config->adminStripeConfig();
        $webhooks = $this->webhookController(new ScriptedWebhookRegistrar());
        $answers[] = $webhooks->adminStripeWebhookStatus();
        $answers[] = $webhooks->adminStripeWebhookRegister();
        $answers[] = $webhooks->adminStripeWebhookClearAll();
        $failing = new ScriptedWebhookRegistrar(null, new WebhookRegistrationException('Invalid API Key provided: sk_test_****abcd; whsec_masked'));
        $answers[] = $this->webhookController($failing)->adminStripeWebhookRegister();

        $everything = (string) json_encode(array_map($this->exposed(...), $answers));

        foreach (['registration_failed', 'key_mismatch', 'conflict', 'invalid_format', 'isWebhookSecretSet', 'Invalid API Key provided'] as $proofItWasLookedAt) {
            self::assertStringContainsString($proofItWasLookedAt, $everything);
        }
        foreach ([self::STORED_TOKEN, self::STORED_PLATFORM_KEY, self::STORED_WEBHOOK_SECRET, self::NEW_TOKEN, self::NEW_WEBHOOK_SECRET, self::CREATED_WEBHOOK_SECRET, 'sk-leaky'] as $secret) {
            self::assertStringNotContainsString($secret, $everything);
        }
        self::assertDoesNotMatchRegularExpression(self::SECRET_LOOKING, $everything);
    }

    private function input(string $version, ?string $testToken = null, ?string $webhookSecret = null): AdminStripeConfigInput
    {
        $input = new AdminStripeConfigInput();
        $input->expectedVersion = $version;
        $input->testToken = $testToken;
        $input->webhookSecret = $webhookSecret;

        return $input;
    }

    private function settings(): OxidStripeAdminSettings
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('getWebhookUrl')->willReturn('https://shop.example/webhook');
        $config->method('getClassicSettingsUrl')->willReturn('https://shop.example/admin/index.php?cl=module_config');

        return new OxidStripeAdminSettings($this->module, $this->state(), new ConfigurationValidator($config), $config);
    }

    private function state(): OxidStripeWebhookState
    {
        return new OxidStripeWebhookState($this->vars, $this->module);
    }

    private function configController(): StripeAdminConfigController
    {
        $modifier = new StripeConfigModifier(
            $this->settings(),
            new StripeConfigChangeValidator(),
            new ConfigurationValidator($this->createStub(ModuleConfigurationServiceInterface::class)),
            new CountingStepUp(),
            new RecordingStripeAudit(),
        );

        return new StripeAdminConfigController($this->settings(), $modifier);
    }

    private function webhookController(ScriptedWebhookRegistrar $registrar): StripeAdminWebhookController
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);
        $config->method('getModuleDescription')->willReturn('Stripe module');

        return new StripeAdminWebhookController(new StripeWebhookAdministrator(
            $this->settings(),
            $this->state(),
            $registrar,
            $config,
            new CountingStepUp(),
            new RecordingStripeAudit(),
            new NullLogger(),
        ));
    }

    /**
     * Every value a client could select: the result of every #[Field] method, recursively, and the parts of an error.
     *
     * @return array<string, mixed>|scalar|null
     */
    private function exposed(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->exposed(...), $value);
        }
        if (!is_object($value)) {
            return $value;
        }
        $result = [];
        foreach ((new \ReflectionObject($value))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $isAnswer = $method->getAttributes(Field::class) !== [] || $value instanceof AdminError;
            if ($isAnswer && $method->getNumberOfRequiredParameters() === 0 && !$method->isConstructor() && !$method->isStatic()) {
                $result[$method->getName()] = $this->exposed($method->invoke($value));
            }
        }

        return $result;
    }
}
