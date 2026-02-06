# Sprint 42: Idempotency Implementation - Discussion & Decision

**Date:** 2026-02-06
**Status:** ✅ DECISIONS MADE
**Prerequisites:** Sprint 38-41 completed (dead code cleanup)
**Estimated Effort:** 8-16 hours (depending on chosen option)

---

## Executive Summary

The `oe_payments_idempotency` table exists but is **completely unused**. This sprint decides how to handle idempotency for API calls (capture, refund, create payment) across all payment providers.

**Core Goal:** Prevent duplicate charges/refunds when:
- User double-clicks "Capture Payment" button
- Network timeout causes retry
- Webhook handler and admin action race condition

---

## Questions for Discussion

### Question 1: Do We Need Custom Idempotency?

**Context:** Stripe SDK already supports idempotency keys natively. PayPal has similar features.

| Option | Description | Effort |
|--------|-------------|--------|
| **A) Yes - Build in payment-component** | Full control, works with all providers, caches results | 11-16 hours |
| **B) No - Use provider SDKs only** | Rely on Stripe/PayPal native features | 2-4 hours |
| **C) Remove dead table** | Delete `oe_payments_idempotency`, document decision | 1-2 hours |

**Considerations:**
- Option A: Future-proof, consistent across providers
- Option B: Simpler, but inconsistent provider support
- Option C: Acknowledge over-engineering, clean up

**My Recommendation:** Option A or B depending on multi-provider plans

---

### Question 2: If Building Custom - Which Pattern?

**Context:** Three architectural patterns available (see analysis report).

| Option | Pattern | Pros | Cons |
|--------|---------|------|------|
| **A) Decorator** | `IdempotentPaymentAdapter` wraps any adapter | SOLID, flexible, opt-in | Extra class |
| **B) Request Field** | Add `idempotencyKey` to request objects | Simple, uses provider native | Inconsistent |
| **C) Abstract Base** | `AbstractPaymentAdapter` with template method | Enforced, DRY | Forces inheritance |

**Code Example - Decorator (Option A):**
```php
// DI configuration
services:
    stripe_adapter:
        class: StripeAdapter

    payment_adapter:  # Production use
        class: IdempotentPaymentAdapter
        arguments:
            - '@stripe_adapter'           # Wraps Stripe
            - '@idempotency_repository'   # Uses oe_payments_idempotency
```

**My Recommendation:** Option A (Decorator) - follows SOLID, composition over inheritance

---

### Question 3: Scope of Idempotency Protection

**Context:** Which operations should be protected?

| Operation | Risk Level | Recommendation |
|-----------|------------|----------------|
| `capturePayment()` | 🔴 HIGH | ✅ Protect |
| `refundPayment()` | 🔴 HIGH | ✅ Protect |
| `createPayment()` | 🟡 MEDIUM | ⚠️ Optional (contract prevents duplicates) |
| `voidPayment()` | 🟢 LOW | ❌ Skip (void is idempotent by nature) |
| `authorizePayment()` | 🟡 MEDIUM | ⚠️ Optional |

**My Recommendation:** Start with capture + refund only (highest risk)

---

### Question 4: Idempotency Key Strategy

**Context:** How to generate unique keys?

| Option | Key Format | Example |
|--------|------------|---------|
| **A) Contract-based** | `{contractId}_{operation}` | `abc123_capture` |
| **B) Order-based** | `{orderId}_{operation}_{timestamp}` | `order456_capture_1707177600` |
| **C) Request hash** | `md5(providerPaymentId + amount + operation)` | `a1b2c3d4e5f6...` |
| **D) Caller provides** | Pass in request object | Developer responsibility |

**My Recommendation:** Option A (Contract-based) - aligns with smart-contract architecture

---

### Question 5: Cache Duration

**Context:** How long to keep idempotency records?

| Option | Duration | Storage Impact |
|--------|----------|----------------|
| **A) 24 hours** | Matches Stripe's default | ~1000 rows/day |
| **B) 7 days** | Safety margin | ~7000 rows/week |
| **C) 30 days** | Audit trail | ~30000 rows/month |
| **D) Permanent** | Full history | Grows indefinitely |

