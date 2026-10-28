<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\DataType;

use OxidEsales\Payments\Stripe\GraphQL\Admin\DataType\AdminStripeConfig;
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
            'mode', 'captureMode', 'removeByBillingCountry', 'removeByBasketCurrency', 'provideCustomerEmail',
            'logLevel', 'logWebhooks', 'testPublishableKey', 'livePublishableKey', 'webhookEndpointUrl',
            'testTokenSet', 'liveTokenSet', 'testPlatformKeySet', 'livePlatformKeySet', 'webhookSecretSet',
            'testConnected', 'liveConnected', 'keyValidationError', 'version',
        ], $fields);
        foreach ($fields as $field) {
            self::assertDoesNotMatchRegularExpression('/(token|secret|platformkey)$/i', $field, 'no field may carry a secret value');
        }
    }

    public function testConfig_ReadsFromTheSnapshot(): void
    {
        $snapshot = ConfigFixtures::snapshot(['liveTokenSet' => true, 'keyValidationError' => 'nope']);
        $config = new AdminStripeConfig($snapshot);

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
        self::assertSame($snapshot->version(), $config->getVersion());
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
