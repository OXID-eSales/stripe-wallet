<?php
// tests/Component/Unit/Infrastructure/ModuleStructureTest.php

declare(strict_types=1);

namespace Osc\Payment\Tests\Unit\Infrastructure;

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

        $this->assertEquals('osc/oxid-payment-stripe', $data['name']);
        $this->assertArrayHasKey('Osc\\Payment\\Component\\', $data['autoload']['psr-4']);
        $this->assertArrayHasKey('Osc\\Payment\\Stripe\\', $data['autoload']['psr-4']);
        $this->assertEquals('src/Component/', $data['autoload']['psr-4']['Osc\\Payment\\Component\\']);
        $this->assertEquals('src/Stripe/', $data['autoload']['psr-4']['Osc\\Payment\\Stripe\\']);
    }

    /** @test */
    public function metadata_php_exists_and_is_valid(): void
    {
        $metadataFile = $this->moduleRoot . '/metadata.php';
        $this->assertFileExists($metadataFile);

        $aModule = [];
        include $metadataFile;

        $this->assertEquals('osc/payment-stripe', $aModule['id']);
        $this->assertEquals('2.1', $GLOBALS['sMetadataVersion'] ?? $sMetadataVersion);
    }

    /** @test */
    public function component_directories_exist(): void
    {
        $requiredDirs = [
            'src/Component/Contract',
            'src/Component/Event',
            'src/Component/Event/Domain',
            'src/Component/Model',
            'src/Component/Repository',
            'src/Component/Service',
            'src/Component/Webhook',
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
            'src/Stripe/Webhook',
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
            'tests/Component/Unit/Component',
            'tests/Component/Unit/Stripe',
            'tests/Component/Integration/Component',
            'tests/Component/Integration/Stripe',
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
        $migrationDir = $this->moduleRoot . '/migration';
        $this->assertDirectoryExists($migrationDir);

        $expectedMigrations = [
            '001_create_payment_transaction_table.sql',
            '002_create_payment_order_state_table.sql',
            '003_create_payment_customer_table.sql',
            '004_create_payment_basket_snapshot_table.sql',
        ];

        foreach ($expectedMigrations as $migration) {
            $this->assertFileExists(
                $migrationDir . '/' . $migration,
                "Missing migration: $migration"
            );
        }
    }

    /** @test */
    public function phpunit_xml_is_configured_correctly(): void
    {
        $phpunitXml = $this->moduleRoot . '/phpunit.xml.dist';
        $this->assertFileExists($phpunitXml);

        $xml = simplexml_load_file($phpunitXml);

        // Check test suites exist
        $testsuites = $xml->xpath('//testsuite[@name="Unit"]');
        $this->assertCount(1, $testsuites, 'Unit test suite not found');

        $testsuites = $xml->xpath('//testsuite[@name="Integration"]');
        $this->assertCount(1, $testsuites, 'Integration test suite not found');
    }
}
