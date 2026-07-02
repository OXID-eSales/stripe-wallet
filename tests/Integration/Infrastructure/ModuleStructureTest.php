<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * Module structure integration tests
 *
 * TODO: Add tests for module structure validation
 */
class ModuleStructureTest extends TestCase
{
    public function testModuleMetadataExists(): void
    {
        $metadataPath = dirname(__DIR__, 3) . '/metadata.php';
        $this->assertFileExists($metadataPath, 'metadata.php should exist in module root');
    }

    public function testServicesYamlExists(): void
    {
        $servicesPath = dirname(__DIR__, 3) . '/services.yaml';
        $this->assertFileExists($servicesPath, 'services.yaml should exist in module root');
    }
}
