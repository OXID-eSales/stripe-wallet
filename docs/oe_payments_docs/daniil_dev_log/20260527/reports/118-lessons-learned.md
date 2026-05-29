# 118 — Lessons Learned from the Code-Review 114 Remediation

**Date:** 2026-05-28
**Source effort:** 13 sub-sprints (114.1 → 114.13), 61 commits across 2 repos, ≈ 9.5 hours of agent compute, 100% gate pass rate.
**Companion reports:** 116 (narrative), 117 (numerical achievements).

These are the durable lessons — what to do the same and what to do differently — distilled from running a 13-sub-sprint, 2-repo, agent-driven remediation under a strict TDD + scope regime.

---

## A. Process

### A.1 — Verify findings against current code at the start of each sprint
Findings shift as earlier sprints land. Line numbers move. **Several findings were implicitly resolved by earlier sprints I didn't initially expect:**
- A4 (`'other'` literal) was eliminated by 114.5 O7 (which removed the speculative `Payment::getPaymentProvider()`).
- L3 looked like it needed a payment-base interface change to add `fail()/cancel()` — but the interface already had them; the 4 `instanceof PaymentContract` downcasts were spurious.
- `ChargeRefundedHandler` (one of D3's three sites) was deleted in 114.4 before D3 was tackled, shrinking D3 from a 3-site to a 2-site dedup.

**How to apply:** before dispatching every sprint, `grep` the verified files for the cited patterns — never trust a finding's line numbers from the original review more than a fresh grep.

### A.2 — Force per-commit granularity by splitting the dispatch
When 114.10 was dispatched as a single sprint with 6 phases, the agent collapsed phases 2-4 into one commit ("Phase 2-4 combined") despite the prompt asking for per-phase commits. When 114.11 was **split** into 114.11a (S5, S6, S7) and 114.11b (S2, S3, S4) by the dispatch boundary, the agent honored per-finding commits.

**How to apply:** if commit-by-commit reviewability matters (large refactor, security-critical path), split the work into multiple smaller dispatches instead of asking nicely in one big prompt. The dispatch boundary is a hard constraint; the prompt is a soft one.

### A.3 — Verify high-impact agent claims independently
Multiple times an agent report needed grep/read verification:
- 114.1 — verified `shopId: 1` literal is gone and `'EUR'` was not touched (scope discipline check).
- 114.2 — verified flag is set BEFORE the order-creation dispatch (security gate).
- 114.4 — confirmed `match` is gone and the dead handlers actually deleted.
- 114.10b — caught the agent's "boundary sealed" claim was approximate (2 legitimate signature-verification imports in webhook entry remained; that's fine, but the report should have noted it).
- 114.13 — caught a miscount: agent said "4 baselined" but baseline was actually 3.

**How to apply:** treat agent reports as a hypothesis; confirm key claims with `grep`/`Read`/`git show`. The cost is small (1-2 minutes) and catches both overclaims and undocumented exceptions.

### A.4 — STOP-and-ask is the right move on security or undocumented behavior
Sprint 114.2 wanted to narrow the address-validation bypass, but the docblock referenced a `StripeCheckoutReturnHandler` **that didn't exist**. No code set the supposed `sDelAddrMD5` "restore" the comment described. Rather than guess a gate, I asked the user with concrete options. The chosen "explicit contract flag" was the only correct gate (the natural alternative would have failed because `stripe_contract_id` isn't in the session yet during the order-creating dispatch).

**How to apply:** when a finding's *fix* depends on undocumented or contradictory state, STOP and present focused options to the user — don't have the agent guess on security paths. Each agent prompt should explicitly authorize STOP-and-report for ambiguous fixes.

### A.5 — Hard scope rules must be repeated in every agent dispatch
The user's "edit only stripe + payment-base" rule needed to appear at the top of every subsequent agent prompt as **ABSOLUTE HARD RULES**. The agents respected it consistently — they didn't touch paypal, OPC, or OXID core in any of the 7 dispatches after the rule was stated.

**How to apply:** treat session-scoped scope rules as a prompt header. Restate them, don't reference them ("per earlier rule"). Agents work from the prompt, not from session history.

### A.6 — Sequential agent dispatch beats parallel on a shared docker tree
Concurrent docker test runs share MySQL, the OXID tmp cache, the file system mount, and `oe:cache:clear` state. Parallel dispatches risk flakiness. Worktree isolation is also wrong here because the docker `php` container mounts a specific host path (`source/` → `/var/www`); tests outside that mount don't run.

