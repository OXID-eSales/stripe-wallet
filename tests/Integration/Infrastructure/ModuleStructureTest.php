<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Infrastructure\Tests\Integration;

use PHPUnit\Framework\TestCase;

class ModuleStructureTest extends TestCase
{
    private string $moduleRoot;

    protected function setUp(): void
    {
        $this->moduleRoot = __DIR__ . '/../../..';
    }

    /** @test */
    public function composer_json_has_correct_namespaces(): void
    {
        $composerJson = $this->moduleRoot . '/composer.json';
        $this->assertFileExists($composerJson);

        $data = json_decode(file_get_contents($composerJson), true);

        $this->assertEquals('oxid-esales/stripe-wallet', $data['name']);
        $this->assertArrayHasKey('OxidSolutionCatalysts\\Payments\\Component\\', $data['autoload']['psr-4']);
        $this->assertArrayHasKey('OxidSolutionCatalysts\\Payments\\Stripe\\', $data['autoload']['psr-4']);
        $this->assertEquals('./src/Component', $data['autoload']['psr-4']['OxidSolutionCatalysts\\Payments\\Component\\']);
        $this->assertEquals('./src/Stripe', $data['autoload']['psr-4']['OxidSolutionCatalysts\\Payments\\Stripe\\']);
    }

    /** @test */
    public function metadata_php_exists_and_is_valid(): void
    {
        global $sMetadataVersion;
        $metadataFile = $this->moduleRoot . '/metadata.php';
        $this->assertFileExists($metadataFile);

        $aModule = [];
        include $metadataFile;

        $this->assertEquals('stripe', $aModule['id']);
        $this->assertEquals('2.1', $GLOBALS['sMetadataVersion'] ?? $sMetadataVersion);
    }

    /** @test */
    public function component_directories_exist(): void
    {
        $requiredDirs = [
            'src/Component/Contract',
            'src/Component/EventSystem/Event',
            'src/Component/EventSystem/Event/Contract',
            'src/Component/EventSystem/Event/Payment',
            'src/Component/Model',
            'src/Component/Repository',
            'src/Component/Service',
            'src/Component/Controller/Webhook',
        ];

        foreach ($requiredDirs as $dir) {
            $this->assertDirectoryExists(
                $this->moduleRoot . '/' . $dir,
                "Missing directory: $dir"
            );
        }
    }

    /** @test */
    public function stripe_directories_exist(): void
    {
        $requiredDirs = [
            'src/Stripe/Handler',
            'src/Stripe/Service',
            'src/Stripe/Controller/Webhook',
            'src/Stripe/Controller',
            'src/Stripe/Model',
        ];

        foreach ($requiredDirs as $dir) {
            $this->assertDirectoryExists(
                $this->moduleRoot . '/' . $dir,
                "Missing directory: $dir"
            );
        }
    }

    /** @test */
    public function test_directories_exist(): void
    {
        $requiredDirs = [
            'tests/Unit/Component',
            'tests/Unit/Stripe',
            'tests/Integration/Component',
            'tests/Integration/Stripe',
            'tests/Integration/Infrastructure',
            'tests/Support',
        ];

        foreach ($requiredDirs as $dir) {
            $this->assertDirectoryExists(
                $this->moduleRoot . '/' . $dir,
                "Missing test directory: $dir"
            );
        }
    }

    /** @test */
    public function migration_files_exist(): void
    {
        // Check migration directory structure
        $migrationDir = $this->moduleRoot . '/migration';
        $this->assertDirectoryExists($migrationDir, 'Migration directory should exist');

        // Check migrations.yml configuration file
        $migrationsYml = $migrationDir . '/migrations.yml';
        $this->assertFileExists($migrationsYml, 'migrations.yml configuration file should exist');

        // Verify migrations.yml content
        $yamlContent = file_get_contents($migrationsYml);
        $this->assertStringContainsString('oxmigrations_osc_stripe', $yamlContent, 'migrations.yml should contain correct table name');
        $this->assertStringContainsString('OxidSolutionCatalysts\Payments\Migrations', $yamlContent, 'migrations.yml should contain correct namespace');

        // Check migration/data directory
        $migrationDataDir = $migrationDir . '/data';
        $this->assertDirectoryExists($migrationDataDir, 'Migration data directory should exist');

        // Check Doctrine migration files
        $expectedMigrations = [
            'Version20251024140000.php', // Payment transaction table
            'Version20251024140100.php', // Payment order state table
            'Version20251024140200.php', // Payment customer table
            'Version20251024140300.php', // Payment basket snapshot table
        ];

        foreach ($expectedMigrations as $migration) {
            $this->assertFileExists(
                $migrationDataDir . '/' . $migration,
                "Missing Doctrine migration: $migration"
            );
        }
    }

    /** @test */
    public function phpunit_xml_is_configured_correctly(): void
    {
        $phpunitXml = $this->moduleRoot . '/tests/phpunit.xml';
        $this->assertFileExists($phpunitXml);

        $xml = simplexml_load_file($phpunitXml);

        // Check test suites exist
        $testsuites = $xml->xpath('//testsuite[@name="Unit"]');
        $this->assertCount(1, $testsuites, 'Unit test suite not found');

        $testsuites = $xml->xpath('//testsuite[@name="Integration"]');
        $this->assertCount(1, $testsuites, 'Integration test suite not found');
    }
}
