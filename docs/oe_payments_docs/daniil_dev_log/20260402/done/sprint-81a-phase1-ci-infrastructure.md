# Sprint 81a: Phase 1 — CI Infrastructure & Foundation

**Parent:** Sprint 81 — High-Load Testing
**Date:** 2026-04-02
**Status:** done

## Objective

Set up the CI pipeline and k6 infrastructure so load tests can be triggered manually via GitHub Actions, with pre-flight safety checks and post-run DB validation.

## Principles

- **DevOps First** — CI pipeline created before any test logic; every run is reproducible
- **TDD** — Thresholds (pass/fail criteria) defined before scenarios are implemented
- **DIP** — All config injected via environment variables, not hardcoded

## Subtasks

| # | Task | File(s) | Status | Acceptance Criteria |
|---|------|---------|--------|---------------------|
| 1.1.1 | Create workflow file with `workflow_dispatch` trigger | `.github/workflows/load-test.yml` | done | Workflow appears in GitHub Actions "Run workflow" dropdown |
| 1.1.2 | Add input parameters: `target_vus`, `duration`, `ramp_up`, `scenario`, `dry_run` | `.github/workflows/load-test.yml` | done | Each input has type, default, and description |
| 1.1.3 | Add `pre-flight` job: HTTP health check on `pay1.oxid.dev` | `.github/workflows/load-test.yml` | done | Job fails if shop returns HTTP >= 400 |
| 1.1.4 | Add `pre-flight` job: Stripe test mode verification via SSH | `.github/workflows/load-test.yml` | done | Job fails if `getStripeMode() !== 'test'` — **never load-test live keys** |
| 1.1.5 | Add `seed-data` job: create 200 test users idempotently | `.github/workflows/load-test.yml` | done | `loadtest_user_001..200@oxid-esales.dev` exist in `oxuser` |
| 1.1.6 | Add `seed-data` job: set product stock to 99999 | `.github/workflows/load-test.yml` | done | `UPDATE oxarticles SET OXSTOCK = 99999 WHERE OXACTIVE = 1` |
| 1.1.7 | Add `load-test` job: install k6 binary + Chromium deps | `.github/workflows/load-test.yml` | done | `k6 version` prints, Chromium libs installed |
| 1.1.8 | Add `load-test` job: run k6 with env vars from inputs | `.github/workflows/load-test.yml` | done | k6 reads `K6_TARGET_VUS`, `K6_DURATION`, etc. |
| 1.1.9 | Add `load-test` job: export results as artifact | `.github/workflows/load-test.yml` | done | `k6-results-{run_number}` artifact with JSON + console output |
| 1.1.10 | Add `post-validation` job: orphan orders query | `.github/workflows/load-test.yml` | done | Query runs via SSH, fails if orphan count > 0 |
| 1.1.11 | Add `post-validation` job: stuck contracts query | `.github/workflows/load-test.yml` | done | Contracts in draft/pending > 5 min reported |
| 1.1.12 | Add `post-validation` job: double-used coupons query | `.github/workflows/load-test.yml` | done | Fails if single-use coupon used > 1 time |
| 1.1.13 | Add `post-validation` job: contract state distribution | `.github/workflows/load-test.yml` | done | Prints fulfilled/cancelled/expired/failed counts |
| 1.1.14 | Add job summary with parameters table | `.github/workflows/load-test.yml` | done | Markdown table in `$GITHUB_STEP_SUMMARY` |
| 1.2.1 | Create `k6.config.js` with dynamic scenario builder | `tests/load/k6.config.js` | done | `buildScenario()` reads env, supports single or all scenarios |
| 1.2.2 | Define custom k6 metrics | `tests/load/k6.config.js` | done | `checkout_success_rate`, `contract_state_valid`, `stripe_api_errors`, `checkout_duration`, `orders_created` |
| 1.2.3 | Define thresholds (TDD: red before green) | `tests/load/k6.config.js` | done | `checkout_success_rate > 0.90`, `contract_state_valid == 1.0`, etc. |
| 1.2.4 | Wire `K6_BROWSER_ENABLED` for Chromium mode | `tests/load/k6.config.js` | done | Each scenario has `options: { browser: { type: 'chromium' } }` |
| 1.2.5 | Implement `dry_run` mode (1 VU, 1 iteration) | `tests/load/k6.config.js` | done | `per-vu-iterations` executor when `K6_DRY_RUN=true` |

## Job Dependency Graph

```
pre-flight ──→ seed-data ──→ load-test ──→ post-validation
   │                            │               │
   ├─ HTTP health check         ├─ Install k6   ├─ Orphan orders
   └─ Stripe test mode verify   ├─ Install Chromium  ├─ Stuck contracts
                                ├─ Run k6 browser    ├─ Double-used coupons
                                └─ Upload artifacts  └─ State distribution + summary
```

## Secrets Required

All already exist from `playwright-ci.yml`:

| Secret | Used In | Purpose |
|--------|---------|---------|
| `SHOP_HOST` | SSH connection | `pay1.oxid.dev` hostname |
| `SHOP_SSH_USER` | SSH connection | SSH username |
| `SHOP_SSH_KEY` | SSH connection | SSH private key |
| `TEST_USER_PASSWORD` | k6 env var | Login password for test users |

## Safety Guardrails

1. **Manual trigger only** — never auto-runs on push/PR
2. **Stripe test mode check** — pipeline aborts if Stripe is in live mode
3. **Shop health check** — pipeline aborts if shop is down
4. **Post-validation always runs** — even if k6 fails, DB checks execute
5. **Idempotent seeding** — users only created if they don't exist

## Files Created

```
.github/workflows/load-test.yml    # 4 jobs, ~180 lines
tests/load/k6.config.js            # Scenario builder + browser helpers, ~450 lines
tests/load/scenarios/               # (empty, scenarios are inline in k6.config.js)
tests/load/helpers/                 # (empty, helpers are inline in k6.config.js)
tests/load/validation/              # (empty, validation is inline in workflow)
```

## Notes

- Scenarios and helpers are currently inline in `k6.config.js` for simplicity. If the file grows beyond 500 lines, extract to separate modules using k6's ES module imports.
- Validation queries are inline in the workflow YAML. If we add more queries, extract to `tests/load/validation/` SQL files and a runner script.
