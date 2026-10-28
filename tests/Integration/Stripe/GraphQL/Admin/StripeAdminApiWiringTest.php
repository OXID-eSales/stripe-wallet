<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\GraphQL\Admin;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Facade\ModuleSettingServiceInterface;
use OxidEsales\EshopCommunity\Tests\Integration\IntegrationTestCase;
use OxidEsales\GraphQL\Admin\Authorization\Service\RightsCatalogue;
use Doctrine\DBAL\Connection;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\ModuleConfVarsInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OrderPaymentIntentIdReaderInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripePaymentDetailsReaderInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OxidStripeAdminAudit;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminAuditInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminSettingsInterface;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeWebhookAdministratorInterface;
use OxidEsales\Payments\Stripe\GraphQL\Service\NamespaceMapper;
use OxidEsales\Payments\Stripe\Module;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Stripe admin API in the real shop: wired, in the schema, and no secret value anywhere in what it returns.
 */
#[Group('integration')]
final class StripeAdminApiWiringTest extends IntegrationTestCase
{
    private const CONFIG_FIELDS = 'mode captureMode removeByBillingCountry removeByBasketCurrency provideCustomerEmail '
        . 'logLevel logWebhooks testPublishableKey livePublishableKey webhookEndpointUrl testTokenSet liveTokenSet '
        . 'testPlatformKeySet livePlatformKeySet webhookSecretSet testConnected liveConnected keyValidationError version';
    private const WEBHOOK_SECRET_VARS = ['sStripeWebhookEndpointSecretTest', 'sStripeWebhookEndpointSecretLive'];
    private const SECRET_SETTINGS = [
        'sStripeTestToken', 'sStripeLiveToken', 'sStripeTestKey', 'sStripeLiveKey', 'sStripeWebhookEndpointSecret',
    ];

    public function setUp(): void
    {
        parent::setUp();
        if (!class_exists(RightsCatalogue::class)) {
            self::markTestSkipped('oxid-admin-graphql is not installed');
        }
        // Required by path, not by autoload: CI's shop bootstrap does not register the Tests\ namespace, and the
        // probe refers to GraphQL classes that exist only where the guard above passed.
        require_once __DIR__ . '/../../../Support/AdminSchemaProbe.php';
    }

    public function testNamespaceMapper_InTheRealShop_MapsTheStripeAdminApi(): void
    {
        $mapper = new NamespaceMapper();

        self::assertArrayHasKey('OxidEsales\\Payments\\Stripe\\GraphQL\\Admin\\Controller', $mapper->getControllerNamespaceMapping());
        self::assertArrayHasKey('OxidEsales\\Payments\\Stripe\\GraphQL\\Admin\\Relation', $mapper->getTypeNamespaceMapping());
    }

    public function testTheConfigRightIsInTheCatalogue(): void
    {
        self::assertTrue($this->service(RightsCatalogue::class)->has('ADMIN_PAYMENT_CONFIG'));
    }

    public function testSchema_HasTheConfigQueryAndAConfigTypeWithoutAnySecretField(): void
    {
        $schema = $this->schema();

        self::assertMatchesRegularExpression('/adminStripeConfig: AdminStripeConfig!/', $schema);
        preg_match('/type AdminStripeConfig \{([^}]*)\}/s', $schema, $type);
        self::assertNotEmpty($type, 'type AdminStripeConfig');
        preg_match_all('/^\s*(\w+)[(:]/m', $type[1], $fields);
        self::assertEqualsCanonicalizing(explode(' ', self::CONFIG_FIELDS), $fields[1]);
        foreach (['testTokenSet', 'liveTokenSet', 'testPlatformKeySet', 'livePlatformKeySet', 'webhookSecretSet'] as $flag) {
            self::assertMatchesRegularExpression('/' . $flag . ': Boolean!/', $type[1]);
        }
        foreach ($fields[1] as $field) {
            self::assertDoesNotMatchRegularExpression('/(token|secret|platformkey)$/i', $field, 'field ' . $field);
        }
    }

    public function testContainer_WiresTheAuditToTheRealRecorder(): void
    {
        self::assertInstanceOf(OxidStripeAdminAudit::class, $this->service(StripeAdminAuditInterface::class));
    }

