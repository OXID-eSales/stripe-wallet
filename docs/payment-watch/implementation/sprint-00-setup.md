# Sprint 0: Project Setup & Infrastructure

**Duration:** 1 week
**Team:** Full team (2-3 developers)
**Goal:** Establish development environment and project structure

---

## Sprint Overview

This sprint focuses on creating the foundation for PaymentWatch development. By the end of this sprint, the team will have a fully configured development environment with Docker, PHPUnit, and OXID module registration.

**Key Focus:**
- ✅ Directory structure
- ✅ Composer configuration
- ✅ PHPUnit setup in Docker
- ✅ OXID module registration
- ✅ Team onboarding to TDD workflow

---

## Tasks

### Task 0.1: Repository & Module Structure

**Objective:** Create the correct directory structure following OXID and namespace conventions.

**Steps:**

```bash
cd /home/dtkachev/osc/strpwt7-oct21/source/extensions/stripe

# Create source directory structure
mkdir -p src/Watch/{Controller,Service,ValueObject,Strategy,Exception,Config}

# Create test directory structure
mkdir -p tests/Unit/Watch/{ValueObject,Service,Strategy,Infrastructure}
mkdir -p tests/Integration/Watch/{Controller,Database,EndToEnd}
mkdir -p tests/Acceptance/Watch

# Verify structure
tree src/Watch tests/Unit/Watch tests/Integration/Watch
```

**Expected Output:**
```
src/Watch/
├── Config/
├── Controller/
├── Exception/
├── Service/
├── Strategy/
└── ValueObject/

tests/Unit/Watch/
├── Infrastructure/
├── Service/
├── Strategy/
└── ValueObject/

tests/Integration/Watch/
├── Controller/
├── Database/
└── EndToEnd/
```

**Acceptance Criteria:**
- ✅ All directories created
- ✅ Structure matches documentation (01-implementation-guide.md)
- ✅ Directory names follow PSR-4 conventions

**Time Estimate:** 30 minutes

---

### Task 0.2: Composer Configuration

**Objective:** Configure Composer autoloading for the PaymentWatch namespace.

**Steps:**

1. **Edit composer.json:**

```json
{
  "name": "oxid-esales/stripe-payment-module",
  "autoload": {
    "psr-4": {
      "OxidSolutionCatalysts\\Payments\\Stripe\\": "./src/Stripe",
      "OxidSolutionCatalysts\\Payments\\Watch\\": "./src/Watch"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "OxidSolutionCatalysts\\Payments\\Watch\\Tests\\": "./tests"
    }
  },
  "require-dev": {
    "phpunit/phpunit": "^10.0",
    "mockery/mockery": "^1.6"
  }
}
```

2. **Regenerate autoloader:**

```bash
composer dump-autoload
```

3. **Verify autoloading works:**

```bash
composer validate
# Should output: ./composer.json is valid
```

**Acceptance Criteria:**
- ✅ `composer validate` passes
- ✅ Namespace `OxidSolutionCatalysts\Payments\Watch\` maps to `./src/Watch`
- ✅ Dev dependencies installed

**Time Estimate:** 20 minutes

---

### Task 0.3: PHPUnit Configuration

**Objective:** Set up PHPUnit to run tests in Docker with coverage enabled.

**Steps:**

1. **Create tests/phpunit.xml:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.0/phpunit.xsd"
         bootstrap="/var/www/source/bootstrap.php"
         colors="true"
         verbose="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>Integration</directory>
        </testsuite>
        <testsuite name="Acceptance">
            <directory>Acceptance</directory>
        </testsuite>
    </testsuites>

    <coverage includeUncoveredFiles="true"
              pathCoverage="false"
              ignoreDeprecatedCodeUnits="true"
              disableCodeCoverageIgnore="true">
        <report>
            <html outputDirectory="coverage/html"/>
            <clover outputFile="coverage/clover.xml"/>
            <text outputFile="php://stdout" showUncoveredFiles="true"/>
        </report>
        <include>
            <directory suffix=".php">../src/Watch</directory>
        </include>
        <exclude>
            <directory>../src/Watch/Config</directory>
        </exclude>
    </coverage>

    <php>
        <ini name="display_errors" value="1"/>
        <ini name="error_reporting" value="32767"/>
    </php>

    <groups>
        <exclude>
            <group>integration</group>
            <group>acceptance</group>
        </exclude>
    </groups>
</phpunit>
```

