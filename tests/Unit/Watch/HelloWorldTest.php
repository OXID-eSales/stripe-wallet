<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap tests to verify PHPUnit setup for Watch module.
 *
 * Sprint 17: Removed false-positive assertTrue(true) test.
 * Remaining tests verify namespace and OXID constants are accessible.
 *
 * @group unit
 * @group watch
 * @group bootstrap
 */
class HelloWorldTest extends TestCase
{
    /**
     * @test
     * Verifies that the test namespace matches expected Watch module namespace.
     */
    public function it_has_correct_namespace(): void
    {
        $expectedNamespace = 'OxidSolutionCatalysts\Payments\Tests\Unit\Watch';
        $actualNamespace = __NAMESPACE__;

        $this->assertEquals($expectedNamespace, $actualNamespace);
    }

    /**
     * @test
     * Verifies that OXID constants/classes are accessible (bootstrap.php loaded).
     */
    public function it_can_access_oxid_constants(): void
    {
        // This verifies bootstrap.php loaded and OXID is available
        $this->assertTrue(
            defined('OXID_PHP_UNIT') ||
            defined('OX_BASE_PATH') ||
            class_exists('OxidEsales\Eshop\Core\Registry', false),
            'OXID environment should be accessible via bootstrap'
        );
    }
}
