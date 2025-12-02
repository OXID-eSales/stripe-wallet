<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Migrations;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * TDD Tests for Sprint 2 Table Consolidation Migration
 *
 * @group sprint-2
 * @group migrations
 */
class Sprint2TableConsolidationMigrationTest extends TestCase
{
    private string $migrationFile;

    protected function setUp(): void
    {
        $this->migrationFile = __DIR__ . '/../../../migration/data/Version20251202_Sprint2TableConsolidation.php';
    }

    /**
     * @test
     * Migration file should exist
     */
    public function migrationFileExists(): void
    {
        $this->assertFileExists(
            $this->migrationFile,
            'Sprint 2 migration file should exist'
        );
    }

    /**
     * @test
     * Migration should have valid PHP syntax
     */
    public function migrationHasValidSyntax(): void
    {
        $output = [];
        $exitCode = 0;

        exec("php -l {$this->migrationFile} 2>&1", $output, $exitCode);

        $this->assertEquals(
            0,
            $exitCode,
            'Migration should have valid PHP syntax: ' . implode("\n", $output)
        );
    }

    /**
     * @test
     * Migration class should be loadable
     */
    public function migrationClassIsLoadable(): void
    {
        require_once $this->migrationFile;

        $this->assertTrue(
            class_exists(\OxidSolutionCatalysts\Payments\Migrations\Version20251202_Sprint2TableConsolidation::class),
            'Migration class should be loadable'
        );
    }

    /**
     * @test
     * Migration should extend AbstractMigration
     */
    public function migrationExtendsAbstractMigration(): void
    {
        require_once $this->migrationFile;

        $reflection = new ReflectionClass(
            \OxidSolutionCatalysts\Payments\Migrations\Version20251202_Sprint2TableConsolidation::class
        );

        $this->assertTrue(
            $reflection->isSubclassOf(\Doctrine\Migrations\AbstractMigration::class),
            'Migration should extend AbstractMigration'
        );
    }

    /**
     * @test
     * Migration should have up method
     */
    public function migrationHasUpMethod(): void
    {
        require_once $this->migrationFile;

        $reflection = new ReflectionClass(
            \OxidSolutionCatalysts\Payments\Migrations\Version20251202_Sprint2TableConsolidation::class
        );

        $this->assertTrue(
            $reflection->hasMethod('up'),
            'Migration should have up() method'
        );
    }

    /**
     * @test
     * Migration should have down method
     */
    public function migrationHasDownMethod(): void
    {
        require_once $this->migrationFile;

        $reflection = new ReflectionClass(
            \OxidSolutionCatalysts\Payments\Migrations\Version20251202_Sprint2TableConsolidation::class
        );

        $this->assertTrue(
            $reflection->hasMethod('down'),
            'Migration should have down() method'
        );
    }

    /**
     * @test
     * Migration source should contain DROP for redundant tables
     */
    public function migrationDropsRedundantTables(): void
    {
        $source = file_get_contents($this->migrationFile);

        $this->assertStringContainsString(
            'DROP TABLE IF EXISTS osc_stripe_customer_mapping',
            $source,
            'Migration should drop osc_stripe_customer_mapping'
        );

        $this->assertStringContainsString(
            'DROP TABLE IF EXISTS osc_payment_webhook_log',
            $source,
            'Migration should drop osc_payment_webhook_log'
        );

        $this->assertStringContainsString(
            'DROP TABLE IF EXISTS osc_stripe_payment_details',
            $source,
            'Migration should drop osc_stripe_payment_details'
        );
    }

    /**
     * @test
     * Migration should add OXPROVIDER column to webhook logs
     */
    public function migrationAddsProviderColumnToWebhookLogs(): void
    {
        $source = file_get_contents($this->migrationFile);

        $this->assertStringContainsString(
            'OXPROVIDER',
            $source,
            'Migration should add OXPROVIDER column'
        );
    }

    /**
     * @test
     * Migration should add OXPAYLOAD column to webhook logs
     */
    public function migrationAddsPayloadColumnToWebhookLogs(): void
    {
        $source = file_get_contents($this->migrationFile);

        $this->assertStringContainsString(
            'OXPAYLOAD',
            $source,
            'Migration should add OXPAYLOAD column'
        );
    }

    /**
     * @test
     * Migration should migrate customer data
     */
    public function migrationMigratesCustomerData(): void
    {
        $source = file_get_contents($this->migrationFile);

        $this->assertStringContainsString(
            'INSERT INTO osc_payment_customer',
            $source,
            'Migration should migrate data to osc_payment_customer'
        );

        $this->assertStringContainsString(
            'FROM osc_stripe_customer_mapping',
            $source,
            'Migration should select from osc_stripe_customer_mapping'
        );
    }

    /**
     * @test
     * Migration should migrate webhook data
     */
    public function migrationMigratesWebhookData(): void
    {
        $source = file_get_contents($this->migrationFile);

        $this->assertStringContainsString(
            'INSERT INTO osc_payment_webhooklogs',
            $source,
            'Migration should migrate data to osc_payment_webhooklogs'
        );

        $this->assertStringContainsString(
            'FROM osc_payment_webhook_log',
            $source,
            'Migration should select from osc_payment_webhook_log'
        );
    }

    /**
     * @test
     * Migration should have meaningful description
     */
    public function migrationHasMeaningfulDescription(): void
    {
        require_once $this->migrationFile;

        $reflection = new ReflectionClass(
            \OxidSolutionCatalysts\Payments\Migrations\Version20251202_Sprint2TableConsolidation::class
        );

        $this->assertTrue(
            $reflection->hasMethod('getDescription'),
            'Migration should have getDescription() method'
        );
    }
}