2. **Create test command alias:**

Add to `~/.bashrc` or `~/.zshrc`:

```bash
# PaymentWatch PHPUnit alias
alias phpunit-watch='docker compose exec -T -e XDEBUG_MODE=coverage php vendor/bin/phpunit -c /var/www/extensions/stripe/tests/phpunit.xml --bootstrap=/var/www/source/bootstrap.php'
```

3. **Reload shell:**

```bash
source ~/.bashrc  # or source ~/.zshrc
```

4. **Test PHPUnit:**

```bash
phpunit-watch --version
# Should output: PHPUnit 10.x.x
```

**Acceptance Criteria:**
- ✅ PHPUnit runs in Docker container
- ✅ Xdebug coverage enabled
- ✅ Bootstrap file loads correctly
- ✅ Alias works
- ✅ No permission issues

**Time Estimate:** 45 minutes

---

### Task 0.4: Module Metadata

**Objective:** Register PaymentWatch as an OXID module.

**Steps:**

1. **Create metadata.php:**

```php
<?php
/**
 * PaymentWatch Module Metadata
 *
 * @category   Module
 * @package    OxidSolutionCatalysts\Payments\Watch
 * @author     OXID eSales AG
 * @license    GPL-3.0
 */

declare(strict_types=1);

$sMetadataVersion = '2.1';

$aModule = [
    'id' => 'osc-paymentwatch',
    'title' => [
        'en' => 'PaymentWatch - E2E Testing Helper',
        'de' => 'PaymentWatch - E2E Test-Helfer'
    ],
    'description' => [
        'en' => 'Secure API for remote database state verification in E2E payment tests',
        'de' => 'Sichere API für Remote-Datenbankstatusüberprüfung in E2E-Zahlungstests'
    ],
    'thumbnail' => 'logo.png',
    'version' => '1.0.0',
    'author' => 'OXID eSales AG',
    'url' => 'https://www.oxid-esales.com',
    'email' => 'info@oxid-esales.com',
    'extend' => [],
    'controllers' => [
        'paymentwatch_assumption' => \OxidSolutionCatalysts\Payments\Watch\Controller\AssumptionController::class
    ],
    'templates' => [],
    'blocks' => [],
    'settings' => [
        [
            'group' => 'paymentwatch_main',
            'name' => 'paywatchEnabled',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'paymentwatch_main',
            'name' => 'paywatchAllowedHosts',
            'type' => 'aarr',
            'value' => []
        ],
        [
            'group' => 'paymentwatch_main',
            'name' => 'paywatchRateLimitEnabled',
            'type' => 'bool',
            'value' => false
        ],
        [
            'group' => 'paymentwatch_main',
            'name' => 'paywatchRateLimitPerMinute',
            'type' => 'str',
            'value' => '100'
        ]
    ],
    'events' => [
        'onActivate' => '\OxidSolutionCatalysts\Payments\Watch\Core\Events::onActivate',
        'onDeactivate' => '\OxidSolutionCatalysts\Payments\Watch\Core\Events::onDeactivate'
    ]
];
```

2. **Create routes configuration (Config/routes.yaml):**

```yaml
paymentwatch_assume:
    path: /paymentwatch/assume
    controller: paymentwatch_assumption::assume
    methods: [POST]
    requirements:
        _moduleId: osc-paymentwatch
    defaults:
        _format: json
```

3. **Activate module:**

```bash
docker compose exec -T php vendor/bin/oe-console oe:module:activate osc-paymentwatch
```

4. **Verify module active:**

```bash
docker compose exec -T php vendor/bin/oe-console oe:module:list

# Should show:
# osc-paymentwatch | active | PaymentWatch - E2E Testing Helper
```

**Acceptance Criteria:**
- ✅ Module appears in OXID admin panel
- ✅ Module can be activated/deactivated
- ✅ Route `/paymentwatch/assume` registered
- ✅ Settings visible in admin

**Time Estimate:** 1 hour

---

### Task 0.5: First Test (Hello World)

**Objective:** Create and run the first test to verify TDD workflow.

**Steps:**

1. **Create first test (tests/Unit/Watch/HelloWorldTest.php):**

