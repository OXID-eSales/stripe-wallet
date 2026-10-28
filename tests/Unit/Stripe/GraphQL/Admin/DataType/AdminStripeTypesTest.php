<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\DataType;

use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfig;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfigInput;
use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfigPayload;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminRefusal;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigResult;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ConfigFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TheCodingMachine\GraphQLite\Annotations\Field;

final class AdminStripeTypesTest extends TestCase
{
    public function testConfig_ExposesExactlyTheDocumentedFields(): void
    {
        $fields = $this->fieldNamesOf(AdminStripeConfig::class);

        self::assertEqualsCanonicalizing([
            'mode', 'captureMode', 'removeByBillingCountry', 'removeByBasketCurrency', 'provideCustomerEmail', 'secretsEditable',
            'logLevel', 'logWebhooks', 'testPublishableKey', 'livePublishableKey', 'webhookEndpointUrl',
            'testTokenSet', 'liveTokenSet', 'testPlatformKeySet', 'livePlatformKeySet', 'webhookSecretSet',
            'testConnected', 'liveConnected', 'keyValidationError', 'version', 'classicSettingsUrl',
        ], $fields);
        foreach ($fields as $field) {
            self::assertDoesNotMatchRegularExpression('/(token|secret|platformkey)$/i', $field, 'no field may carry a secret value');
        }
    }

    public function testConfig_ReadsFromTheSnapshot(): void
    {
        $snapshot = ConfigFixtures::snapshot(['liveTokenSet' => true, 'keyValidationError' => 'nope']);
        $config = new AdminStripeConfig($snapshot, true);

        self::assertSame('test', $config->getMode());
        self::assertSame('automatic', $config->getCaptureMode());
        self::assertTrue($config->isRemoveByBillingCountry());
        self::assertFalse($config->isProvideCustomerEmail());
        self::assertSame('normal', $config->getLogLevel());
        self::assertTrue($config->isLogWebhooks());
        self::assertSame('pk_test_AAAAAAAAAAbbbb', $config->getTestPublishableKey());
        self::assertSame('', $config->getLivePublishableKey());
        self::assertSame('https://shop.example/index.php?cl=stripewebhook', $config->getWebhookEndpointUrl());
        self::assertTrue($config->isTestTokenSet());
        self::assertTrue($config->isLiveTokenSet());
        self::assertFalse($config->isTestPlatformKeySet());
        self::assertTrue($config->isWebhookSecretSet());
        self::assertTrue($config->isTestConnected());
        self::assertFalse($config->isLiveConnected());
        self::assertSame('nope', $config->getKeyValidationError());
        self::assertSame('https://shop.example/admin/index.php?cl=module_config&oxid=oe_payments_stripe_wallet&shp=1', $config->getClassicSettingsUrl());
        self::assertSame($snapshot->version(), $config->getVersion());
    }

    public function testInput_TakesSecretsButTheConfigTypeNeverReturnsThem(): void
    {
        $input = new ReflectionClass(AdminStripeConfigInput::class);

        foreach (['expectedVersion', 'testToken', 'liveToken', 'testPlatformKey', 'livePlatformKey', 'webhookSecret'] as $property) {
            self::assertTrue($input->hasProperty($property), $property);
        }
        self::assertFalse($input->hasProperty('webhookEndpointUrl'), 'the endpoint is registered, not edited');
    }

    public function testInput_BecomesAChangeWithoutLosingOrInventingAnything(): void
    {
        $input = new AdminStripeConfigInput();
        $input->expectedVersion = 'v1';
        $input->mode = 'live';
        $input->liveToken = 'sk_live_AAAAAAAAAAvalue';
        $input->logWebhooks = false;

        $change = $input->toChange();

        self::assertSame('v1', $change->expectedVersion);
        self::assertSame('live', $change->mode);
        self::assertSame('sk_live_AAAAAAAAAAvalue', $change->tokenFor('live'));
        self::assertFalse($change->logWebhooks);
        self::assertNull($change->captureMode);
        self::assertSame(['mode', 'logWebhooks', 'liveToken'], $change->fieldNames());
    }

    public function testPayload_CarriesTheConfigOrTheErrorNeverBoth(): void
    {
        $done = AdminStripeConfigPayload::of(StripeConfigResult::done(ConfigFixtures::snapshot()), true);
        $refused = AdminStripeConfigPayload::of(StripeConfigResult::refused(StripeAdminRefusal::invalid('mode', 'not_allowed', 'no')), false);

        self::assertNotNull($done->config());
        self::assertSame([], $done->errors());
        self::assertNull($refused->config());
        self::assertCount(1, $refused->errors());
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private function fieldNamesOf(string $class): array
    {
        $names = [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Field::class) !== []) {
                $names[] = lcfirst((string) preg_replace('/^(get|is|has)(?=[A-Z])/', '', $method->getName()));
            }
        }

        return $names;
    }
}
