# Sprint 81d: Phase 4 — Execution, Validation & Analysis

**Parent:** Sprint 81 — High-Load Testing
**Date:** 2026-04-02
**Status:** todo

## Objective

Execute the load test suite in progressive stages (dry-run → baseline → full → endurance), validate results against thresholds, analyze bottlenecks, and document findings.

## Principles

- **TDD** — Thresholds are the "test"; k6 output is "red" or "green"
- **DevOps First** — Every run is a CI job with artifacts, not a local one-off
- **Measure twice, cut once** — Baseline before full load; never skip dry-run

## Subtasks

### 4.1 — Dry-Run Validation

**Goal:** Verify each scenario executes end-to-end with 1 VU, 1 iteration.

| # | Task | Status | How to Run |
|---|------|--------|------------|
| 4.1.1 | Trigger workflow: `dry_run=true`, `scenario=happy_path` | todo | GitHub Actions → Run workflow → dry_run: true, scenario: happy_path |
| 4.1.2 | Verify: browser launches, login works, products added | todo | Check k6 console output in artifact |
| 4.1.3 | Verify: Stripe Checkout page reached, card filled | todo | Check k6 console output — no selector errors |
| 4.1.4 | Verify: 3DS handled, thank you page reached | todo | `orders_created` counter = 1 |
| 4.1.5 | Trigger workflow: `dry_run=true`, `scenario=cancellation` | todo | Verify back navigation works |
| 4.1.6 | Trigger workflow: `dry_run=true`, `scenario=guest_coupon` | todo | Verify coupon applied, user step reached |
| 4.1.7 | Trigger workflow: `dry_run=true`, `scenario=payment_failure` | todo | Verify declined error shown, retry succeeds |
| 4.1.8 | Trigger workflow: `dry_run=true`, `scenario=threeds` | todo | Verify 3DS iframe interaction works |
| 4.1.9 | Trigger workflow: `dry_run=true`, `scenario=all` | todo | All 5 scenarios run 1 iteration each |
| 4.1.10 | Fix any selector mismatches or timing issues | todo | Compare error output against Playwright Helper.ts selectors |

**Expected issues:**
- Timing differences between Playwright (`waitForLoadState('networkidle')`) and k6 browser (simpler waits)
- Selector differences if `pay1.oxid.dev` has a different theme version than local
- Cookie consent banner variations

**Pass criteria:** All 5 scenarios complete without errors in dry-run mode.

### 4.2 — Baseline Run (10 VU/min, 2 min)

**Goal:** Establish performance baseline with minimal load.

| # | Task | Status | How to Run |
|---|------|--------|------------|
| 4.2.1 | Trigger workflow: `target_vus=10`, `duration=2`, `scenario=all` | todo | 13 iterations over ~3 min total |
| 4.2.2 | Record baseline metrics | todo | Extract from k6 JSON artifact |
| 4.2.3 | Verify all thresholds pass at low load | todo | All green in k6 summary |
| 4.2.4 | Record baseline p95 checkout_duration | todo | Expected: < 30s |
| 4.2.5 | Verify post-validation: 0 orphan orders, 0 stuck contracts | todo | post-validation job passes |

**Baseline metrics to record:**

| Metric | Expected (10 VU/min) | Record Actual |
|--------|---------------------|---------------|
| `checkout_duration` p50 | < 15s | _____ |
| `checkout_duration` p95 | < 30s | _____ |
| `checkout_success_rate` | > 95% | _____ |
| `browser_http_req_failed` | < 1% | _____ |
| `orders_created` | ~4-5 (10 VU × 2 min × 40% happy + 15% retry) | _____ |
| PHP memory peak | stable | _____ |
| MySQL connections | < 20 | _____ |

### 4.3 — Full Load Run (100 VU/min, 10 min)

**Goal:** Primary load test — validate system under target load.

| # | Task | Status | How to Run |
|---|------|--------|------------|
| 4.3.1 | Trigger workflow: `target_vus=100`, `duration=10`, `ramp_up=2` | todo | ~1000 iterations over 13 min |
| 4.3.2 | Monitor pay1.oxid.dev during run | todo | SSH: `watch -n 5 'show processlist'`, `top`, `tail -f error.log` |
| 4.3.3 | Record all metrics | todo | Extract from k6 JSON artifact |
| 4.3.4 | Analyze threshold pass/fail | todo | Focus on any RED thresholds |
| 4.3.5 | Review post-validation results | todo | Orphan orders, stuck contracts, coupons |
| 4.3.6 | Compare against baseline (4.2) | todo | Identify degradation patterns |

**Expected results at 100 VU/min:**

| Metric | Threshold | Expected |
|--------|-----------|----------|
| `checkout_success_rate` | > 90% | 85-95% (some timeouts expected) |
| `checkout_duration` p95 | < 60s | 30-50s |
| `contract_state_valid` | == 100% | 100% |
| `stripe_api_errors` | < 10 | 0-5 |
| `orders_created` | ~400-500 | depends on success rate |
| Orphan orders | 0 | 0 |
| Double-used coupons | 0 | 0 (critical if > 0) |

