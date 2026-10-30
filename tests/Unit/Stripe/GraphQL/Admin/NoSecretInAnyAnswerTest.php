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
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OxidStripeAdminSettings;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OxidStripeWebhookState;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookAdministrator;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidator;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryConfVars;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryModuleSettings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Field;

/**
 * The no-secret rule, end to end through the real controllers, services and settings (only the storage is in
 * memory): whatever a client asks, every value of every answer is searched for every secret the shop stores and for
 * anything that looks like a Stripe secret.
 */
final class NoSecretInAnyAnswerTest extends TestCase
{
    private const STORED_TOKEN = 'sk_test_fakeAAAAAAAAAAstoredtoken';
    private const STORED_PLATFORM_KEY = 'sk_test_fakePLATFORMstoredplatform';
    private const STORED_WEBHOOK_SECRET = 'whsec_storedsigningsecret';
    private const SECRET_LOOKING = '/(?:[sr]k_(?:test|live)_|whsec_)/';

    private InMemoryModuleSettings $module;
    private InMemoryConfVars $vars;

    protected function setUp(): void
    {
        $this->module = new InMemoryModuleSettings([
            'sStripeMode' => 'test', 'sStripeTestPk' => 'pk_test_fakeAAAAAAAAAAbbbb',
            'sStripeTestToken' => self::STORED_TOKEN, 'sStripeTestKey' => self::STORED_PLATFORM_KEY,
        ]);
        $this->vars = new InMemoryConfVars(['sStripeWebhookEndpointSecretTest' => self::STORED_WEBHOOK_SECRET]);
    }

    public function testNoAnswerOfAnyOperationCarriesASecret(): void
    {
        $answers = [
            $this->configController()->adminStripeConfig(),
            $this->webhookController()->adminStripeWebhookStatus(),
        ];

        $everything = (string) json_encode(array_map($this->exposed(...), $answers));

        foreach (['isWebhookSecretSet', 'isTestTokenSet'] as $proofItWasLookedAt) {
            self::assertStringContainsString($proofItWasLookedAt, $everything);
        }
        foreach ([self::STORED_TOKEN, self::STORED_PLATFORM_KEY, self::STORED_WEBHOOK_SECRET] as $secret) {
            self::assertStringNotContainsString($secret, $everything);
        }
        self::assertDoesNotMatchRegularExpression(self::SECRET_LOOKING, $everything);
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
        return new StripeAdminConfigController($this->settings());
    }

    private function webhookController(): StripeAdminWebhookController
    {
        return new StripeAdminWebhookController(new StripeWebhookAdministrator($this->settings(), $this->state()));
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
