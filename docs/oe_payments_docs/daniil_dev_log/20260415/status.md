# Status — 2026-04-15

## Sprint 88: STRP-123 Keep Cancelled Orders (No Gaps in Order Numbers)

### Problem

`RetryCleanupService` deleted `NOT_FINISHED` orders when checkout was cancelled/abandoned, creating gaps in `oxordernr` sequence.

### Root Cause (Two-Part)

1. **Order deletion** — `$order->delete()` removed the row entirely
2. **`sess_challenge` collision** — storno'd order stayed in DB, OXID's `checkOrderExist()` matched the old `sess_challenge`, `finalizeOrder()` returned `ORDER_STATE_ORDEREXISTS` skipping all data copying → empty order shell

### Fix

| Change | File | What |
|--------|------|------|
| Storno instead of delete | `OxidShopOrderService.php` | `OXSTORNO=1, OXTRANSSTATUS=CANCELLED` instead of `$order->delete()` |
| Regenerate sess_challenge | `StripeOrderController.php` | New UUID in `checkoutCancel()` + `cleanupStaleCheckoutOnRender()` |
| Test updates | 2 test files | Updated assertions for STORNO+CANCELLED and new sess_challenge |

### Verification (Live)

| Order | Status | Total | User | Result |
|-------|--------|-------|------|--------|
| 625 | CANCELLED+STORNO | 842.97 | Marc | Full data preserved |
| 626 | CANCELLED+STORNO | 842.97 | Marc | Full data preserved |
| 627 | OK | 842.97 | Marc | Completed successfully |
| 628 | CANCELLED+STORNO | 547.10 | Marc | Different basket — data preserved |
| 629 | OK | 547.10 | Marc | Completed successfully |

### Sub-Sprint Progress

| Sprint | Description | Status | Notes |
|--------|-------------|--------|-------|
| 88a | Analysis | done | `deleteNotFinishedOrder` uses `oxNew()` — not unit-testable |
| 88b | Storno implementation | done | One line: delete → STORNO + CANCELLED |
| 88c | Pre-commit | done | Integration test updated |
| 88-hotfix | Empty order bug | done | `sess_challenge` not regenerated after cancel → `ORDER_STATE_ORDEREXISTS` |
| 88-verify | Live verification | done | Orders 625-629 confirmed: no empty orders, no gaps |

### Status: DONE — COMMITABLE
