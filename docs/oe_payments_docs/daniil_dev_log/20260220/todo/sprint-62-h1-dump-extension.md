# Sprint 62 — Security: H1 DumpExtension Production Availability

**Date:** 2026-02-20
**Branch:** `b-7.4.x-security-STRP-99`
**Finding:** H1 — DumpExtension Available in Production
**Standard:** OWASP A05:2021 (Security Misconfiguration)
**CVSS:** 5.5

---

## Problem Statement

`DumpExtension` registers `dump()` and `dd()` Twig functions with `is_safe => ['html']` (bypasses
Twig auto-escaping) in all environments including production. `dd()` calls `die()`, enabling
denial-of-service from any template. The class is unconditionally registered in `services.yaml`.

The quick fix (deleting the file) was reverted: the correct solution is a proper
environment-aware implementation that satisfies SOLID, TDD, and clean-code requirements.

---

## Core Requirements

All code in this sprint must satisfy:

- **TDD-first** — write a failing test, then minimal implementation to make it pass, then refactor
- **SOLID** — Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation,
  Dependency Inversion
- **DI (Dependency Injection)** — inject dependencies via constructor; depend on abstractions
- **DRY** — no duplicated logic; shared helpers extracted appropriately
- **OCP / Liskov** — new behaviour via composition, not modification; subtypes are substitutable
- **Clean code** — meaningful names, methods 15–25 lines, no `else` (early returns), explicit
  `use` imports, no inline `\Exception`
- **No over-engineering** — implement exactly what the finding requires; no speculative features
- **PSR-12** code style; **PHPStan level max** compliant; **PHPMD** clean (no new violations)
- **Validation:** `./bin/pre-commit-check.sh --full` must pass before the sprint is closed

---

## Root Cause Analysis

| Problem | Location |
|---------|----------|
| `dump()` marked `is_safe => ['html']` — bypasses auto-escape | `DumpExtension::getFunctions()` |
| `dd()` calls `die()` — production DoS risk | `DumpExtension::dumpAndDie()` |
| No environment guard — registered unconditionally | `services.yaml` |
| No interface — untestable, violates DI | (no interface exists) |

---

## Design: Environment-Aware Registration

### Approach

Use a **environment checker interface** injected into `DumpExtension`. In production/live Stripe
mode, `getFunctions()` returns an empty array (the extension is a no-op). In development mode,
it returns the functions. The environment check is extracted behind an interface so it can be
mocked in tests — no static calls, no `$_ENV` access in the extension itself.

This is the minimal change that:
- Fixes the security issue without deleting the debugging tool
- Follows SRP (extension does Twig; env detection is separate)
- Follows DI (env checker injected, not instantiated internally)
- Is testable without framework bootstrapping

### New Interface

```
src/Stripe/Environment/DevelopmentEnvironmentCheckerInterface.php
```

```php
interface DevelopmentEnvironmentCheckerInterface
{
    public function isDevelopmentMode(): bool;
}
```

### Implementation

```
src/Stripe/Environment/ModuleConfigurationDevelopmentChecker.php
```

Injects `ModuleConfigurationServiceInterface`. Returns `true` when Stripe is in **test mode**
(i.e. `$config->isTestMode() === true`). Live mode is always treated as production.

Rationale: using the existing module-level test/live switch is the least-surprise behaviour for
merchants — debug tools are available when testing, unavailable in live.

### Updated DumpExtension

Constructor receives `DevelopmentEnvironmentCheckerInterface`. `getFunctions()` returns `[]` when
`isDevelopmentMode()` is `false`. `dump()` escapes output via `htmlspecialchars()` when rendering
(belt-and-suspenders; the `is_safe` flag is removed). `dumpAndDie()` is removed entirely — DoS
vector with no valid production use case.

### services.yaml

`DumpExtension` keeps its existing registration. Inject
`ModuleConfigurationDevelopmentChecker` as the checker argument.

---

## File Plan

| Action | File |
|--------|------|
| **CREATE** | `src/Stripe/Environment/DevelopmentEnvironmentCheckerInterface.php` |
| **CREATE** | `src/Stripe/Environment/ModuleConfigurationDevelopmentChecker.php` |
| **MODIFY** | `src/Stripe/Twig/DumpExtension.php` |
| **MODIFY** | `services.yaml` — add checker service and wire into DumpExtension |
| **CREATE** | `tests/Unit/Stripe/Environment/ModuleConfigurationDevelopmentCheckerTest.php` |
| **CREATE** | `tests/Unit/Stripe/Twig/DumpExtensionTest.php` |