**If thresholds fail:**

| Symptom | Likely Cause | Investigation |
|---------|-------------|---------------|
| `checkout_duration` p95 > 60s | PHP-FPM worker starvation | Check `pm.max_children`, increase if < 20 |
| `browser_http_req_failed` > 5% | MySQL connection exhaustion | Check `max_connections`, `SHOW PROCESSLIST` |
| `checkout_success_rate` < 80% | Session race conditions | Check PHP session lock contention |
| `stripe_api_errors` > 10 | Stripe rate limit (429) | Check Stripe Dashboard → Developers → Logs |
| Orphan orders > 0 | Contract-order link broken | Check `oe_payments_contract.OXORDERID` for NULLs |
| Double-used coupons > 0 | Coupon `SELECT ... FOR UPDATE` deadlock | Check MySQL deadlock log, add row-level locking |

### 4.4 — Endurance Run (100 VU/min, 30 min)

**Goal:** Detect memory leaks, connection pool exhaustion, and gradual degradation.

| # | Task | Status | How to Run |
|---|------|--------|------------|
| 4.4.1 | Trigger workflow: `target_vus=100`, `duration=30`, `ramp_up=3` | todo | ~3000 iterations over 34 min |
| 4.4.2 | Monitor PHP memory over time | todo | `memory_get_peak_usage()` — should be flat, not increasing |
| 4.4.3 | Monitor MySQL connections over time | todo | `SHOW PROCESSLIST` count — should be stable |
| 4.4.4 | Monitor disk usage (session files) | todo | `du -sh /tmp/sess_*` — should not grow unbounded |
| 4.4.5 | Compare p95 at minute 5 vs minute 25 | todo | Detect latency creep |
| 4.4.6 | Check for MySQL slow queries | todo | `SHOW GLOBAL STATUS LIKE 'Slow_queries'` before/after |
| 4.4.7 | Review error log for new warnings | todo | `tail -100 error_log.txt` after run |

**Key degradation indicators:**

| Indicator | Healthy | Unhealthy |
|-----------|---------|-----------|
| PHP memory peak | Flat (~50-100MB per worker) | Increasing over time |
| MySQL connections | Stable (< max_connections) | Grows, approaches limit |
| p95 latency over time | Stable or decreasing | Increases beyond minute 15 |
| Error rate over time | Constant low rate | Increases in later minutes |
| Disk (sessions) | Stable | Growing |

### 4.5 — Results Analysis & Report

| # | Task | Status | Details |
|---|------|--------|---------|
| 4.5.1 | Collect all k6 artifacts from 4.2-4.4 | todo | Download from GitHub Actions artifacts |
| 4.5.2 | Create comparison table: baseline vs full vs endurance | todo | Side-by-side metrics |
| 4.5.3 | Identify bottlenecks (confirm/reject hypotheses) | todo | See hypothesis list below |
| 4.5.4 | Document Stripe-specific findings | todo | Rate limits hit? Webhook delays? |
| 4.5.5 | Write recommendations | todo | Config changes, code fixes, architecture improvements |
| 4.5.6 | Create report in `done/` folder | todo | `20260402/done/load-test-report.md` |

**Hypotheses to validate:**

| # | Hypothesis | Validated? | Finding |
|---|-----------|-----------|---------|
| H1 | MySQL `oe_payments_contract` row-level locking causes deadlocks under concurrent state transitions | todo | |
| H2 | PHP-FPM worker starvation when Stripe API calls block workers | todo | |
| H3 | Session race conditions when same user has concurrent requests | todo | |
| H4 | Single-use coupon `SELECT ... FOR UPDATE` deadlocks under concurrency | todo | |
| H5 | `OXBASKETDATA` JSON serialization overhead with large baskets | todo | |
| H6 | Stripe webhook delivery delay increases under load (> 30s) | todo | |

## Execution Schedule

| Run | When | Duration | Purpose |
|-----|------|----------|---------|
| Dry-run (all) | First | ~5 min | Validate selectors work |
| Baseline (10 VU) | After dry-run passes | ~3 min | Establish reference metrics |
| Full (100 VU) | After baseline passes | ~13 min | Primary load test |
| Endurance (100 VU) | After full passes | ~34 min | Memory/connection leak detection |

**Total estimated time:** ~1 hour including review between runs.

## CI Trigger Cheatsheet

```bash
# Dry-run
gh workflow run load-test.yml -f dry_run=true -f scenario=all

# Baseline
gh workflow run load-test.yml -f target_vus=10 -f duration=2 -f scenario=all

# Full load
gh workflow run load-test.yml -f target_vus=100 -f duration=10 -f ramp_up=2 -f scenario=all

# Endurance
gh workflow run load-test.yml -f target_vus=100 -f duration=30 -f ramp_up=3 -f scenario=all

# Single scenario debug
gh workflow run load-test.yml -f target_vus=10 -f duration=2 -f scenario=happy_path
```
