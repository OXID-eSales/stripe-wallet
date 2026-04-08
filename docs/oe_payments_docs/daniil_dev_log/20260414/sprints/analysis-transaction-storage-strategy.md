# Differential Analysis: Transaction Storage Strategy

**Date:** 2026-04-14
**Context:** Enterprise 5-star payment platform — Stripe module for OXID eShop 7.4+

## Options

| Option | Summary |
|--------|---------|
| **A** | Stripe API is sole source of truth. No local DB recording. Fetch on demand. |
| **B** | Local DB as audit log + Stripe API for live display. Write on every event. |
| **C** (hybrid) | Local DB as primary read source, Stripe API as reconciliation fallback. Write on every event, read from DB first, reconcile periodically. |

---

## Dimension 1: Data Sovereignty & Compliance

| Criteria | Option A (API only) | Option B (DB audit log) | Option C (DB primary + reconciliation) |
|----------|---------------------|------------------------|-----------------------------------------|
| PCI DSS audit trail | Stripe holds it — out of your control | Local audit trail you own | Full audit trail you own + reconciliation proof |
| GDPR data minimization | Better — less PII stored locally | More data stored, needs retention policy | Same as B |
| Regulatory data access | Depends on Stripe availability | Available even if Stripe is down | Available independently |
| Auditor access | Must go through Stripe API or Dashboard | Direct DB query / export | Direct DB query + reconciliation reports |

**Verdict:** For enterprise / regulated environments, **B or C wins**. Auditors expect the merchant to have their own transaction records, not just a pointer to a third-party API. PCI DSS Requirement 10 (track and monitor access) is easier to demonstrate with local records.

---

## Dimension 2: Reliability & Availability

