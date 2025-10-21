# TICKET-001: Project Setup with Component/Stripe Structure

[Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-002 →](SPRINT-1-TICKET-02-event-layer.md)

---

## Summary
Set up the OXID module with complete directory structure separating Component (reusable) and Stripe (provider-specific) with TDD infrastructure.

## Priority
**P0 - Blocker** (All other work depends on this)

## Story Points
**8 points** (2 days)

## Business Value
Establishes the foundation for clean architecture with clear separation between reusable component and Stripe implementation, enabling future extraction to Composer package.

---

## Description

Create the OXID module structure with:
- Correct PSR-4 namespacing (Component vs Stripe)
- OXID metadata.php configuration
- Complete test infrastructure (PHPUnit, test doubles, fixtures)
- Database migrations structure
- Code quality tools (PHPStan, PHPCS)
- CI/CD pipeline

This follows TDD by setting up tests BEFORE any production code.

---

## Acceptance Criteria

### Must Have
- [ ] OXID module structure created (`extensions/osc/stripe/`)
- [ ] Composer package with dual PSR-4 namespaces
  - `Osc\Payment\Component\` → `src/Component/`
  - `Osc\Payment\Stripe\` → `src/Stripe/`
- [ ] metadata.php configured for OXID
- [ ] PHPUnit 9+ with 3 test suites (Unit, Integration, Acceptance)
- [ ] Database migration files created
- [ ] PHPStan level 6+ configured
- [ ] GitHub Actions workflow
- [ ] Directory structure verification test passes

### Should Have
- [ ] Makefile with common commands
- [ ] Docker setup for testing
- [ ] Pre-commit hooks

### Won't Have (This Sprint)
- OXID shop integration (later)
- Admin UI (later)

---

## Technical Details

### Directory Creation Script

```bash
#!/bin/bash
# scripts/setup-structure.sh

MODULE_DIR="extensions/osc/stripe"

# Create Component directories
mkdir -p "$MODULE_DIR/src/Component/Contract"
mkdir -p "$MODULE_DIR/src/Component/Event/Domain"
mkdir -p "$MODULE_DIR/src/Component/EventHandler"
mkdir -p "$MODULE_DIR/src/Component/Model"
mkdir -p "$MODULE_DIR/src/Component/Repository"
mkdir -p "$MODULE_DIR/src/Component/Service"
mkdir -p "$MODULE_DIR/src/Component/Webhook"

# Create Stripe directories
mkdir -p "$MODULE_DIR/src/Stripe/Handler"
mkdir -p "$MODULE_DIR/src/Stripe/Service"
mkdir -p "$MODULE_DIR/src/Stripe/Webhook/Handler"
mkdir -p "$MODULE_DIR/src/Stripe/Controller/Admin"
mkdir -p "$MODULE_DIR/src/Stripe/Model"

# Create test directories
mkdir -p "$MODULE_DIR/tests/Unit/Component/Event/Domain"
mkdir -p "$MODULE_DIR/tests/Unit/Component/Model"
mkdir -p "$MODULE_DIR/tests/Unit/Stripe/Handler"
mkdir -p "$MODULE_DIR/tests/Integration/Component/Repository"
mkdir -p "$MODULE_DIR/tests/Integration/Stripe"
mkdir -p "$MODULE_DIR/tests/Acceptance/Stripe"
mkdir -p "$MODULE_DIR/tests/Support/Builders"
mkdir -p "$MODULE_DIR/tests/Support/Fixtures"

# Create migration directory
mkdir -p "$MODULE_DIR/migration"

# Create views
mkdir -p "$MODULE_DIR/views/blocks/page/checkout"
mkdir -p "$MODULE_DIR/views/admin/tpl"

echo "✅ Directory structure created"
```

### Database Migration Runner

```php
<?php
// src/Stripe/Setup/MigrationRunner.php

namespace Osc\Payment\Stripe\Setup;

use OxidEsales\Eshop\Core\Database\Adapter\DatabaseInterface;

class MigrationRunner
{
    private DatabaseInterface $db;
    private string $migrationPath;

    public function __construct(DatabaseInterface $db, string $migrationPath)
    {
        $this->db = $db;
        $this->migrationPath = $migrationPath;
    }

    public function runAll(): void
    {
        $migrations = glob($this->migrationPath . '/*.sql');
        sort($migrations);

        foreach ($migrations as $migration) {
            $this->runMigration($migration);
        }
    }

    private function runMigration(string $file): void
    {
        $sql = file_get_contents($file);
        $statements = array_filter(explode(';', $sql));

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $this->db->execute($statement);
            }
        }

        echo "✅ Migrated: " . basename($file) . "\n";
    }
}
```

---

## TDD Workflow

### Step 1: RED - Write Structure Verification Tests

```php
<?php
// tests/Unit/Infrastructure/ModuleStructureTest.php

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
            'tests/Unit/Component',
            'tests/Unit/Stripe',
            'tests/Integration/Component',
            'tests/Integration/Stripe',
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
```

### Step 2: GREEN - Create Structure

Run tests (they fail), then create all files/directories.

### Step 3: REFACTOR - Document

Add README, improve scripts.

---

## Tasks Breakdown

1. **Create Directory Structure** (2 hours)
   - [ ] Run setup script
   - [ ] Create .gitkeep files
   - [ ] Verify structure

2. **Configure Composer** (2 hours)
   - [ ] Create composer.json with dual namespaces
   - [ ] Run `composer install`
   - [ ] Test autoloading

3. **Create OXID metadata.php** (2 hours)
   - [ ] Configure module metadata
   - [ ] Define extended classes
   - [ ] Configure controllers
   - [ ] Test metadata loads in OXID

4. **Database Migrations** (3 hours)
   - [ ] Create migration files (001-004)
   - [ ] Create MigrationRunner
   - [ ] Test migrations run successfully
   - [ ] Verify table structure

5. **Test Infrastructure** (3 hours)
   - [ ] Configure PHPUnit with 3 suites
   - [ ] Create base test classes
   - [ ] Configure PHPStan
   - [ ] Write structure verification tests
   - [ ] Run all tests (GREEN)

6. **CI/CD Pipeline** (2 hours)
   - [ ] Create GitHub Actions workflow
   - [ ] Test workflow runs

---

## Definition of Done

- [ ] All acceptance criteria met
- [ ] Structure verification tests pass
- [ ] Composer autoload works for both namespaces
- [ ] Database migrations run successfully
- [ ] PHPUnit runs with 3 test suites
- [ ] PHPStan level 6 passes (with no code yet)
- [ ] README documents structure
- [ ] PR reviewed and approved

---

## Dependencies
None (foundation ticket)

---

## Related Tickets
Blocks all other tickets

---

[Back to Sprint Overview](SPRINT-1-overview.md) | [Back to Index](SPRINT-1-index.md) | [Next: TICKET-002 →](SPRINT-1-TICKET-02-event-layer.md)
