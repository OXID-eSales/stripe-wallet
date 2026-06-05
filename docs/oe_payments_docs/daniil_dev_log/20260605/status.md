# 2026-06-05 — Status

## Sprints 120 + 121 (STRP-129 follow-ups): admin Payment-tab input validation — DONE

- **Sprint 120** (`b-7.4.x-STRP-129-capture-reason`, 5 commits): `capture_reason`
  char-level gate pre-dispatch, session-backed feedback channel, panel alert,
  admin-lang messages (en/de). Plans + completion in `sprints/` / `done/`.
- **Sprint 121** (`b-7.4.x-STRP-129-admin-amounts`, 5 commits, branched off 120):
  `AdminAmountValidator` kills the malformed→null→FULL-capture footgun;
  PI/charge-derived bounds (fail-closed); `refund_description` gate;
  `processRefundByCharge` reason-whitelist bypass fixed; non-positive guards
  in Capture/RefundService.

## Numbers

Unit suite: 1158/2783 (base) → 1180/2842 (S120) → **1243/3028** (S121).
Integration standalone: 87 OK. PHPCS/PHPStan(max)/PHPMD: clean, baseline
unchanged (3 entries), zero new suppressions.

## Open

1. Manual browser verify (en+de) of the panel alerts — caches cleared, ready.
2. `ModuleLifecycleTest` combined-suite errors are PRE-EXISTING (reproduce on
   clean `b-7.4.x`, 4 errors there vs 3 here).
3. Playwright admin specs deferred (CI manual-only).
4. Old STRP-145 stashes (`stash@{0}`, `stash@{1}`) left untouched in the repo.