    public function testSchema_HasTheModifyMutationWithAWriteOnlySecretInput(): void
    {
        $schema = $this->schema();

        self::assertMatchesRegularExpression('/adminStripeConfigModify\(input: AdminStripeConfigInput!\): AdminStripeConfigPayload!/', $schema);
        self::assertMatchesRegularExpression('/input AdminStripeConfigInput \{[^}]*expectedVersion: String!/s', $schema);
        foreach (['testToken', 'liveToken', 'testPlatformKey', 'livePlatformKey', 'webhookSecret'] as $secret) {
            self::assertMatchesRegularExpression('/input AdminStripeConfigInput \{[^}]*' . $secret . ': String[^!]/s', $schema);
        }
        preg_match('/type AdminStripeConfigPayload \{([^}]*)\}/s', $schema, $payload);
        self::assertMatchesRegularExpression('/config: AdminStripeConfig\b/', $payload[1] ?? '');
        self::assertMatchesRegularExpression('/errors: \[AdminError!\]!/', $payload[1] ?? '');
    }

    public function testMutation_AStaleVersion_IsAConflictAndNothingIsWritten(): void
    {
        $response = $this->probe(
            'mutation { adminStripeConfigModify(input: { expectedVersion: "stale", captureMode: "manual" }) '
            . '{ config { version } errors { errorCode message } } }'
        );

        self::assertArrayNotHasKey('errors', $response);
        self::assertSame('conflict', $response['data']['adminStripeConfigModify']['errors'][0]['errorCode'] ?? null);
        self::assertNull($response['data']['adminStripeConfigModify']['config']);
    }

    public function testSchema_HasTheThreeWebhookOperationsAndNoFieldThatCouldCarryTheSigningSecret(): void
    {
        $schema = $this->schema();

        self::assertMatchesRegularExpression('/adminStripeWebhookStatus: AdminStripeWebhookStatus!/', $schema);
        self::assertMatchesRegularExpression('/adminStripeWebhookRegister: AdminStripeWebhookPayload!/', $schema);
        self::assertMatchesRegularExpression('/adminStripeWebhookClearAll: AdminStripeWebhookPayload!/', $schema);
        preg_match('/type AdminStripeWebhookStatus \{([^}]*)\}/s', $schema, $status);
        preg_match('/type AdminStripeWebhookPayload \{([^}]*)\}/s', $schema, $payload);
        preg_match_all('/^\s*(\w+)[(:]/m', ($status[1] ?? '') . ($payload[1] ?? ''), $fields);
        self::assertEqualsCanonicalizing(['mode', 'configured', 'endpointId', 'url', 'status', 'deleted', 'errors'], $fields[1]);
    }

    public function testContainer_WiresTheWebhookAdministrator(): void
    {
        $status = $this->service(StripeWebhookAdministratorInterface::class)->status();

        self::assertContains($status->mode, ['test', 'live']);
        self::assertNotSame('', $status->url);
    }

    public function testQuery_AdminStripeWebhookStatus_NeverReturnsAStoredSigningSecret(): void
    {
        $secrets = $this->storedWebhookSecrets();
        if ($secrets === []) {
            self::markTestSkipped('this shop has no webhook signing secret stored; the grep needs one to look for');
        }

        $response = $this->probe('{ adminStripeWebhookStatus { mode configured endpointId url } }');

        self::assertArrayNotHasKey('errors', $response);
        $body = (string) json_encode($response);
        foreach ($secrets as $secret) {
            self::assertFalse(str_contains($body, $secret), 'a stored signing secret appears in the response');
        }
        self::assertStringNotContainsString('whsec_', $body);
    }

    public function testSchema_TheContractHasTheStripeExtensionWithTheDocumentedFields(): void
    {
        $schema = $this->schema();

        self::assertMatchesRegularExpression('/type AdminPaymentContract \{[^}]*stripe: AdminStripePaymentDetails\b/s', $schema);
        preg_match('/type AdminStripePaymentDetails \{([^}]*)\}/s', $schema, $type);
        preg_match_all('/^\s*(\w+)[(:]/m', $type[1] ?? '', $fields);
        self::assertEqualsCanonicalizing(
            ['paymentIntentId', 'chargeId', 'externalTransactionId', 'intentStatus', 'dashboardUrl', 'apiError'],
            $fields[1]
        );
    }