**How to apply:** for OXID/docker-mounted modules, dispatch sequentially. Within a single dispatch, the agent can still parallelize file reads/edits internally.

---

## B. Architecture

### B.1 — Characterization tests are the only honest safety net for refactors
For 114.4 (webhook dispatch) and 114.10b (DTO migration), the discipline was: write tests asserting the **exact current behavior** of each event type / consumer FIRST against the unrefactored code (the green parity net), then refactor with them staying green. This caught nothing dramatic but is what made the refactors trustable.

**How to apply:** before any "behavior-preserving" refactor, write a characterization test layer (assertions over current return values / view-data shape / numbers). Without it, "preserves behavior" is hope, not evidence.

### B.2 — Adjacent bugs surface during focused refactors — fix them in scope
Tackling D1 (centralize cents math) made the truncation visible: `(int)(19.99 * 100) = 1998` was charging Stripe €19.98 instead of €19.99. **4 real money bugs were fixed as a side-effect of the DRY consolidation** — none of them appeared in the original review.

Similarly:
- L3 cleanup revealed all 4 downcasts were spurious (no interface change needed).
- The webhook-handler tagging revealed `PaymentIntentSucceededHandler` was registered without a tag (dead service masquerading as live).
- S5's `ContainerFactory` cleanup revealed `WebhookController` was re-fetching the container mid-request.

**How to apply:** when refactoring, READ the code being touched — don't just mechanically transform it. Adjacent bugs often hide in the same neighborhood as the cited finding.

### B.3 — Additive payment-base changes are the path to cross-module safety
114.10a touched payment-base twice (NormalizedPaymentStatus class, currency field on capture/refund request DTOs) — and **paypal + OPC stayed byte-identical**:
- Pattern 1: new file (new constants class) — old constants delegate to it as aliases. Old consumers see no diff.
- Pattern 2: new ctor parameter — APPEND-only, default `null`, never reorder. Old constructions still compile.

The rule of three:
1. Never reorder existing constructor arguments.
2. Never change a return type.
3. Always append optional parameters with safe defaults.

**How to apply:** every payment-base PR must be additive-only. Run paypal + OPC unit suites BEFORE and AFTER the change; counts must match exactly (modulo pre-existing flakes you can identify).

### B.4 — Provider-local outcome objects beat widening shared DTOs
For 114.4, payment-base's `WebhookResult` carried no contract id, but Stripe needed to log one. The temptation was to add a `?string $contractId` to the shared DTO. Instead, a stripe-local `StripeWebhookOutcome` (carrying `WebhookResult` + `?string $contractId`) was introduced. Result: payment-base untouched; paypal/OPC zero risk; the concern stayed where it actually belongs (the Stripe layer that knows about contracts in this flow).

**How to apply:** if only one provider needs the richer carrier, build it provider-local. Widen the shared DTO only when ≥ 2 providers will use the new field.

### B.5 — Pure stateless utilities don't need DI
`AmountConverter` is a `final class` with `public static` methods + currency-exponent constants. No interface. No injection. Why not?
- There is no swappable implementation that would ever be useful (a "fake AmountConverter" returning different math is a test bug, not a feature).
- Mocking a deterministic pure function provides no value (you test it directly).
- Injecting it into VOs and OXID models would have required ctor seams in awkward places.

R-4 (DI) is about *swappable dependencies*. R-9 (no overengineering) says don't build an interface that will never have a second implementation. For pure utilities the two rules agree on **static**.

**How to apply:** for a stateless deterministic utility, ask "would a second implementation ever be useful?" If no, static methods. If yes (e.g. multiple `Locker` strategies, multiple `Clock` sources), inject.

### B.6 — `payment-base`'s `EventListenerProvider` reads `getPriority()` from code, not the YAML tag
Discovered in 114.11a (S7). Dropping `StripeContractCreationHandler::getPriority()` (which returned 100) and relying on the services.yaml `priority: 100` tag would have silently shifted dispatch from 100 → 0. The agent correctly kept the in-code method.

**How to apply:** when consolidating handler priorities, do NOT remove `getPriority()` methods without verifying how the dispatcher resolves them. In current payment-base, the in-code value wins. (Fixing this in payment-base is a small future ticket.)