| Criteria | Option A | Option B | Option C |
|----------|----------|----------|----------|
| Stripe API downtime | Admin tab shows nothing | Display from DB, mark as "last synced" | Display from DB, reconcile when API returns |
| Stripe rate limits (100/s test, 10000/s live) | Every admin page load = 1-2 API calls | API call only for live display, DB for history | DB read (0 API calls), periodic reconciliation |
| Network latency | ~200-500ms per admin page load (Stripe API round-trip) | DB read: <5ms, API call only for fresh data | DB read: <5ms |
| Offline/air-gapped environments | Non-functional | Fully functional for history | Fully functional |
| Webhook delivery failures | Data gap — no record of what happened | Data gap same as A (if webhook didn't fire, DB also empty) | Reconciliation catches gaps |

**Verdict:** **A is fragile** for enterprise. A Stripe outage or rate limit means your admin panel is blind. **C is most resilient** — serves from DB, reconciles differences.

---

## Dimension 3: Performance

| Criteria | Option A | Option B | Option C |
|----------|----------|----------|----------|
| Admin page load time | +200-500ms (Stripe API) | +200-500ms (still calls API for live display) | <5ms (DB only) |
| Order list with 50 orders | 50x API calls if each shows transaction data | 50x DB reads (fast) | 50x DB reads (fast) |
| Batch reporting (export 10K orders) | Impossible without rate limiting | Direct SQL query | Direct SQL query |
| Concurrent admin users (10 admins) | 10-20 API calls/page = rate limit risk | No Stripe API pressure | No Stripe API pressure |

**Verdict:** **A doesn't scale** for enterprise admin workflows. Batch operations (reports, exports, order list views) are impractical. **C is best** for performance.

---

## Dimension 4: Audit Trail & Forensics

| Criteria | Option A | Option B | Option C |
|----------|----------|----------|----------|
| "What happened to this order?" | Stripe Dashboard or API call | Local DB query | Local DB query + reconciliation status |
| Dispute investigation | Must correlate Stripe Dashboard events with OXID order | DB has timestamps correlated to OXID events | Same as B + discrepancy detection |
| Who initiated what? | Stripe logs source but not our user | Can store `initiator` (admin user, webhook, API) | Same as B |
| Tamper evidence | Stripe is authoritative | DB could be tampered (no blockchain) | Reconciliation detects tampering/drift |
| Time-to-answer for support | Slow (API call + cross-reference) | Fast (single DB query) | Fastest (pre-reconciled) |

**Verdict:** **B is good, C is best.** The `initiator` field (which admin user triggered the refund) is critical for enterprise — Stripe only knows "API key", not "which admin user". Local recording captures this context.

---

## Dimension 5: Data Consistency

| Criteria | Option A | Option B | Option C |
|----------|----------|----------|----------|
| Single source of truth | Stripe API (clean) | Two sources that can drift | Two sources with reconciliation to detect drift |
| Stripe Dashboard actions | Always reflected | Missing (unless webhook fires) | Detected and corrected by reconciliation |
| Race conditions | None (always live) | Write-after-event, could miss on crash | Same risk, reconciliation catches it |
| Data drift risk | Zero | Medium — silent drift if webhook fails | Low — reconciliation flags discrepancies |

**Verdict:** **A is simplest for consistency.** B has silent drift risk. **C addresses drift** but adds complexity.

---

## Dimension 6: Development & Maintenance Cost

| Criteria | Option A | Option B | Option C |
|----------|----------|----------|----------|
| Code complexity | Lowest — delete Sprint 84/85 recording code | Medium — keep recording, display from API | Highest — recording + reconciliation job + display logic |
| Testing surface | Small (API mocking only) | Medium (DB writes + API mocking) | Large (DB + API + reconciliation logic) |
| Stripe SDK upgrades | Must track API changes for display | Display from API, DB schema stable | DB schema stable, reconciliation must track API changes |
| Bug surface | Low | Medium (dual-write bugs) | Higher (reconciliation edge cases) |
| Time to implement | 1 day (delete code) | Current state (already done) | 3-5 days (add reconciliation job) |

**Verdict:** **A is cheapest** to build and maintain. **C is most expensive** but most robust.

---

## Dimension 7: Multi-Provider Strategy

| Criteria | Option A | Option B | Option C |
|----------|----------|----------|----------|
| Adding PayPal/Klarna/Adyen later | Each provider needs its own API integration for display | `oe_payments_transaction` is provider-agnostic — same UI for all | Same as B + reconciliation per provider |
| Unified transaction history | Impossible — each provider has different API | One table, one UI, any provider | Same as B |
| Provider migration | History lost if Stripe API access revoked | History preserved in DB | History preserved + reconciled |

**Verdict:** **B and C win decisively.** The `oe_payments_transaction` table was designed as provider-agnostic (payment-component). With Option A, adding a second provider means building another API-specific display — no reuse.

---

## Dimension 8: Business Intelligence & Reporting

| Criteria | Option A | Option B | Option C |
|----------|----------|----------|----------|
| "Total refunds this month" | Stripe Dashboard or API pagination | `SELECT SUM(amount) FROM oe_payments_transaction WHERE type='refund'` | Same as B |
| Custom reports | Requires Stripe API integration in reporting tool | Standard SQL / BI tool | Standard SQL / BI tool |
| Real-time dashboards | API polling (rate limits) | DB triggers / change streams | DB-based |
| Data warehouse integration | ETL from Stripe API (separate pipeline) | ETL from local DB (standard) | ETL from local DB (standard) |

**Verdict:** **B and C enable standard BI.** Option A locks reporting behind the Stripe API.

---

## Recommendation Matrix

| Weight | Dimension | A | B | C |
|--------|-----------|---|---|---|
| High | Compliance / Audit | 2 | 4 | 5 |
| High | Reliability | 2 | 3 | 5 |
| High | Multi-Provider | 1 | 5 | 5 |
| Medium | Performance | 2 | 3 | 5 |
| Medium | Forensics | 2 | 4 | 5 |
| Medium | Reporting | 1 | 5 | 5 |
| Medium | Consistency | 5 | 3 | 4 |
| Low | Dev Cost | 5 | 4 | 2 |
| **Score** | **(weighted)** | **2.3** | **3.9** | **4.5** |

---

## Recommended: Option B+ (pragmatic enterprise)

**Option B with one addition from C**: keep DB recording (already built), display from Stripe API on the Stripe tab (already built), and add a **lightweight reconciliation check on admin view** (already built — the `reconcilePaymentState` in `OrderRefund::render()`).

This gives:
- Local audit trail with `initiator` context (who did what)
- Stripe API as display source (always fresh)
- Self-healing on admin view (reconciliation)
- Multi-provider ready (`oe_payments_transaction` is provider-agnostic)
- SQL-queryable for reporting
- No separate reconciliation cron job (the "C" overhead we skip)

### What This Means for Sprint 87

1. **Keep** Sprint 84/85 DB recording — it's the audit log
2. **Keep** Sprint 86 Stripe API display — it's the live source of truth
3. **Keep** `reconcilePaymentState()` — it's the lightweight reconciliation
4. **Add** `initiator` field to transaction records (which admin user, webhook ID, etc.)
5. **Consider** (future sprint): nightly reconciliation report that flags DB ↔ Stripe drift

### What NOT To Do

- Don't display from DB on the Stripe tab (Stripe API is fresher)
- Don't remove DB recording (you'll need it for reporting and multi-provider)
- Don't build a full reconciliation cron job now (overengineering for current stage)