    public function testReader_AContractThatDoesNotExist_HasNoStripePart(): void
    {
        self::assertNull($this->service(StripePaymentDetailsReaderInterface::class)->find('no-such-contract'));
    }

    public function testOrderReader_ReadsTheIntentIdTheOrderKeeps(): void
    {
        $orderId = 'pay3-test-order-' . bin2hex(random_bytes(4));
        $connection = $this->service(Connection::class);
        $connection->executeStatement(
            'INSERT INTO oxorder (OXID, OXSHOPID, OXTRANSID) VALUES (?, 1, ?)',
            [$orderId, 'pi_integration_123']
        );
        try {
            $reader = $this->service(OrderPaymentIntentIdReaderInterface::class);

            self::assertSame('pi_integration_123', $reader->intentIdOf($orderId));
            self::assertSame('', $reader->intentIdOf('no-such-order'));
        } finally {
            $connection->executeStatement('DELETE FROM oxorder WHERE OXID = ?', [$orderId]);
        }
    }

    public function testPaymentBase_ShowsStripesColumnOfTheStateHelp(): void
    {
        $response = $this->probe('{ adminPaymentContractStateHelp(provider: "stripe") { providerLabel rows { state providerStatus } } }');

        self::assertArrayNotHasKey('errors', $response);
        $help = $response['data']['adminPaymentContractStateHelp'];
        self::assertSame('Stripe PaymentIntent status', $help['providerLabel']);
        self::assertContains('requires_capture', array_column($help['rows'], 'providerStatus'));
    }

    public function testQuery_AdminStripeConfig_ReturnsEveryFieldAndNeverTheStoredSecrets(): void
    {
        $storedSecrets = $this->storedSecrets();
        if ($storedSecrets === []) {
            self::markTestSkipped('this shop has no Stripe secret stored; the grep needs one to look for');
        }

        $response = $this->probe('{ adminStripeConfig { ' . self::CONFIG_FIELDS . ' } }');

        self::assertArrayNotHasKey('errors', $response);
        self::assertArrayHasKey('webhookSecretSet', $response['data']['adminStripeConfig']);
        $body = (string) json_encode($response);
        foreach ($storedSecrets as $secret) {
            self::assertFalse(str_contains($body, $secret), 'a stored secret appears in the response');
        }
    }

    public function testQuery_TheSchemaOffersNoWayToAskForASecretByName(): void
    {
        $response = $this->probe('{ adminStripeConfig { testToken liveToken testPlatformKey webhookSecret } }');

        self::assertArrayHasKey('errors', $response, 'there is no field to select a secret with');
        self::assertArrayNotHasKey('data', $response);
    }

    public function testSettings_ResolveFromTheContainerAndReportTheStoredSecretsOnlyAsFlags(): void
    {
        $snapshot = $this->service(StripeAdminSettingsInterface::class)->snapshot();

        self::assertContains($snapshot->mode, ['test', 'live']);
        $everything = (string) json_encode(get_object_vars($snapshot));
        foreach ($this->storedSecrets() as $secret) {
            self::assertStringNotContainsString($secret, $everything);
        }
    }

    /**
     * @return list<string>
     */
    private function storedSecrets(): array
    {
        $settings = $this->service(ModuleSettingServiceInterface::class);
        $secrets = [];
        foreach (self::SECRET_SETTINGS as $name) {
            $value = $settings->getString($name, Module::MODULE_ID)->toString();
            if (strlen($value) >= 8) {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }

    /**
     * @return list<string>
     */
    private function storedWebhookSecrets(): array
    {
        $secrets = [];
        foreach (self::WEBHOOK_SECRET_VARS as $name) {
            $value = $this->service(ModuleConfVarsInterface::class)->read($name);
            if (strlen($value) >= 8) {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }

    private function schema(): string
    {
        return \OxidEsales\Payments\Stripe\Tests\Integration\Support\AdminSchemaProbe::sdl();
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(string $query): array
    {
        return \OxidEsales\Payments\Stripe\Tests\Integration\Support\AdminSchemaProbe::execute($query);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(string $id): object
    {
        return ContainerFactory::getInstance()->getContainer()->get($id);
    }
}
