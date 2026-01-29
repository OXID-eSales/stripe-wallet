# Sprint 1: AUTHORIZED State - Completion Report

**Status:** COMPLETED
**Date:** 2025-12-16
**Duration:** ~30 minutes

---

## Summary

Added the `AUTHORIZED` state to the contract state machine to support delayed/manual capture workflow.

---

## Changes Made

### 1. ContractState.php

**File:** `src/Component/Contract/ContractState.php`

- Added `'authorized'` to `VALID_STATES` array
- Added `authorized()` factory method
- Added `isAuthorized()` checker method

### 2. PaymentContract.php

**File:** `src/Component/Contract/PaymentContract.php`

- Added `authorize()` method - transitions from PENDING to AUTHORIZED
- Added `captureAuthorization()` method - transitions from AUTHORIZED to READY_TO_COMMIT
- Both methods include proper validation and touch() calls

### 3. Unit Tests

**Files:**
- `tests/Unit/Component/Contract/ContractStateTest.php` - 5 new tests
- `tests/Unit/Component/Contract/PaymentContractTest.php` - 10 new tests

---

## Test Results

```
PHPUnit 11.5.44
Tests: 1364, Assertions: 3243
Status: OK (with deprecation warnings - pre-existing)
```

### New Tests Added

**ContractStateTest:**
- `testAuthorizedFactory`
- `testAuthorizedStateIsNotTerminal`
- `testAuthorizedStateEquality`
- `testAuthorizedFromValue`
- `testAuthorizedToString`
- Updated `testTerminalStates` to include authorized

**PaymentContractTest:**
- `testAuthorizeTransitionsFromPendingToAuthorized`
- `testAuthorizeThrowsExceptionForDraftContract`
- `testAuthorizeThrowsExceptionForReadyToCommitContract`
- `testCaptureAuthorizationTransitionsToReadyToCommit`
- `testCaptureAuthorizationThrowsExceptionForNonAuthorizedContract`
- `testCaptureAuthorizationThrowsExceptionForDraftContract`
- `testFullManualCaptureFlow`
- `testCancelFromAuthorizedState`
- `testExpireFromAuthorizedState`
- `testToArrayIncludesAuthorizedState`
- `testFromArrayRestoresAuthorizedState`

---

## Code Quality

| Check | Status |
|-------|--------|
| PHPUnit Unit Tests | PASS (1364 tests) |
| PHPStan Level 6 | PASS |
| PHPMD | WARNING (pre-existing class complexity) |

**Note:** PHPMD warnings about `PaymentContract` having too many methods are pre-existing issues. The class already had 44 public methods before; adding 2 new methods pushed it to 46.

---

## State Machine Update

### Before

```
DRAFT → PENDING → READY_TO_COMMIT → COMMITTED → FULFILLED
```

### After

```
DRAFT → PENDING → AUTHORIZED → READY_TO_COMMIT → COMMITTED → FULFILLED
            │
            └──────────────────→ READY_TO_COMMIT (automatic capture path)
```

---

## Commands Used

```bash
# Run unit tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Unit

# Run specific tests
docker compose exec php php vendor/bin/phpunit -c extensions/stripe/tests/phpunit.xml \
  extensions/stripe/tests/Unit/Component/Contract/ContractStateTest.php

# Pre-commit checks
./bin/pre-commit-check.sh
```

---

## Next Sprint

Sprint 2: Add module configuration setting (`sStripeCaptureMode`)
