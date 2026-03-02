# Sprint 70b — M3: Restrictive File Permissions

**Date:** 2026-02-24
**Status:** DONE
**Finding:** M3 — File Permissions Too Permissive (CVSS 3.0, MEDIUM)
**Package:** payment-component

## Problem

`FileLogger::ensureDirectoryExists()` created log directories with `0755` (world-readable: `rwxr-xr-x`). On shared hosting or misconfigured servers, any user on the system can read log files containing contract IDs, payment intent IDs, error details, and debugging info — all sensitive in a PCI DSS context.

```php
mkdir($logDir, 0755, true);
```

## Fix

Changed to `0750` (`rwxr-x---`):

```php
mkdir($logDir, 0750, true);
```

- **Owner (7):** Full access — web server process writes logs
- **Group (5):** Read + execute — ops/admin group reads for monitoring
- **Other (0):** No access — other system users blocked

### Why Not 0700?

Ops teams need log access for monitoring (Prometheus exporters, log collectors, fail2ban). `0750` allows the web server's group to read without opening to the world.

### Why Not chmod Existing Directories?

Existing directories may have been set to `0755` deliberately by an admin (e.g., for a log collector with a different user). We respect existing permissions — only newly created directories get `0750`.

## Files Modified (1)

- `payment-component/src/Service/FileLogger.php`
  - Changed `mkdir($logDir, 0755, true)` to `mkdir($logDir, 0750, true)`

## Files Created (1)

### Tests
- `payment-component/tests/Unit/Service/FileLoggerPermissionsTest.php`
  - Uses `sys_get_temp_dir()` for isolated filesystem tests
  - Cleans up in `tearDown()`

## Test Results

```
Tests: 3, Assertions: 5, Failures: 0
```

| # | Test | Scenario | Expected |
|---|------|----------|----------|
| 1 | `logDirectoryCreatedWithRestrictivePermissions` | Log to non-existent dir | Dir perms = `0750` |
| 2 | `logFileCreatedInsideRestrictiveDirectory` | Write log entry | Directory blocks other-user access (`perms & 0007 === 0`) |
| 3 | `existingDirectoryNotModified` | Dir exists with 0755 | Perms unchanged (0755 preserved) |

## PCI DSS Compliance

- **Req. 3.4:** Restrict access to cardholder data on a need-to-know basis
- **Req. 7.1:** Limit access to system components and cardholder data to those who need it
- **Req. 10.5:** Secure audit trails so they cannot be altered

## SOLID Compliance

- **S**: One-line change, one concern (directory permissions)
- **O**: No behavioral changes to logging logic
- **L**: `FileLogger` API unchanged
- **I**: No new interfaces
- **D**: No new dependencies