---

## TDD Implementation Steps

### Step 1 — Interface (no test needed, pure contract)

Create `DevelopmentEnvironmentCheckerInterface` with single method `isDevelopmentMode(): bool`.

### Step 2 — DumpExtensionTest (RED)

Write `DumpExtensionTest` covering:

```
testGetFunctionsReturnsEmptyArrayInProductionMode()
  — mock checker returns false → getFunctions() === []

testGetFunctionsReturnsDumpFunctionInDevelopmentMode()
  — mock checker returns true → getFunctions() has 'dump'

testDumpReturnsEmptyStringForNoArgs()
  — checker true → dump() with no args returns ''

testDumpOutputIsHtmlEscaped()
  — checker true → dump('<script>') does not contain raw '<script>'

testDumpOutputContainsVarDumpData()
  — checker true → dump('hello') output contains 'hello'

testDumpAndDieDoesNotExist()
  — DumpExtension has no dumpAndDie method (assert via reflection or just omit)
```

All tests RED at this point.

### Step 3 — Implement DumpExtension (GREEN)

Rewrite `DumpExtension`:

```php
public function __construct(private DevelopmentEnvironmentCheckerInterface $envChecker)
{
}

public function getFunctions(): array
{
    if (!$this->envChecker->isDevelopmentMode()) {
        return [];
    }
    return [
        new TwigFunction('dump', [$this, 'dump']),  // no is_safe => html
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
```

`dumpAndDie` is **not** re-introduced. `getName()` kept for Twig compatibility.

Tests GREEN.

### Step 4 — ModuleConfigurationDevelopmentCheckerTest (RED)

```
testIsDevelopmentModeReturnsTrueInTestMode()
  — config->isTestMode() returns true → checker returns true

testIsDevelopmentModeReturnsFalseInLiveMode()
  — config->isTestMode() returns false → checker returns false
```

Tests RED.

### Step 5 — Implement ModuleConfigurationDevelopmentChecker (GREEN)

```php
public function __construct(private ModuleConfigurationServiceInterface $config)
{
}

public function isDevelopmentMode(): bool
{
    return $this->config->isTestMode();
}
```

Tests GREEN.

### Step 6 — services.yaml wiring

```yaml
OxidEsales\Payments\Stripe\Environment\ModuleConfigurationDevelopmentChecker:
  arguments:
    - '@OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface'
  public: false

OxidEsales\Payments\Stripe\Twig\DumpExtension:
  arguments:
    - '@OxidEsales\Payments\Stripe\Environment\ModuleConfigurationDevelopmentChecker'
  tags:
    - { name: twig.extension }
  public: false
```

### Step 7 — Refactor & clean up

- Review method lengths (target ≤ 20 lines)
- Confirm no `else` branches
- Confirm all `use` imports explicit
- Run `composer phpcs`, `composer phpstan`, `composer phpmd`

### Step 8 — Pre-commit validation

```bash
./bin/pre-commit-check.sh --full
```

Must report: 0 PHPCS errors, 0 PHPStan errors, 0 new PHPMD violations, all tests green.

---

## Acceptance Criteria

| Criterion | Check |
|-----------|-------|
| `getFunctions()` returns `[]` when `isTestMode() === false` | unit test |
| `dump()` output is HTML-escaped (no raw `<script>` passthrough) | unit test |
| `dumpAndDie()` method does not exist | unit test / code review |
| `DumpExtension` depends on interface, not concrete class | code review |
| No `$_ENV` / `$_SERVER` access in extension or checker | code review |
| `ModuleConfigurationDevelopmentChecker` has 100% unit coverage | test run |
| `DumpExtension` has 100% unit coverage for all branches | test run |
| Pre-commit `--full` passes clean | CI run |
| PHPStan level max: 0 errors | CI run |
| PHPMD: 0 new violations | CI run |

---

## Out of Scope

- Removing `DumpExtension` entirely — keep it as a guarded dev tool
- Fixing other STRP-99 findings (H2–H10, M1–M9, C1–C5 residuals) — separate sprints
- Adding environment modes beyond test/live — over-engineering

---

## References

- Audit report: `docs/oe_payments_docs/daniil_dev_log/20260219/reports/01-security-audit-strp99-no-mcp.md` (H1)
- OWASP A05:2021 Security Misconfiguration
- PSR-12, PHPStan level max, PHPMD baseline: `tests/PhpMd/phpmd.baseline.xml`