```php
<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch;

use PHPUnit\Framework\TestCase;

/**
 * Hello World test to verify PHPUnit setup
 *
 * @group unit
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
        $expectedNamespace = 'OxidSolutionCatalysts\Payments\Watch\Tests\Unit\Watch';
        $actualNamespace = __NAMESPACE__;

        $this->assertEquals($expectedNamespace, $actualNamespace);
    }

    /**
     * @test
     */
    public function it_can_access_oxid_bootstrap(): void
    {
        // This verifies bootstrap.php loaded
        $this->assertTrue(defined('OX_BASE_PATH') || class_exists('OxidEsales\Eshop\Core\Registry'));
    }
}
```

2. **Run test:**

```bash
phpunit-watch tests/Unit/Watch/HelloWorldTest.php
```

**Expected Output:**
```
PHPUnit 10.5.0 by Sebastian Bergmann and contributors.

...                                                                 3 / 3 (100%)

Time: 00:00.123, Memory: 10.00 MB

OK (3 tests, 3 assertions)
```

3. **Run with coverage:**

```bash
phpunit-watch tests/Unit/Watch/HelloWorldTest.php --coverage-text
```

**Acceptance Criteria:**
- ✅ All 3 tests pass
- ✅ Coverage report generated
- ✅ OXID bootstrap accessible
- ✅ No errors or warnings

**Time Estimate:** 30 minutes

---

### Task 0.6: Development Tools Setup

**Objective:** Configure IDE and development tools for efficient TDD workflow.

**Steps:**

1. **PHPStorm Configuration:**

   - **Settings → PHP:**
     - CLI Interpreter: Docker (docker compose exec php)
     - Path mappings: `/var/www` → project root

   - **Settings → PHP → Test Frameworks:**
     - Add PHPUnit by Remote Interpreter
     - Configuration file: `tests/phpunit.xml`
     - Bootstrap file: `/var/www/source/bootstrap.php`

   - **Settings → PHP → Debug:**
     - Add Xdebug server
     - Port: 9003
     - Path mappings configured

2. **VSCode Configuration (.vscode/settings.json):**

```json
{
  "php.validate.executablePath": "docker compose exec -T php php",
  "php.suggest.basic": false,
  "phpunit.php": "docker compose exec -T php php",
  "phpunit.phpunit": "vendor/bin/phpunit",
  "phpunit.args": [
    "-c", "/var/www/extensions/stripe/tests/phpunit.xml",
    "--bootstrap=/var/www/source/bootstrap.php"
  ]
}
```

3. **Git Configuration:**

Create `.gitignore` additions:

```
# PaymentWatch specific
/src/Watch/.phpunit.result.cache
/tests/.phpunit.result.cache
/coverage/
/tests/coverage/

# IDE
.idea/
.vscode/
*.swp
*.swo

# Composer
vendor/
composer.lock
```

4. **Documentation Access:**

Create quick reference document:

```bash
cat > DEVELOPMENT.md << 'EOF'
# PaymentWatch Development Quick Reference

## Running Tests

```bash
# All tests
phpunit-watch

# Specific test file
phpunit-watch tests/Unit/Watch/ValueObject/AssumptionRequestTest.php

# With coverage
phpunit-watch --coverage-html coverage/

# Specific group
phpunit-watch --group security
```

## TDD Workflow

1. **RED**: Write failing test
2. **GREEN**: Make test pass (minimal code)
3. **REFACTOR**: Improve code while tests stay green

## Useful Commands

```bash
# Run OXID console
docker compose exec -T php vendor/bin/oe-console

# Activate module
docker compose exec -T php vendor/bin/oe-console oe:module:activate osc-paymentwatch

# Check routes
docker compose exec -T php vendor/bin/oe-console debug:router | grep paymentwatch

# Clear cache
docker compose exec -T php vendor/bin/oe-console oe:cache:clear
```
EOF
```

**Acceptance Criteria:**
- ✅ IDE can run tests directly
- ✅ Xdebug breakpoints work
- ✅ Namespace resolution working
- ✅ Quick reference created

**Time Estimate:** 1 hour

---

### Task 0.7: Team Onboarding

**Objective:** Ensure all team members understand TDD workflow and project structure.

**Steps:**

1. **Team Workshop (2 hours):**
   - Overview of PaymentWatch architecture
   - TDD principles (RED-GREEN-REFACTOR)
   - Demo: First test execution
   - Hands-on: Each developer runs test suite

