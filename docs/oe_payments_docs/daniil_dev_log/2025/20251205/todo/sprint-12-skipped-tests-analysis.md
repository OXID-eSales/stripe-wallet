# Sprint 12: Skipped & Incomplete Integration Tests Analysis

**Date:** 2025-12-05
**Status:** IN PROGRESS
**Branch:** b-7.4.x-auth-STRP-70

---

## Summary

Integration test run shows **67 skipped** and **1 incomplete** test out of 306 total tests.

```
Tests: 306, Assertions: 1098, Skipped: 67, Incomplete: 1
```

---

## Incomplete Tests (1)

### 1. StripeAdapterIntegrationTest::testRefundsPaymentPartialAmount

**File:** `tests/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php`
**Status:** INCOMPLETE
**Reason:** TBD - likely marked incomplete during development

---

## Skipped Tests by Category

### Category 1: DoctrineContractRepositoryTest (1 test)

| Test | Reason |
|------|--------|
| `testTransactionRollback` | TBD |

**File:** `tests/Integration/Component/Repository/DoctrineContractRepositoryTest.php`

---

### Category 2: MigrationStructureTest (11 tests)

All related to PaymentWatch index verification:

| Test | Index Being Tested |
|------|-------------------|
| `testWebhookLogsTableExists` | Table existence |
| `testContractTableHasPaymentWatchStateIndex` | OXSTATE index |
| `testContractTableHasPaymentWatchProviderOrderIndex` | OXPROVIDERORDERID index |
| `testContractTableHasPaymentWatchOrderIndex` | OXORDERID index |
| `testContractTableHasPaymentWatchUserIndex` | OXUSERID index |
| `testContractTableHasPaymentWatchCompositeIndex` | Composite index |
| `testTransactionTableHasPaymentWatchStatusIndex` | Status index |
| `testTransactionTableHasPaymentWatchContractIndex` | Contract FK index |
| `testTransactionTableHasPaymentWatchProviderOrderIndex` | Provider order index |
| `testTransactionTableHasPaymentWatchTypeIndex` | Type index |
| `testTransactionTableHasPaymentWatchCompositeIndex` | Composite index |

**File:** `tests/Integration/Database/MigrationStructureTest.php`
**Likely Reason:** PaymentWatch feature not yet merged or indexes not created in test DB

---

### Category 3: ModuleLifecycleTest (6 tests)

| Test | Dependency |
|------|------------|
| `testModuleCanBeActivated` | Base test |
| `testModuleCanBeDeactivated` | Depends on activation |
| `testModuleCanBeReactivatedAfterDeactivation` | Depends on deactivation |
| `testModuleIdIsCorrect` | Module metadata |
| `testServicesAvailableAfterActivation` | Depends on activation |
| `testMultipleActivationDeactivationCycles` | Full cycle |

**File:** `tests/Integration/Module/ModuleLifecycleTest.php`
**Likely Reason:** Module not activated in CI test environment

---

### Category 4: Watch Feature Tests (49 tests)

These are all related to the **PaymentWatch** feature (external monitoring API).

#### AssumptionControllerIntegrationTest (13 tests)
**File:** `tests/Integration/Watch/Controller/AssumptionControllerIntegrationTest.php`

| Test | Purpose |
|------|---------|
| `it_returns_successful_response_for_valid_request` | Basic API functionality |
| `it_returns_false_when_value_does_not_match` | Negative matching |
| `it_supports_all_comparison_operators` | `=`, `!=`, `>`, `<`, etc. |
| `it_supports_like_operators` | `LIKE`, `NOT LIKE` |
| `it_supports_null_check_operators` | `IS NULL`, `IS NOT NULL` |
| `it_returns_401_for_missing_api_key` | Auth validation |
| `it_returns_400_for_invalid_json` | Input validation |
| `it_returns_400_for_sql_injection_attempt` | Security |
| `it_handles_multiple_where_conditions` | Complex queries |
| `it_returns_false_when_row_not_found` | Missing data |
| `it_includes_query_time_in_response` | Performance metrics |
| `it_includes_request_id_in_response_header` | Tracing |

#### CompletePaymentFlowTest (6 tests)
**File:** `tests/Integration/Watch/EndToEnd/CompletePaymentFlowTest.php`

