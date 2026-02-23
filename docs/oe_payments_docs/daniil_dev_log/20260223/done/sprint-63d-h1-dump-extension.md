# Sprint 63d — H1: Environment-Aware DumpExtension

**Date:** 2026-02-23
**Branch:** `b-7.4.x-security-STRP-99`
**Finding:** H1 — DumpExtension Available in Production
**Severity:** HIGH | **CVSS:** 5.5
**Standard:** OWASP A05:2021 (Security Misconfiguration)
**Supersedes:** Sprint 62 plan (`20260220/todo/sprint-62-h1-dump-extension.md`)

---

## Problem

`DumpExtension` registers `dump()` and `dd()` Twig functions unconditionally in all environments:

```php
public function getFunctions(): array
{
    return [
        new TwigFunction('dump', [$this, 'dump'], ['is_safe' => ['html']]),
        new TwigFunction('dd', [$this, 'dumpAndDie'], ['is_safe' => ['html']]),
    ];
}

public function dumpAndDie(...$vars): string
{
    $output = $this->dump(...$vars);
    die($output);  // Kills request
}
```

Issues:
- `is_safe => ['html']` bypasses Twig auto-escaping — XSS if variables contain user input
- `dd()` calls `die()` — production DoS
- No environment check — available in production/live mode
- Unconditionally registered in `services.yaml`

---

## Core Requirements

- **TDD-first** — failing test, then implementation, then refactor
- **SOLID** — SRP (extension does Twig; env detection is separate), DI (inject env checker)
- **Clean code** — no else, early returns, methods 15-25 lines
- **PSR-12**, **PHPStan level max**, **PHPMD** clean
- **Never suppress static analysis warnings**
- Validation: `./bin/pre-commit-check.sh --full` must pass

---

## Design: Environment-Aware Registration

**Approach:** Inject a `DevelopmentEnvironmentCheckerInterface` into `DumpExtension`. In live mode, `getFunctions()` returns `[]` (no-op). In test mode, returns guarded `dump()` function. `dd()` is removed entirely.

**Why this over deleting the file:** Keep debug tooling available during development without production risk.

**Environment detection:** Use existing `ModuleConfigurationServiceInterface::isTestMode()` — test mode = development tools available, live mode = no debug functions. Least-surprise for merchants.

---

## File Plan

| Action | File |
|--------|------|
| CREATE | `src/Stripe/Environment/DevelopmentEnvironmentCheckerInterface.php` |
| CREATE | `src/Stripe/Environment/ModuleConfigurationDevelopmentChecker.php` |
| MODIFY | `src/Stripe/Twig/DumpExtension.php` |
| MODIFY | `services.yaml` |
| CREATE | `tests/Unit/Stripe/Twig/DumpExtensionTest.php` |
| CREATE | `tests/Unit/Stripe/Environment/ModuleConfigurationDevelopmentCheckerTest.php` |

---

## TDD Steps

### Step 1 — Interface (no test needed, pure contract)

Create `DevelopmentEnvironmentCheckerInterface`:

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Environment;

interface DevelopmentEnvironmentCheckerInterface
{
    public function isDevelopmentMode(): bool;
}
```

### Step 2 — DumpExtension Tests (RED)

Write `DumpExtensionTest`:

```
testGetFunctionsReturnsEmptyArrayInProductionMode()
  — Mock checker: isDevelopmentMode() returns false
  — $ext = new DumpExtension($mockChecker)
  — assertSame([], $ext->getFunctions())

testGetFunctionsReturnsDumpFunctionInDevelopmentMode()
  — Mock checker: isDevelopmentMode() returns true
  — $functions = $ext->getFunctions()
  — assertCount(1, $functions)
  — assertEquals('dump', $functions[0]->getName())

testDdMethodDoesNotExist()
  — assertFalse(method_exists($ext, 'dumpAndDie'))

testDumpReturnsEmptyStringForNoArgs()
  — Mock checker: isDevelopmentMode() returns true
  — assertSame('', $ext->dump())

testDumpOutputIsHtmlEscaped()
  — $result = $ext->dump('<script>alert(1)</script>')
  — assertStringNotContainsString('<script>', $result)
  — assertStringContainsString('&lt;script&gt;', $result)

testDumpOutputWrappedInPreTag()
  — $result = $ext->dump('hello')
  — assertStringStartsWith('<pre>', $result)
  — assertStringEndsWith('</pre>', $result)

testDumpOutputContainsVarDumpData()
  — $result = $ext->dump('hello')
  — assertStringContainsString('hello', $result)

testDumpWithMultipleArgs()
  — $result = $ext->dump('foo', 42)
  — assertStringContainsString('foo', $result)
  — assertStringContainsString('42', $result)

testGetNameReturnsDumpExtension()
  — assertEquals('dump_extension', $ext->getName())
  — (or whatever the existing getName() returns)