**Table has `OXEXPIRES` column** - can implement cleanup cron.

**My Recommendation:** Option B (7 days) with cleanup cron

---

### Question 6: What About the Dead Table?

**Context:** `oe_payments_idempotency` exists but is unused.

| Option | Action |
|--------|--------|
| **A) Use it** | Implement IdempotencyRepository for existing table |
| **B) Modify it** | Add/remove columns as needed, then use |
| **C) Delete it** | Remove table, create new if needed later |
| **D) Keep unused** | Document as "reserved for future use" |

**Current table schema:**
```sql
oe_payments_idempotency (
    OXID, OXKEY, OXORDERID, OXOPERATION,
    OXRESULT, OXSTATUS, OXCREATED, OXEXPIRES
)
```

**My Recommendation:** Option A - table schema is already good

---

## Implementation Plan (If Option A Selected)

### Phase 1: Repository Layer (4 hours)
```
payment-component/src/Repository/
├── IdempotencyRepositoryInterface.php
└── DoctrineIdempotencyRepository.php
```

### Phase 2: Decorator Adapter (4 hours)
```
payment-component/src/Adapter/
└── IdempotentPaymentAdapter.php
```

### Phase 3: Request Object Enhancement (2 hours)
```
payment-component/src/Adapter/Request/
├── CapturePaymentRequest.php  (+ idempotencyKey)
└── RefundPaymentRequest.php   (+ idempotencyKey)
```

### Phase 4: Tests (4 hours)
```
payment-component/tests/
├── Unit/Adapter/IdempotentPaymentAdapterTest.php
└── Integration/Repository/DoctrineIdempotencyRepositoryTest.php
```

### Phase 5: Wire Up Stripe Module (2 hours)
- Configure DI to wrap StripeAdapter
- Add idempotency key generation in capture/refund handlers

---

## Decision Matrix

| Question | Options | Decision |
|----------|---------|----------|
| Q1: Need custom? | A/B/C | **A) Build custom layer** (use existing table) |
| Q2: Pattern | A/B/C | **A) Decorator pattern** wrapping adapter |
| Q3: Scope | capture/refund/all | **Capture + Refund only** (highest risk) |
| Q4: Key strategy | A/B/C/D | **A) Contract-based** (`capture:{providerPaymentId}`, `refund:{chargeId}`) |
| Q5: Cache duration | 24h/7d/30d/permanent | **A) 24 hours** |
| Q6: Existing table | use/modify/delete/keep | **A) Use existing** `oe_payments_idempotency` table as-is |

---

## Dependencies

**Before starting this sprint:**
- [x] Sprint 38: Remove dead API key fields ✅
- [x] Sprint 39: Add key mismatch warning ✅
- [x] Sprint 40: Remove StatusMappingConfig ✅
- [x] Sprint 41: Idempotency analysis report ✅

**Blocking:**
- None - can proceed independently

---

## Risks

| Risk | Mitigation |
|------|------------|
| Over-engineering | Start with capture+refund only |
| Performance impact | Minimal (+2 queries per API call) |
| Stale cached results | 7-day expiry + cleanup cron |
| Test coverage gaps | TDD approach, integration tests |

---

## Notes for Discussion

1. **Multi-provider future:** Are we planning PayPal/Unzer/Amazon Pay modules?
   - If yes → Pattern A (Decorator) is worth the investment
   - If no → Pattern B (SDK native) is simpler

2. **Contract system already prevents some duplicates:**
   - Contract state machine prevents double-capture at domain level
   - Idempotency adds defense-in-depth at API call level

3. **Stripe's native idempotency:**
   - Keys expire after 24 hours
   - Results cached on Stripe's side
   - We could use this AND our own (belt + suspenders)

---

## Action Items After Discussion

- [x] Finalize answers to Q1-Q6
- [x] Update this document with decisions
- [x] Create implementation tasks
- [ ] Execute sprint with TDD approach
- [ ] Run `./bin/pre-commit-check.sh --full` for validation
