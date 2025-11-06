<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Integration\Module;

use PHPUnit\Framework\TestCase;

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
            'osc_stripe_wallet',
            $this->moduleData['id'],
            'Module ID must be "osc_stripe_wallet"'
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
            'osc_stripe_webhook',
            $controllers,
            'Webhook controller must be registered'
        );

        $this->assertArrayHasKey(
            'osc_stripe_payment',
            $controllers,
            'Payment controller must be registered'
        );

        // Verify controller classes exist
        $this->assertTrue(
            class_exists($controllers['osc_stripe_webhook']),
            'Webhook controller class must exist: ' . $controllers['osc_stripe_webhook']
        );

        $this->assertTrue(
            class_exists($controllers['osc_stripe_payment']),
            'Payment controller class must exist: ' . $controllers['osc_stripe_payment']
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

        // Test mode setting
        $this->assertContains(
            'osc_stripe_test_mode',
            $settingNames,
            'Test mode setting must be defined'
        );

        // API keys
        $this->assertContains(
            'osc_stripe_test_publishable_key',
            $settingNames,
            'Test publishable key setting must be defined'
        );

        $this->assertContains(
            'osc_stripe_test_secret_key',
            $settingNames,
            'Test secret key setting must be defined'
        );

        $this->assertContains(
            'osc_stripe_live_publishable_key',
            $settingNames,
            'Live publishable key setting must be defined'
        );

        $this->assertContains(
            'osc_stripe_live_secret_key',
            $settingNames,
            'Live secret key setting must be defined'
        );

        // Webhook secrets
        $this->assertContains(
            'osc_stripe_test_webhook_secret',
            $settingNames,
            'Test webhook secret setting must be defined'
        );

        $this->assertContains(
            'osc_stripe_live_webhook_secret',
            $settingNames,
            'Live webhook secret setting must be defined'
        );

        // Payment configuration
        $this->assertContains(
            'osc_stripe_payment_methods',
            $settingNames,
            'Payment methods setting must be defined'
        );

        $this->assertContains(
            'osc_stripe_capture_method',
            $settingNames,
            'Capture method setting must be defined'
        );
    }

    /**
     * Test 8: Sensitive settings use password type
     */
    public function testSensitiveSettingsProtected(): void
    {
        $this->assertArrayHasKey('settings', $this->moduleData);
        $settings = $this->moduleData['settings'];

        foreach ($settings as $setting) {
            // Check if setting name contains 'secret' or 'secret_key'
            if (str_contains($setting['name'], 'secret_key') ||
                str_contains($setting['name'], 'webhook_secret')) {

                $this->assertEquals(
                    'password',
                    $setting['type'],
                    sprintf(
                        'Sensitive setting "%s" must use password type, got: %s',
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
     * Test 11: Capture method setting has valid constraints
     */
    public function testCaptureMethodConstraints(): void
    {
        $this->assertArrayHasKey('settings', $this->moduleData);
        $settings = $this->moduleData['settings'];

        $captureMethodSetting = null;
        foreach ($settings as $setting) {
            if ($setting['name'] === 'osc_stripe_capture_method') {
                $captureMethodSetting = $setting;
                break;
            }
        }

        $this->assertNotNull(
            $captureMethodSetting,
            'Capture method setting must exist'
        );

        $this->assertEquals(
            'select',
            $captureMethodSetting['type'],
            'Capture method setting must be of type "select"'
        );

        $this->assertArrayHasKey(
            'constraints',
            $captureMethodSetting,
            'Capture method setting must have constraints'
        );

        // Constraints must be a pipe-delimited string
        $constraints = $captureMethodSetting['constraints'];

        $this->assertIsString($constraints, 'Capture method constraints must be a string');

        $constraints = explode('|', $constraints);

        $this->assertIsArray($constraints, 'Constraints must be convertible to an array');

        $this->assertContains(
            'automatic',
            $constraints,
            'Constraints must include "automatic"'
        );

        $this->assertContains(
            'manual',
            $constraints,
            'Constraints must include "manual"'
        );
    }

    /**
     * Test 12: Payment methods setting has default value
     */
    public function testPaymentMethodsDefaultValue(): void
    {
        $this->assertArrayHasKey('settings', $this->moduleData);
        $settings = $this->moduleData['settings'];

        $paymentMethodsSetting = null;
        foreach ($settings as $setting) {
            if ($setting['name'] === 'osc_stripe_payment_methods') {
                $paymentMethodsSetting = $setting;
                break;
            }
        }

        $this->assertNotNull(
            $paymentMethodsSetting,
            'Payment methods setting must exist'
        );

        $this->assertEquals(
            'arr',
            $paymentMethodsSetting['type'],
            'Payment methods setting must be of type "arr"'
        );

        $this->assertArrayHasKey(
            'value',
            $paymentMethodsSetting,
            'Payment methods setting must have default value'
        );

        $this->assertIsArray(
            $paymentMethodsSetting['value'],
            'Payment methods default value must be an array'
        );

        $this->assertContains(
            'card',
            $paymentMethodsSetting['value'],
            'Default payment methods must include "card"'
        );
    }
}
