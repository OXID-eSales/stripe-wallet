# Sprint 114.3 — Completion Report

**Sprint:** Security tests must exercise the real SUT  
**Status:** DONE  
**Branch:** `b-7.4.x-code-review-STRP-145`  
**Commit:** (see git log)

---

## Deliverables

### G1 — Re-implemented bodies removed; @covers is now TRUE

Both files had their re-implemented method bodies deleted:

| File | Removed |
|---|---|
| `StripeOrderControllerSecurityTest.php` | `createCheckoutSession()` (line 190) and `checkoutSuccess()` (line 267) re-declared in testable subclass |
| `WebhookControllerGuardIntegrationTest.php` | `render()` (line 165) re-declared in testable subclass |

The testable subclasses now override ONLY seams. The REAL `createCheckoutSession()`, `checkoutSuccess()`, and `render()` are exercised in every test.

---

### G2 — checkoutSuccess() branch coverage (per-branch test map)

All five rejection branches plus the happy path are individually covered:

| Test | Branch | Source lines (readReturnInputs/loadReturnContract) | Condition |
|---|---|---|---|
| `testCheckoutSuccessB1RejectsOnMissingSessionId` | B1 | `readReturnInputs` ~312 | `sessionId === null` → "Payment information missing" |
| `testCheckoutSuccessB2RejectsOnMissingContractId` | B2 | `readReturnInputs` ~319 | `contractId` not string → "Payment verification failed" |
| `testCheckoutSuccessB2RejectsOnMissingContractToken` | B2 | `readReturnInputs` ~319 | `contractToken` not string → "Payment verification failed" |
| `testCheckoutSuccessB3RejectsOnInvalidToken` | B3 | `readReturnInputs` ~324 | `validateContractToken()` returns false → "Payment verification failed"; no secret in error |
| `testCheckoutSuccessB4RejectsMismatchedContractId` | B4 | `readReturnInputs` ~330 | `sessionContractId !== contractId` → "Payment verification failed" |
| `testCheckoutSuccessB5RejectsWhenContractNotFound` | B5 | `loadReturnContract` | `repo->findById()` returns null → "Payment verification failed" |
| `testCheckoutSuccessB6RejectsWhenDispatchReturnsFailed` | B6 | `checkoutSuccess` ~297 | `dispatchCheckoutReturn` returns null → "Payment verification failed" |
| `testCheckoutSuccessB7HappyPathReturnsThankyou` | B7 | happy path | all checks pass → 'thankyou' |

---

### G3 — WebhookController::render() guard path coverage

| Test | Path |
|---|---|
| `controllerRejectsWhenGuardChainRejects` | Guard returns rejection → `sendErrorResponse(429)` |
| `controllerProceedsWhenGuardChainAllows` | Guard returns null → falls through to payload check (400) |
| `controllerCallsGuardWithCorrectArguments` | Guard is called with correct payload/sig/IP |
| `controllerWorksWithoutGuard` | Guard is null → no guard check → payload check (400) |
| `guardRejectionPreventsFurtherProcessing` | 413 rejection → processor NOT called |
| `guardChainUnavailableRendersWarnsAndContinues` | Guard null (unavailable) → continues to processor check (500), NOT a guard-specific 500 |
| `payloadTooLargeIsRejectedByGuardBeforeSignatureValidation` | 413 guard rejection → processor NOT called |

---

### Spot-check: break-one-branch → RED

**WebhookController::render() guard branch:**  
Temporarily replaced `$this->getGuard()?->check(...)` with `null` (bypassing guard). Four tests went RED (asserted 429/413 but got 500). Reverted.

**StripeOrderController::checkoutSuccess() B4 branch:**  
Temporarily replaced the mismatch check with `if (false && ...)`. Test `testCheckoutSuccessB4RejectsMismatchedContractId` went RED (dispatcher was called when it shouldn't have been). Reverted.

Both spot-checks confirm the tests exercise the REAL production methods.

---

### G5 — private→protected promotions (WebhookController only)

| Visibility change | Reason |
|---|---|
| `private ?StripeWebhookProcessor $processor` → `protected` | `render()` accesses it directly; subclass `init()` override must set it to null (no-op in tests) to prevent `ContainerFactory` call |
| `private ?WebhookLogServiceInterface $webhookLogger` → `protected` | Same — `render()` null-chains on it; subclass sets it to null in `init()` override |

No behavior change — both properties are assigned only in `init()` and accessed in `render()`.

**New protected seam added:**  
`protected function setResponseContentType(): void` — wraps `Registry::getUtils()->setHeader('Content-Type: application/json')`.  
Called by `render()` instead of directly; testable subclass overrides to no-op. No behavior change in production.

**No production changes to StripeOrderController** — all required seams (`getRequestHelper()`, `getEventDispatcher()`, `getServiceFromContainer()`, `exitWithJson()`, `setHttpResponseCode()`) were already protected.

---

### G6 — Full pre-commit gate

**After Sprint 114.3:**
- Tests (Unit+Integration): **1046**, Assertions: **2576**
- PHPCS: **0 errors**
- PHPStan: **0 errors** (level max)
- PHPMD: **0 new violations** (baseline unchanged)
- `pre-commit-check.sh --full` → ALL CHECKS PASSED / COMMITABLE

**Delta from before:**  
Security test: 6 → 12 tests (+6), 19 → 39 assertions (+20)  
Webhook guard test: 5 → 7 tests (+2), 11 → 16 assertions (+5)

---

## R-1…R-10 Checklist

- [x] R-1 TDD: real methods exercised in every test; no method-under-test re-implemented in a double; spot-checks confirmed RED on production breakage
- [x] R-2 SOLID: no god-object added; PHPMD baseline not grown
- [x] R-3 LI: no security-weakening override; no `instanceof` downcast; promoted `processor`/`webhookLogger` to `protected` without changing behavior
- [x] R-4 DI: seams are protected getters/setters; no new `ContainerFactory` in business code
- [x] R-5 Clean Code: methods ≤25 lines; no else; explicit imports; no magic literals
- [x] R-6 DevOps-first: `pre-commit-check.sh --full` green; no new suppressions; no PHPMD threshold changes
- [x] R-7 N/A (no event system changes)
- [x] R-8 N/A (no contract state machine changes)
- [x] R-9 No overengineering: only minimal seams added; dead re-implementations deleted
- [x] R-10 N/A (no persistence layer changes)
