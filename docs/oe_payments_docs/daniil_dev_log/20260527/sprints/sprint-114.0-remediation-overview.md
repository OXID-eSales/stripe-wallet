# Sprint 114.0 — Code-Review 114 Remediation: Overview & Sequencing

**Module:** `extensions/stripe`
**Source:** `docs/oe_payments_docs/daniil_dev_log/20260527/reports/114-code-review.md`
**Mode:** umbrella plan — 13 separated sub-sprints (114.1 … 114.13), each its own branch + commit(s), each green on `./bin/pre-commit-check.sh --full`.
**Numbering:** sub-sprints of review 114 (mirrors the existing `sprint-102.1…102.5` convention).
**Engineering requirements:** see [`_engineering_requirements.md`](./_engineering_requirements.md) — guards **R-1…R-10** (TDD · SOLID · LI · DI · Clean Code · DevOps-first · Event-driven · Contract-aware · No-overengineering · Persistence: events write / reads direct) are **binding** on every sub-sprint.

## 1. Why

Code review 114 found 2 latent functional/security bugs, ~600 LOC of
dead/duplicate code, a currency-conversion correctness hazard duplicated
across 14 files, and a leaking provider-agnostic boundary. This umbrella
sprint sequences the fixes so they ship in safe, independently-revertable
slices, each TDD-first.

Every sub-sprint follows the same discipline: **RED** (failing test that
encodes the finding) → **GREEN** (minimal fix) → **REFACTOR** → all four
gates (PHPCS, PHPStan level max, PHPMD, PHPUnit Unit+Integration) green.

## 2. Sub-sprint map

| Sprint | Pri | Title | Findings | Depends on |
|--------|-----|-------|----------|-----------|
| 114.1 | **P0** | Fix hardcoded `shopId: 1` in transaction audit | C1 | — |
| 114.2 | **P0** | Fix `validateDeliveryAddress()` Stripe bypass + real test | L1, T2 | — |
| 114.3 | **P0** | Security tests must exercise the real SUT | T1 | — |
| 114.4 | **P1** | Unify webhook dispatch (tagged registry) + delete dead handlers | O1, S1 | — |
| 114.5 | **P1** | Dead-code sweep (services, validator, events, mapper, model) | O2, O4, O5, O6, O7, O8, O9, O10 | 114.4 |
| 114.6 | **P1** | Remove redundant `LazyStripeAdapter`, rewire to factory | O3, L2 | — |
| 114.7 | **P2** | `AmountConverter` — centralize cents math (incl. JPY/KRW) | D1 | — |
| 114.8 | **P2** | DRY the Stripe request handlers (base + recorder + idempotent executor) | D3, D4, D5 | 114.1, 114.4 |
| 114.9 | **P2** | Consolidate config/session helpers | D2, D6, D7, D8, D9 | — |
| 114.10 | **P3** | Provider-agnostic boundary: map Stripe SDK types to DTOs | A1, A2, A3, A4, L3 | 114.7 |
| 114.11 | **P3** | SRP / DIP cleanups | S2, S3, S4, S5, S6, S7 | 114.6, 114.10 |
| 114.12 | **P3** | Clean-code pass + status/mode constants | C2, C3, C4, C5, C6, C7, C8 | 114.7 |
| 114.13 | **P3** | Close coverage gaps + test hygiene | §8, T3, T4, T5, T6 | 114.4, 114.5 |

## 3. Recommended execution order

1. **P0 first, in parallel** (114.1, 114.2, 114.3) — independent, no shared files. These are correctness/security; merge before anything else.
2. **P1 next** — 114.4 (webhook unify) before 114.5 (dead-code sweep) because 114.5 deletes handlers that 114.4 may re-home. 114.6 is independent.
3. **P2** — 114.7 (AmountConverter) is a prerequisite for 114.10 and 114.12 (both consume the new converter / constants). 114.8 depends on 114.1 (shopId now injected) and 114.4 (handler set finalized). 114.9 independent.
4. **P3** — structural; 114.10 → 114.11; 114.12 and 114.13 last.

## 4. Global guardrails (apply to every sub-sprint)

> **Canonical source:** [`_engineering_requirements.md`](./_engineering_requirements.md) (R-1…R-10) is binding. The bullets below are the highlights.

- **TDD non-negotiable:** the failing test is committed (or at least shown red) before the fix. Re-use the correct seam-only testable-subclass pattern at `tests/Unit/Stripe/Controller/StripeOrderControllerTest.php:636` — never re-implement the method under test.
- **No new static-analysis suppressions.** Per project rule, fix the code; suppress only OXID-core patterns (oxNew/Registry/virtual parents). Do not raise PHPMD thresholds.
- **Mock interfaces, not concretions.** Replace `createMock(PaymentContract::class)` with `PaymentContractInterface`; build real value objects (`BasketSnapshot`, `Transaction`) instead of mocking them.
- **No edits under `vendor/`** or generated `var/configuration/...` — edit `metadata.php` / `services.yaml` / `extensions/` canonically.
- **payment-base edits are PERMITTED** (user-confirmed 2026-05-27) for sprints that need them (notably 114.10, optionally 114.8). They must be **additive / backwards-compatible** and keep the sibling consumers **paypal** and **one-page-checkout** green — run their suites, not just Stripe's. (114.4 deliberately did NOT need a payment-base change and stays stripe-local.)
- **Agnostic boundary:** no new `'stripe'` / payment-id / status literals — use `StripeDefinitions` / `StripeStatusMapper`.
- **Baseline check:** record before/after `Tests / Assertions` counts in each sub-sprint's completion report; net assertions must not drop (deleting dead code may drop *tests*, which is expected and must be justified).

## 5. Definition of Done (umbrella)

- All 13 sub-sprints merged; each has a completion report in `done/`.
- `./bin/pre-commit-check.sh --full` green on the integration branch.
- Report 114 findings table annotated: every H/M/L marked Fixed / Won't-fix (with rationale).
- Memory `project_code_review_114_latent_bugs.md` updated/retired once 114.1 + 114.2 land.
- No net loss of meaningful coverage; deleted tests justified in the relevant report.