### B.7 — Integration-suite silent skips lie about coverage
The Stripe integration suite reported 157 tests but **53 silently skipped without Stripe credentials**. Default CI was green while ~ 1/3 of the integration layer never ran. After 114.13 (T6), the gating is honest:
- `@group requires-stripe-creds` on credential-dependent specs.
- Default suite excludes that group → reports only what actually ran.
- Container-boot failures became hard-fails instead of silent skips.

**How to apply:** never let `markTestSkipped` quietly mask absent preconditions in CI. Either fail the precondition check (container boot, env vars) or gate explicitly via `@group` + suite config. A green run must mean what it says.

### B.8 — The agnostic-boundary "leak" rule applies to TYPES in CONSUMERS, not to provider-specific code
The original A1 finding cited `StripeWebhookProcessor` for `use Stripe\Webhook;`. But the processor's *job* IS provider-specific webhook signature verification — it has to use the SDK. The actual leak is when **agnostic** code (services, view-data, model) accepts/returns provider SDK types.

After 114.10b, `use Stripe\\` still appears 2× in `StripeWebhookProcessor` (for `Webhook::constructEvent` + the corresponding exception). That's fine. The 9 service/view/model leaks the review flagged are gone.

**How to apply:** when calling something an "agnostic-boundary leak," ask: is the file *itself* provider-specific (legitimately uses the SDK), or is it agnostic code that should never know about the provider? Only the second is a leak.

---

## C. Tooling

### C.1 — The tdd-solid-engineer agent works well for behavior-preserving refactors
Across 15 dispatches, the agent reliably:
- Wrote RED tests before GREEN implementations.
- Ran the full pre-commit gate per commit (61/61 green).
- Wrote completion reports.
- Honored scope rules when they were in the prompt as **ABSOLUTE HARD RULES**.

The two things it sometimes does:
1. Collapses multi-phase commits into one (mitigation: split the dispatch — see A.2).
2. Occasionally miscounts in the summary (mitigation: independent verification — see A.3).

### C.2 — payment-base is a separate git repo
Not a submodule, no `.gitmodules` entry — just a sibling directory with its own `.git`. Commits there don't appear in outer `git log`. When verifying, `cd source/extensions/payment-base && git log` (or `git -C source/extensions/payment-base log`).

**How to apply:** when generating PR descriptions or running cross-module audits, remember to query both repos. The outer log will NOT show the 2 payment-base commits from 114.10a.

### C.3 — Numbers communicate impact better than narrative at wrap-up
Report 117 (numerical achievements) carries the impact in a way the narrative report 116 doesn't: 22 cents-math sites → 1, 53 silent skips → 0, +202 unit tests, 61/61 commits green. The user explicitly asked for numbers — they're the universal language for "what did the work actually accomplish."

**How to apply:** for any multi-sprint effort, finish with a number-heavy summary (per-sprint commits, before/after test counts, baseline deltas, DRY sites collapsed). Keep the narrative summary separate.

---

## D. Numbers worth remembering

| Lesson-test | Number from this work |
|---|---|
| Bonus bugs discovered while refactoring | **7** (incl. 4 real-money truncation bugs) |
| Original findings that turned out smaller than the review claimed | **2** (A4 implicitly fixed, L3 trivially fixed — no payment-base needed) |
| Sub-sprints needed to split (vs original plan) | **2** (114.10 → 10a+10b, 114.11 → 11a+11b) |
| Commit collapses by agent despite prompt | **1** (114.10b — fixed pattern in 114.11 by splitting the dispatch) |
| Cross-module regressions | **0** (paypal 449/798 byte-identical; OPC 220/557 byte-identical) |
| New static-analysis suppressions added | **0** |
| PHPMD baseline growth | **0** (shrank −1 instead) |
| Pre-commit gate failures across all sprints | **0** |
| User STOP-and-ask points needed | **1** (114.2 gating strategy) |

---

## E. The single most important lesson

**Discipline > cleverness.** The remediation's success wasn't a clever architectural insight — it was 13 sprints of:
1. Read the actual current code.
2. Write the test first.
3. Make the minimum change.
4. Run the full gate.
5. Commit only when green.

Every shortcut considered (skip a test, collapse a commit, defer a verification, "we can fix the consumer if it breaks") would have eventually surfaced as a regression. The session rule "edit only stripe + payment-base" was the highest-impact piece of discipline — it forced **additive-only** payment-base design, which is what kept paypal + OPC byte-identical across the whole effort.