2. **Documentation Review:**
   - [TDD Overview](../tdd/00-overview.md)
   - [Implementation Guide](../01-implementation-guide.md)
   - [Sprint Plan](../SPRINT-PLAN.md)

3. **Setup Verification:**
   - Each team member runs `phpunit-watch`
   - Verify Docker environment working
   - Confirm IDE integration

4. **Communication Setup:**
   - Slack channel: `#paymentwatch-dev`
   - GitHub project board
   - Daily standup schedule (9:00 AM)

**Acceptance Criteria:**
- ✅ All team members can run tests
- ✅ TDD workflow understood
- ✅ Communication channels active
- ✅ Questions documented in FAQ

**Time Estimate:** 2 hours

---

## Sprint Deliverables

### Completed Setup
- ✅ Module directory structure created
- ✅ Composer autoloading configured
- ✅ PHPUnit running in Docker
- ✅ OXID module registered and activated
- ✅ First test passing
- ✅ Development tools configured
- ✅ Team onboarded

### Documentation
- ✅ DEVELOPMENT.md quick reference
- ✅ phpunit.xml configuration
- ✅ metadata.php module definition
- ✅ routes.yaml route configuration

### Artifacts
- ✅ Coverage report (empty, but working)
- ✅ Test output logs
- ✅ Docker environment verified

---

## Acceptance Criteria

### Must Have
- ✅ PHPUnit runs successfully: `phpunit-watch --version`
- ✅ Module activated: `oe:module:list` shows `osc-paymentwatch`
- ✅ First test passes: All 3 tests green
- ✅ Coverage report generates: `coverage/` directory created
- ✅ Team members can run tests independently

### Nice to Have
- ✅ IDE integration working (PHPStorm/VSCode)
- ✅ Git hooks for pre-commit testing
- ✅ Docker Compose optimized for development

---

## Testing Checklist

Run these commands to verify Sprint 0 complete:

```bash
# 1. Verify directory structure
ls -la src/Watch
ls -la tests/Unit/Watch

# 2. Verify Composer
composer validate

# 3. Verify PHPUnit
phpunit-watch --version

# 4. Run hello world test
phpunit-watch tests/Unit/Watch/HelloWorldTest.php

# 5. Verify module
docker compose exec -T php vendor/bin/oe-console oe:module:list | grep paymentwatch

# 6. Check route registered
docker compose exec -T php vendor/bin/oe-console debug:router | grep paymentwatch

# 7. Generate coverage report
phpunit-watch --coverage-text
```

**All checks must pass before moving to Sprint 1.**

---

## Common Issues & Solutions

### Issue: "Class not found" errors

**Solution:**
```bash
composer dump-autoload
docker compose restart php
```

### Issue: Permission denied in coverage/

**Solution:**
```bash
chmod -R 777 tests/coverage
# Or run tests as proper user:
docker compose exec -u $(id -u):$(id -g) php vendor/bin/phpunit
```

### Issue: Module not appearing in admin

**Solution:**
```bash
# Clear OXID cache
docker compose exec -T php vendor/bin/oe-console oe:cache:clear

# Regenerate views
docker compose exec -T php vendor/bin/oe-console oe:views:update
```

### Issue: Bootstrap file not found

**Solution:**
Verify path in phpunit.xml:
```xml
bootstrap="/var/www/source/bootstrap.php"
```

Check file exists:
```bash
docker compose exec -T php ls -la /var/www/source/bootstrap.php
```

---

## Sprint Review Checklist

**Demo:**
- [ ] Show directory structure
- [ ] Run PHPUnit successfully
- [ ] Show module in OXID admin
- [ ] Execute hello world test
- [ ] Generate coverage report

**Retrospective Questions:**
- What went well with setup?
- Any blockers encountered?
- Is everyone comfortable with TDD workflow?
- What improvements for next sprint?

---

## Next Sprint

**Ready for Sprint 1?**

👉 **Continue to [Sprint 1: Domain Layer](sprint-01-domain.md)**

**Prerequisites:**
- ✅ All Sprint 0 tasks complete
- ✅ All tests passing
- ✅ Team onboarded
- ✅ Development environment working

---

**Sprint 0 Status:** ⏳ Pending

**Last Updated:** 2025-11-12
