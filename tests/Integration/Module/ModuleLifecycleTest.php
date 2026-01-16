<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Module;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Setup\Service\ModuleActivationServiceInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\State\ModuleStateServiceInterface;
use OxidEsales\Payments\Stripe\Module;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Integration tests for module activation/deactivation lifecycle.
 *
 * Tests that the Stripe module can be safely activated and deactivated
 * in OXID 7.4 without crashing the shop.
 *
 * IMPORTANT: These tests require a fully initialized OXID shop environment.
 * They must be run in the Docker container with the shop bootstrapped.
 *
 * @group integration
 * @group module
 * @group lifecycle
 *
 * @covers \OxidEsales\Payments\Stripe\Core\ModuleEvents
 */
class ModuleLifecycleTest extends TestCase
{
    private const MODULE_ID = 'osc_stripe_wallet';
    private const SHOP_ID = 1;

    private ?ContainerInterface $container = null;
    private ?ModuleActivationServiceInterface $activationService = null;
    private ?ModuleStateServiceInterface $stateService = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->container = ContainerFactory::getInstance()->getContainer();
            $this->activationService = $this->container->get(ModuleActivationServiceInterface::class);
            $this->stateService = $this->container->get(ModuleStateServiceInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Cannot initialize OXID container. ' .
                'These tests require a fully initialized OXID shop environment. ' .
                'Error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Test 1: Module can be activated without errors
     */
    public function testModuleCanBeActivated(): void
    {
        // Given: Module is not active
        $this->deactivateModuleIfActive();

        // When: Module is activated
        $exception = null;
        try {
            $this->activationService->activate(self::MODULE_ID, self::SHOP_ID);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        // Then: No exceptions thrown, module is active
        $this->assertNull(
            $exception,
            'Module activation should not throw exceptions. Got: ' .
            ($exception ? $exception->getMessage() : '')
        );

        $this->assertTrue(
            $this->stateService->isActive(self::MODULE_ID, self::SHOP_ID),
            'Module should be active after activation'
        );
    }

    /**
     * Test 2: Module can be deactivated without errors
     *
     * @depends testModuleCanBeActivated
     */
    public function testModuleCanBeDeactivated(): void
    {
        // Given: Module is active
        $this->activateModuleIfNotActive();

        // When: Module is deactivated
        $exception = null;
        try {
            $this->activationService->deactivate(self::MODULE_ID, self::SHOP_ID);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        // Then: No exceptions thrown, module is inactive
        $this->assertNull(
            $exception,
            'Module deactivation should not throw exceptions. Got: ' .
            ($exception ? $exception->getMessage() : '')
        );

        $this->assertFalse(
            $this->stateService->isActive(self::MODULE_ID, self::SHOP_ID),
            'Module should be inactive after deactivation'
        );
    }

    /**
     * Test 3: Module can be reactivated after deactivation
     *
     * @depends testModuleCanBeDeactivated
     */
    public function testModuleCanBeReactivatedAfterDeactivation(): void
    {
        // Given: Module was deactivated
        $this->deactivateModuleIfActive();

        // When: Module is reactivated
        $exception = null;
        try {
            $this->activationService->activate(self::MODULE_ID, self::SHOP_ID);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        // Then: No exceptions thrown, module is active
        $this->assertNull(
            $exception,
            'Module reactivation should not throw exceptions. Got: ' .
            ($exception ? $exception->getMessage() : '')
        );

        $this->assertTrue(
            $this->stateService->isActive(self::MODULE_ID, self::SHOP_ID),
            'Module should be active after reactivation'
        );
    }

    /**
     * Test 4: Module ID matches expected value
     */
    public function testModuleIdIsCorrect(): void
    {
        $this->assertEquals(
            self::MODULE_ID,
            Module::MODULE_ID,
            'Module::MODULE_ID constant must match expected module ID'
        );
    }

    /**
     * Test 5: Services are available after module activation
     *
     * @depends testModuleCanBeActivated
     */
    public function testServicesAvailableAfterActivation(): void
    {
        // Given: Module is active
        $this->activateModuleIfNotActive();

        // Clear container cache to ensure fresh services
        $container = ContainerFactory::getInstance()->getContainer();

        // When/Then: Key services should be available
        $this->assertTrue(
            $container->has('OxidEsales\Payments\Stripe\Service\ModuleConfigurationService'),
            'ModuleConfigurationService should be available after module activation'
        );

        $this->assertTrue(
            $container->has('OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface'),
            'EventDispatcherInterface should be available after module activation'
        );
    }

    /**
     * Test 6: Multiple activation/deactivation cycles don't cause issues
     */
    public function testMultipleActivationDeactivationCycles(): void
    {
        $cycles = 3;

        for ($i = 1; $i <= $cycles; $i++) {
            // Deactivate
            $this->deactivateModuleIfActive();
            $this->assertFalse(
                $this->stateService->isActive(self::MODULE_ID, self::SHOP_ID),
                "Module should be inactive after deactivation in cycle $i"
            );

            // Activate
            $this->activateModuleIfNotActive();
            $this->assertTrue(
                $this->stateService->isActive(self::MODULE_ID, self::SHOP_ID),
                "Module should be active after activation in cycle $i"
            );
        }
    }

    /**
     * Helper: Activate module if not already active
     */
    private function activateModuleIfNotActive(): void
    {
        if (!$this->stateService->isActive(self::MODULE_ID, self::SHOP_ID)) {
            $this->activationService->activate(self::MODULE_ID, self::SHOP_ID);
        }
    }

    /**
     * Helper: Deactivate module if currently active
     */
    private function deactivateModuleIfActive(): void
    {
        if ($this->stateService->isActive(self::MODULE_ID, self::SHOP_ID)) {
            $this->activationService->deactivate(self::MODULE_ID, self::SHOP_ID);
        }
    }

    protected function tearDown(): void
    {
        // Ensure module is left in active state after tests
        try {
            $this->activateModuleIfNotActive();
        } catch (\Throwable $e) {
            // Ignore errors during cleanup
        }

        parent::tearDown();
    }
}
