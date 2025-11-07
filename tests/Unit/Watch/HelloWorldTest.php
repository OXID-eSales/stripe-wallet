<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Watch;

use PHPUnit\Framework\TestCase;

/**
 * Hello World test to verify PHPUnit setup
 *
 * @group unit
 * @group watch
 */
class HelloWorldTest extends TestCase
{
    /**
     * @test
     */
    public function it_runs_phpunit_successfully(): void
    {
        $this->assertTrue(true, 'PHPUnit is working!');
    }

    /**
     * @test
     */
    public function it_has_correct_namespace(): void
    {
        $expectedNamespace = 'OxidSolutionCatalysts\Payments\Tests\Unit\Watch';
        $actualNamespace = __NAMESPACE__;

        $this->assertEquals($expectedNamespace, $actualNamespace);
    }

    /**
     * @test
     */
    public function it_can_access_oxid_constants(): void
    {
        // This verifies bootstrap.php loaded and OXID is available
        $this->assertTrue(
            defined('OXID_PHP_UNIT') ||
            defined('OX_BASE_PATH') ||
            class_exists('OxidEsales\Eshop\Core\Registry', false)
        );
    }
}