| Test | Purpose |
|------|---------|
| `it_tracks_complete_payment_flow_from_pending_to_committed` | Happy path |
| `it_handles_failed_payment_flow` | Failure handling |
| `it_handles_expired_contract_timeout` | Timeout handling |
| `it_handles_refund_flow` | Refund tracking |
| `it_tracks_concurrent_payments_for_different_users` | Concurrency |
| `it_validates_state_transitions_in_correct_order` | State machine |

#### PerformanceBenchmarkTest (7 tests)
**File:** `tests/Integration/Watch/Performance/PerformanceBenchmarkTest.php`

| Test | Purpose |
|------|---------|
| `it_responds_within_50ms_on_average` | Response time |
| `it_handles_concurrent_requests_efficiently` | Concurrency |
| `it_performs_well_with_complex_where_clauses` | Query complexity |
| `it_performs_well_with_like_operators` | LIKE performance |
| `it_has_minimal_memory_footprint` | Memory usage |
| `it_scales_linearly_with_data_volume` | Scalability |
| `it_measures_database_query_overhead` | DB overhead |

#### SecurityValidationTest (23 tests)
**File:** `tests/Integration/Watch/Security/SecurityValidationTest.php`

| Test | Purpose |
|------|---------|
| `it_blocks_sql_injection_attempts` (14 data sets) | SQL injection protection |
| `it_prevents_timing_attacks_on_api_key_validation` | Timing attack prevention |
| `it_blocks_requests_with_sql_keywords_in_table_names` | Keyword blocking |
| `it_sanitizes_api_key_in_logs` | Log sanitization |
| `it_prevents_parameter_pollution` | Parameter validation |
| `it_limits_request_size_to_prevent_dos` | DoS protection |
| `it_prevents_unicode_bypass_attempts` | Unicode security |
| `it_validates_operator_whitelist_strictly` | Operator validation |
| `it_prevents_cross_table_joins_or_subqueries` | Query scope |
| `it_enforces_https_in_production` | Transport security |
| `it_includes_security_headers_in_response` | Security headers |

---

## Root Cause Analysis

### Why Tests Are Skipped

1. **PaymentWatch Feature Not Active**: The Watch tests (49 total) are for a monitoring API feature that may:
   - Not be merged yet
   - Require specific configuration
   - Need API key setup in `.env`

2. **Module Not Activated in CI**: ModuleLifecycleTest (6 tests) requires the module to be activated via `bin/oe-console`, which CI skips to test isolated code.

3. **Migration Not Run**: MigrationStructureTest (11 tests) checks for PaymentWatch indexes that may not exist in the test database.

4. **Transaction Rollback Test**: DoctrineContractRepositoryTest needs specific DB transaction support.

---

## Recommendations

### Option A: Enable All Tests (Full Coverage)

1. Run migrations before tests in CI
2. Activate module before running ModuleLifecycle tests
3. Configure PaymentWatch API key in CI secrets

### Option B: Separate Test Suites (Current Approach)

1. Keep current behavior - core tests pass
2. Create separate CI job for "full integration" with module activated
3. Run Watch tests only when Watch feature is enabled

### Option C: Mark Tests as Conditional

1. Use `@group watch` annotation for Watch tests
2. Use `@group module-active` for activation-dependent tests
3. Run specific groups based on CI configuration

---

## Next Steps

1. [ ] Investigate why `testRefundsPaymentPartialAmount` is incomplete
2. [ ] Determine if PaymentWatch feature should be enabled in CI
3. [ ] Create test suite matrix for different configurations
4. [ ] Document test requirements in test files

---

## Test Summary Table

| Category | Count | Status | Action Needed |
|----------|-------|--------|---------------|
| Stripe Adapter | 1 | Incomplete | Investigate |
| Contract Repository | 1 | Skipped | Check transaction support |
| Migration Structure | 11 | Skipped | Run PaymentWatch migrations |
| Module Lifecycle | 6 | Skipped | Activate module in CI |
| Watch Controller | 13 | Skipped | Configure Watch feature |
| Watch E2E | 6 | Skipped | Configure Watch feature |
| Watch Performance | 7 | Skipped | Configure Watch feature |
| Watch Security | 23 | Skipped | Configure Watch feature |
| **Total Skipped** | **67** | | |
| **Total Incomplete** | **1** | | |