```

### Step 3 — Implement DumpExtension (GREEN)

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Twig;

use OxidEsales\Payments\Stripe\Environment\DevelopmentEnvironmentCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DumpExtension extends AbstractExtension
{
    public function __construct(
        private DevelopmentEnvironmentCheckerInterface $envChecker
    ) {
    }

    public function getFunctions(): array
    {
        if (!$this->envChecker->isDevelopmentMode()) {
            return [];
        }

        return [
            new TwigFunction('dump', [$this, 'dump']),
        ];
    }

    public function dump(mixed ...$vars): string
    {
        if (empty($vars)) {
            return '';
        }

        ob_start();
        foreach ($vars as $var) {
            var_dump($var);
        }
        $raw = (string) ob_get_clean();

        return '<pre>' . htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
}
```

Key changes from original:
- Constructor requires `DevelopmentEnvironmentCheckerInterface`
- `getFunctions()` returns `[]` in non-dev mode
- `is_safe => ['html']` removed — output will be auto-escaped by Twig
- `dumpAndDie()` removed entirely
- `dump()` HTML-escapes output as belt-and-suspenders

### Step 4 — Environment Checker Tests (RED)

Write `ModuleConfigurationDevelopmentCheckerTest`:

```
testIsDevelopmentModeReturnsTrueWhenTestMode()
  — Mock ModuleConfigurationServiceInterface: isTestMode() returns true
  — $checker = new ModuleConfigurationDevelopmentChecker($mockConfig)
  — assertTrue($checker->isDevelopmentMode())

testIsDevelopmentModeReturnsFalseWhenLiveMode()
  — Mock config: isTestMode() returns false
  — assertFalse($checker->isDevelopmentMode())
```

### Step 5 — Implement Environment Checker (GREEN)

```php
<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Environment;

use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;

class ModuleConfigurationDevelopmentChecker implements DevelopmentEnvironmentCheckerInterface
{
    public function __construct(
        private ModuleConfigurationServiceInterface $config
    ) {
    }

    public function isDevelopmentMode(): bool
    {
        return $this->config->isTestMode();
    }
}
```

### Step 6 — Wire services.yaml

Add/update service definitions:

```yaml
OxidEsales\Payments\Stripe\Environment\DevelopmentEnvironmentCheckerInterface:
  alias: OxidEsales\Payments\Stripe\Environment\ModuleConfigurationDevelopmentChecker

OxidEsales\Payments\Stripe\Environment\ModuleConfigurationDevelopmentChecker:
  arguments:
    - '@OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface'
  public: false

OxidEsales\Payments\Stripe\Twig\DumpExtension:
  arguments:
    - '@OxidEsales\Payments\Stripe\Environment\DevelopmentEnvironmentCheckerInterface'
  tags:
    - { name: twig.extension }
  public: false
```

### Step 7 — Refactor & Verify

- Confirm `DumpExtension` has no static calls, no `$_ENV`/`$_SERVER` access
- Confirm no `is_safe` in any Twig function registration
- Confirm `dumpAndDie` / `dd` not referenced anywhere
- Review method lengths (target ≤ 20 lines)
- Run all checks

---

## Acceptance Criteria

| Criterion | Check |
|-----------|-------|
| `getFunctions()` returns `[]` when `isTestMode() === false` | unit test |
| `getFunctions()` returns `dump` function when `isTestMode() === true` | unit test |
| `dump()` output is HTML-escaped (no raw `<script>`) | unit test |
| `dumpAndDie()` method does not exist | unit test |
| `is_safe => ['html']` not used in function registration | code review |
| DumpExtension depends on interface, not concrete class | code review |
| No `$_ENV`/`$_SERVER` access in extension or checker | code review |
| ModuleConfigurationDevelopmentChecker has 100% coverage | test run |
| DumpExtension has 100% branch coverage | test run |
| services.yaml wires checker correctly | code review |
| All existing tests pass (no regression) | pre-commit |
| 0 PHPCS / PHPStan / PHPMD errors | pre-commit |

---

## Completion Checklist

- [ ] Interface created
- [ ] DumpExtension tests written and RED
- [ ] DumpExtension implemented, tests GREEN
- [ ] Environment checker tests written and RED
- [ ] Environment checker implemented, tests GREEN
- [ ] services.yaml updated
- [ ] `./bin/pre-commit-check.sh --full` passes
- [ ] Sprint moved to `done/`
- [ ] Report created in `reports/`
- [ ] `status.md` updated

---

## References

- Original Sprint 62 plan: `20260220/todo/sprint-62-h1-dump-extension.md`
- Audit report: `20260219/reports/01-security-audit-strp99-no-mcp.md` (H1 section)
- OWASP A05:2021 Security Misconfiguration
