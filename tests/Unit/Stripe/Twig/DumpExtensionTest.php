<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Twig;

use OxidEsales\Payments\Stripe\Environment\DevelopmentEnvironmentCheckerInterface;
use OxidEsales\Payments\Stripe\Twig\DumpExtension;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(\OxidEsales\Payments\Stripe\Twig\DumpExtension::class)]
    #[Group('security')]
    #[Group('owasp-a05')]
class DumpExtensionTest extends TestCase
{
    private DevelopmentEnvironmentCheckerInterface $checkerDev;
    private DevelopmentEnvironmentCheckerInterface $checkerProd;

    protected function setUp(): void
    {
        $this->checkerDev = $this->createMock(DevelopmentEnvironmentCheckerInterface::class);
        $this->checkerDev->method('isDevelopmentMode')->willReturn(true);

        $this->checkerProd = $this->createMock(DevelopmentEnvironmentCheckerInterface::class);
        $this->checkerProd->method('isDevelopmentMode')->willReturn(false);
    }

    public function testGetFunctionsReturnsEmptyArrayInProductionMode(): void
    {
        $extension = new DumpExtension($this->checkerProd);

        $this->assertSame([], $extension->getFunctions());
    }

    public function testGetFunctionsReturnsDumpFunctionInDevelopmentMode(): void
    {
        $extension = new DumpExtension($this->checkerDev);

        $functions = $extension->getFunctions();

        $this->assertNotEmpty($functions);
        $names = array_map(fn($f) => $f->getName(), $functions);
        $this->assertContains('dump', $names);
    }

    public function testGetFunctionsDoesNotRegisterDdInDevelopmentMode(): void
    {
        $extension = new DumpExtension($this->checkerDev);

        $names = array_map(fn($f) => $f->getName(), $extension->getFunctions());

        $this->assertNotContains('dd', $names);
    }

    public function testDumpReturnsEmptyStringForNoArgs(): void
    {
        $extension = new DumpExtension($this->checkerDev);

        $this->assertSame('', $extension->dump());
    }

    public function testDumpOutputIsHtmlEscaped(): void
    {
        $extension = new DumpExtension($this->checkerDev);

        $output = $extension->dump('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function testDumpOutputContainsVarDumpData(): void
    {
        $extension = new DumpExtension($this->checkerDev);

        $output = $extension->dump('hello');

        $this->assertStringContainsString('hello', $output);
    }

    public function testDumpAndDieMethodDoesNotExist(): void
    {
        $this->assertFalse(
            method_exists(DumpExtension::class, 'dumpAndDie'),
            'dumpAndDie() must not exist — die() in a Twig function is a production DoS vector'
        );
    }

    public function testServicesYamlRegistersChecker(): void
    {
        $servicesPath = dirname(__DIR__, 4) . '/services.yaml';

        if (!file_exists($servicesPath)) {
            $this->markTestSkipped('services.yaml not found');
        }

        $content = (string) file_get_contents($servicesPath);

        $this->assertStringContainsString(
            'ModuleConfigurationDevelopmentChecker',
            $content,
            'services.yaml must register ModuleConfigurationDevelopmentChecker'
        );
    }

    public function testServicesYamlWiresDumpExtensionWithChecker(): void
    {
        $servicesPath = dirname(__DIR__, 4) . '/services.yaml';

        if (!file_exists($servicesPath)) {
            $this->markTestSkipped('services.yaml not found');
        }

        $content = (string) file_get_contents($servicesPath);

        $this->assertStringContainsString(
            'DevelopmentEnvironmentCheckerInterface',
            $content,
            'services.yaml must reference DevelopmentEnvironmentCheckerInterface or the checker'
        );
    }
}
