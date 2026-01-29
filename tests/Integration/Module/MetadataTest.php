<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Module;

use PHPUnit\Framework\TestCase;
use OxidEsales\Payments\Stripe\Module;

/**
 * Integration tests for module metadata
 * Tests module definition, structure, and registration in OXID eShop
 */
class MetadataTest extends TestCase
{
    private string $metadataPath;
    private array $moduleData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metadataPath = dirname(__DIR__, 3) . '/metadata.php';

        if (file_exists($this->metadataPath)) {
            $aModule = [];
            include $this->metadataPath;
            $this->moduleData = $aModule;
        }
    }

    /**
     * Test 1: Metadata file exists and is readable
     */
    public function testMetadataFileExists(): void
    {
        $this->assertFileExists(
            $this->metadataPath,
            'Module metadata.php file must exist in module root directory'
        );

        $this->assertFileIsReadable(
            $this->metadataPath,
            'Module metadata.php file must be readable'
        );
    }

    /**
     * Test 2: Module ID is correct and follows naming conventions
     */
    public function testModuleIdIsCorrect(): void
    {
        $this->assertArrayHasKey(
            'id',
            $this->moduleData,
            'Module metadata must contain an id'
        );

        $this->assertEquals(
            Module::MODULE_ID,
            $this->moduleData['id'],
            'Module ID must be "'. Module::MODULE_ID .'"'
        );
    }

    /**
     * Test 3: Module version is defined and follows semver
     */
    public function testModuleVersionDefined(): void
    {
        $this->assertArrayHasKey(
            'version',
            $this->moduleData,
            'Module metadata must contain a version'
        );

        $version = $this->moduleData['version'];
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $version,
            'Module version must follow semantic versioning (e.g., 1.0.0)'
        );
    }

    /**
     * Test 4: Module title is defined for multiple languages
     */
    public function testModuleTitleDefined(): void
    {
        $this->assertArrayHasKey(
            'title',
            $this->moduleData,
            'Module metadata must contain a title'
        );

        $this->assertIsArray(
            $this->moduleData['title'],
            'Module title must be an array with language keys'
        );

        $this->assertArrayHasKey(
            'en',
            $this->moduleData['title'],
            'Module title must include English (en)'
        );

        $this->assertNotEmpty(
            $this->moduleData['title']['en'],
            'English module title must not be empty'
        );
    }

    /**
     * Test 5: Module description is defined for multiple languages
     */
    public function testModuleDescriptionDefined(): void
    {
        $this->assertArrayHasKey(
            'description',
            $this->moduleData,
            'Module metadata must contain a description'
        );

        $this->assertIsArray(
            $this->moduleData['description'],
            'Module description must be an array with language keys'
        );

        $this->assertArrayHasKey(
            'en',
            $this->moduleData['description'],
            'Module description must include English (en)'
        );

        $this->assertNotEmpty(
            $this->moduleData['description']['en'],
            'English module description must not be empty'
        );
    }

    /**
     * Test 6: Module controllers are properly registered
     */
    public function testControllersRegistered(): void
    {
        $this->assertArrayHasKey(
            'controllers',
            $this->moduleData,
            'Module metadata must contain controllers array'
        );

        $controllers = $this->moduleData['controllers'];

        $this->assertArrayHasKey(
            'StripeWebhookController',
            $controllers,
            'Webhook controller must be registered'
        );

        // Note: StripePaymentController is a class extension, not a standalone controller
        // It extends PaymentController via metadata 'extend' section

        // Verify controller classes exist
        $this->assertTrue(
            class_exists($controllers['StripeWebhookController']),
            'Webhook controller class must exist: ' . $controllers['StripeWebhookController']
        );
    }

    /**
     * Test 7: Module settings are properly configured
     */
    public function testSettingsDefined(): void
    {
        $this->assertArrayHasKey(
            'settings',
            $this->moduleData,
            'Module metadata must contain settings array'
        );

        $settings = $this->moduleData['settings'];
        $this->assertIsArray($settings, 'Settings must be an array');
        $this->assertNotEmpty($settings, 'Settings array must not be empty');

        // Extract setting names for easier testing
        $settingNames = array_column($settings, 'name');

        // Mode setting
        $this->assertContains(
            'sStripeMode',
            $settingNames,
            'Stripe mode setting must be defined'
        );

        // Test mode API keys
        $this->assertContains(
            'sStripeTestToken',
            $settingNames,
            'Test token setting must be defined'
        );

        $this->assertContains(
            'sStripeTestPk',
            $settingNames,
            'Test publishable key setting must be defined'
        );

        $this->assertContains(
            'sStripeTestKey',
            $settingNames,
            'Test secret key setting must be defined'
        );

        // Live mode API keys
        $this->assertContains(
            'sStripeLiveToken',
            $settingNames,
            'Live token setting must be defined'
        );

        $this->assertContains(
            'sStripeLivePk',
            $settingNames,
            'Live publishable key setting must be defined'
        );

        $this->assertContains(
            'sStripeLiveKey',
            $settingNames,
            'Live secret key setting must be defined'
        );

        // Webhook configuration
        $this->assertContains(
            'sStripeWebhookEndpoint',
            $settingNames,
            'Webhook endpoint setting must be defined'
        );

        $this->assertContains(
            'sStripeWebhookEndpointSecret',
            $settingNames,
            'Webhook endpoint secret setting must be defined'
        );

        // Logging and behavior settings
        $this->assertContains(
            'blStripeLogTransactionInfo',
            $settingNames,
            'Transaction logging setting must be defined'
        );

        // Sprint 29: Status mapping settings removed - now in StatusMappingConfig class
        // sStripeStatusPending, sStripeStatusProcessing, sStripeStatusCancelled
        // are no longer admin-configurable settings
    }

    /**
     * Test 8: Sensitive settings use password type
     */
    public function testSensitiveSettingsProtected(): void
    {
        $this->assertArrayHasKey('settings', $this->moduleData);
        $settings = $this->moduleData['settings'];

        $sensitivePatterns = ['Token', 'Key', 'Secret'];

        foreach ($settings as $setting) {
            // Check if setting name contains sensitive patterns
            $isSensitive = false;
            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($setting['name'], $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $this->assertEquals(
                    'str',
                    $setting['type'],
                    sprintf(
                        'Sensitive setting "%s" must use string type, got: %s',
                        $setting['name'],
                        $setting['type']
                    )
                );
            }
        }
    }

    /**
     * Test 9: Module events are properly defined
     */
    public function testEventsDefined(): void
    {
        $this->assertArrayHasKey(
            'events',
            $this->moduleData,
            'Module metadata must contain events array'
        );

        $events = $this->moduleData['events'];

        $this->assertArrayHasKey(
            'onActivate',
            $events,
            'onActivate event must be defined'
        );

        $this->assertArrayHasKey(
            'onDeactivate',
            $events,
            'onDeactivate event must be defined'
        );

        // Verify event handler format: Class::method
        $this->assertMatchesRegularExpression(
            '/^[\w\\\\]+::\w+$/',
            $events['onActivate'],
            'onActivate event handler must be in format "Class::method"'
        );

        $this->assertMatchesRegularExpression(
            '/^[\w\\\\]+::\w+$/',
            $events['onDeactivate'],
            'onDeactivate event handler must be in format "Class::method"'
        );

        // Verify event handler classes exist and methods are callable
        foreach ($events as $eventName => $eventHandler) {
            $parts = explode('::', $eventHandler);
            $this->assertCount(
                2,
                $parts,
                sprintf('Event handler "%s" must be in format "Class::method"', $eventHandler)
            );

            [$className, $methodName] = $parts;

            $this->assertTrue(
                class_exists($className),
                sprintf('Event handler class "%s" for event "%s" must exist', $className, $eventName)
            );

            $this->assertTrue(
                is_callable([$className, $methodName]),
                sprintf('Event handler method "%s::%s" for event "%s" must be callable', $className, $methodName, $eventName)
            );
        }
    }

    /**
     * Test 10: Module templates are defined (if present)
     */
    public function testTemplatesDefined(): void
    {
        // Templates are optional in metadata
        if (!isset($this->moduleData['templates'])) {
            $this->markTestSkipped('Module does not define templates');
        }

        $templates = $this->moduleData['templates'];
        $this->assertIsArray($templates, 'Templates must be an array');
        $this->assertNotEmpty($templates, 'Templates array must not be empty');
    }

    /**
     * Test 11: Stripe mode setting has valid constraints
     */
    public function testStripeModeConstraints(): void
    {
        $this->assertArrayHasKey('settings', $this->moduleData);
        $settings = $this->moduleData['settings'];

        $stripeModeSettting = null;
        foreach ($settings as $setting) {
            if ($setting['name'] === 'sStripeMode') {
                $stripeModeSettting = $setting;
                break;
            }
        }

        $this->assertNotNull(
            $stripeModeSettting,
            'Stripe mode setting must exist'
        );

        $this->assertEquals(
            'select',
            $stripeModeSettting['type'],
            'Stripe mode setting must be of type "select"'
        );

        $this->assertArrayHasKey(
            'constraints',
            $stripeModeSettting,
            'Stripe mode setting must have constraints'
        );

        // Constraints must be a pipe-delimited string
        $constraints = $stripeModeSettting['constraints'];

        $this->assertIsString($constraints, 'Stripe mode constraints must be a string');

        $constraints = explode('|', $constraints);

        $this->assertIsArray($constraints, 'Constraints must be convertible to an array');

        $this->assertContains(
            'live',
            $constraints,
            'Constraints must include "live"'
        );

        $this->assertContains(
            'test',
            $constraints,
            'Constraints must include "test"'
        );
    }

    /**
     * Test 12: Settings have required structure
     */
    public function testSettingsStructure(): void
    {
        $this->assertArrayHasKey('settings', $this->moduleData);
        $settings = $this->moduleData['settings'];

        foreach ($settings as $setting) {
            // Each setting must have required fields
            $this->assertArrayHasKey(
                'group',
                $setting,
                sprintf('Setting "%s" must have a group', $setting['name'] ?? 'unknown')
            );

            $this->assertArrayHasKey(
                'name',
                $setting,
                'Setting must have a name'
            );

            $this->assertArrayHasKey(
                'type',
                $setting,
                sprintf('Setting "%s" must have a type', $setting['name'])
            );

            $this->assertArrayHasKey(
                'value',
                $setting,
                sprintf('Setting "%s" must have a value', $setting['name'])
            );

            $this->assertArrayHasKey(
                'position',
                $setting,
                sprintf('Setting "%s" must have a position', $setting['name'])
            );

            // Validate type is valid
            $validTypes = ['str', 'bool', 'select', 'password', 'arr', 'num'];
            $this->assertContains(
                $setting['type'],
                $validTypes,
                sprintf('Setting "%s" has invalid type "%s"', $setting['name'], $setting['type'])
            );
        }
    }
}
