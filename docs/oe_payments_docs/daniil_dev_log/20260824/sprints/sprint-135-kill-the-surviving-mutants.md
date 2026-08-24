# Sprint 135 — Kill the surviving mutants: assert behaviour, not calls

**Ticket:** STRP-TBD (assign before branching)
**Branch:** `b-7.4.x-mutation-hardening-STRP-TBD` (stripe only; `payment-base` not yet mutated — see *Out of scope*)
**Source:** [`reports/01-mutation-testing-baseline.md`](../reports/01-mutation-testing-baseline.md) — Infection 0.31.9 run, 2026-08-24
**Base:** stripe `b-7.4.x` @ v3.2.0
**Prerequisite:** none. Independent of Sprint 133/134 (honest failure paths); touches tests first, production code only where a mutant reveals genuinely dead or unreachable logic.

---

## Why this sprint exists

The suite is large (1,509 tests / 3,934 assertions, green) and the module writes
**1.69 lines of test code per line of production code**. Neither number tells us
whether the tests *detect* anything. A mutation run does, and it was run:

| Metric | Value |
|---|---|
| Mutants generated (covered code) | **462** |
| Killed by the suite | **340** |
| **Escaped** | **122** |
| Mutation code coverage | **100%** |
| **Covered Code MSI** | **73%** |

So the suite **executes** every line the mutants were placed in and **misses 27%
of the semantic changes** made to them. That gap is invisible to line coverage,
to the test-to-source ratio, and to assertion density (≈2.3 per test) — all three
were green while it existed.

## The one rule this sprint installs

> **A test must fail when a method call it depends on is deleted.**
> If removing `$this->logger->error(...)`, `$this->repo->save(...)` or
> `$service->notify(...)` leaves the suite green, that call is not tested — it is
> merely executed. Assert on the *effect* (state, emitted event, persisted row,
> returned value), or on the double with an explicit expectation
> (`expects($this->once())`), never on the fact that a line ran.

`MethodCallRemoval` is **28 of the 122 escapes — the single largest category**.
This is the over-mocking signature: tests that build a mock, exercise the unit,
and assert against the mock's canned return rather than against what the unit
*did*. The module already banned the adjacent anti-pattern in **R-1.5** (no
re-implementing the method under test inside a double) after the Sprint-17
false-positive-test remediation. This sprint extends that rule from "the double
must not fake the logic" to "the assertion must not be satisfiable without the
call."

## Definition of Done (sprint-level)

1. **Covered Code MSI ≥ 85%** over the four measured directories
   (`EventSystem/Handler`, `Core`, `Webhook`, `Service`), up from 73%.
2. **`MethodCallRemoval` escapes = 0** in `EventSystem/Handler` and
   `Webhook/Handler`. This is the sprint's hard gate; the MSI number can miss by
   a point, this cannot.
3. **Every remaining escape is triaged, not merely tolerated.** Each surviving
   mutant is either killed, or recorded in
   `tests/MUTATION_EQUIVALENTS.md` with a one-line argument for why it is an
   equivalent mutant (semantically identical, therefore unkillable). No silent
   survivors.
4. **No production behaviour change without a RED test first.** Where a mutant
   escapes because the code is genuinely unreachable, delete the code — with the
   characterization test written first, per the standing TDD rule.
5. **`AmountConverter`, `MinorUnitConverter`, `CapturableAmount` stay at zero
   escapes.** They are the regression bar; a change that costs money-path MSI is
   rejected regardless of what it buys elsewhere.
6. All gates green: `composer phpcs` · `composer phpstan` (level max, **no new
   baseline entries**) · `composer phpmd` · `--testsuite Unit` ·
   `--testsuite Integration` · `./bin/pre-commit-check.sh --full`.
7. Infection wired into CI as a **non-blocking report** with
   `--min-covered-msi=73` as the initial floor, raised to the achieved value on
   merge. Blocking comes in a later sprint, once the number is stable.

## What is already good, and must not regress

The run's most important finding is not a weakness. **The money path has zero
escaped mutants**: `AmountConverter`, `MinorUnitConverter` and `CapturableAmount`
kill every mutation placed in them. The Sprint-114.7 cents-math consolidation
("~22 hand-coded `* 100` sites → 1") did not just centralise the arithmetic — it
left it genuinely verified. That is the standard the handler layer is being held
to here, and it is an existing in-repo reference implementation, not an aspiration.

## Stories

Ordered by escapes closed per unit of effort. One dispatch per story; do not
collapse (S1 and S2 share a fixture and are still separate commits).

### S1 — `StripeRefundRequestHandler`: 27 escapes
`MethodCallRemoval×12, ArrayItem×9, ArrayItemRemoval×4, Coalesce×2`
The worst file in the module and on the money path. The 12 removable calls are
almost certainly audit-log and result-population calls asserted only via the
mock's return. Rewrite the tests to assert the **emitted result object and the
persisted audit row**, then re-run. The 9 `ArrayItem` escapes indicate the
request payload assembled for the PSP is asserted by shape, not by content —
assert the actual key/value pairs that reach the adapter.

### S2 — `StripePaymentStatusHandler`: 22 escapes
`MethodCallRemoval×8, ArrayItemRemoval×5, ArrayItem×4, ConcatOperandRemoval×2`
Same pattern as S1. The two `ConcatOperandRemoval` escapes mean a string built
for a status message can lose an operand undetected — assert the composed string,
not that composition happened.

### S3 — `CheckoutSessionService`: 22 escapes
`ArrayItemRemoval×4, LogicalAnd×3, ConcatOperandRemoval×3, ArrayItem×2`
Note this class is the module's *reference implementation* for verified
fallbacks (it checks its itemised sum against OXID's authoritative total). The
logic is sound; the tests under-specify it. The 3 `LogicalAnd` escapes are the
priority — a compound condition that can be weakened without failing a test is a
latent branch bug.

### S4 — `StaticContent`: 20 escapes
`Coalesce×4, MethodCallRemoval×3, ProtectedVisibility×2, DecrementInteger×2`
Lowest risk of the big four (presentation, not money). The 2
`ProtectedVisibility` escapes suggest members are more visible than any test
requires — tighten the visibility rather than write a test to pin it.

### S5 — `CustomerDataSanitizer`: 7 escapes
`IncrementInteger×2, MBString×2, DecrementInteger×1, ReturnRemoval×1`
GDPR-relevant (this is the H7 redaction path). The **`ReturnRemoval`** escape is
the alarming one: a return statement can be deleted with the suite green. Treat
as the highest-severity single mutant in the run despite the low file total.
`MBString×2` means multibyte handling is asserted only on ASCII input — add
non-ASCII fixtures (`Müllerstraße`, Cyrillic, CJK), which also aligns with the
Sprint-119 validation widening.

### S6 — Webhook handlers: 6 escapes across 3 files
`CheckoutSessionExpiredWebhookHandler` (3), `OpcExternalReturnCleanupHandler` (3),
`StripeWebhookProcessor` (1) — dominated by `MethodCallRemoval×4`.
Small, and directly under DoD #2. The webhook path is the source of truth for
payment state; a deletable call here is a silent state-transition loss.

### S7 — `ShopId` and the long tail: 8 escapes across 7 files
`ShopId` (3: `GreaterThanOrEqualTo×2, LogicalAnd×1`), `CheckoutReturnService` (5).
`ShopId::of()` boundary conditions are under-tested — `>= 1` can become `> 1`
undetected, i.e. **shop 1 could be rejected and no test would notice**. Given
Sprint 133's F14 (shopId defaulting to 1) touches the same class, coordinate to
avoid a merge conflict.

### S8 — Wire Infection into CI and record the baseline
Add the widened `infection.json5` (four source dirs, `--testsuite=Unit`), a
`composer mutation` script, and a non-blocking CI job publishing the summary.
**Document the environment prerequisite**: the run is impossible against the
developer shop as configured — eight modules are active locally and the
`mollie → paypal` class-extension chain aborts the bootstrap. CI activates only
`oe_payment_base` and `oe_payments_stripe_wallet`, which is why CI can run this
and a local dev box cannot without deactivating modules first. This belongs in
`docs/for_developer/`, not in tribal memory.

## Out of scope

- **`payment-base` was not mutated.** Its suite (915 test methods) has no MSI
  baseline at all. Establishing one is Sprint 136, not this sprint.
- **Uncovered code.** `--with-uncovered` aborts on shop-coupled classes
  (`ViewConfig` extends the OXID chain), so the 73% is MSI over code the unit
  tests already execute — not over the module. Widening the scope needs a
  bootstrap that can mutate shop-extended classes, which is its own problem.
- **Integration and E2E suites.** Not part of the run, so defences living there
  are uncredited. Some S1–S7 escapes may already be caught there; the triage in
  DoD #3 should check before writing a duplicate unit test.

## Traceability: escape cluster → story

| File | Escapes | Dominant mutator | Story |
|---|---|---|---|
| `StripeRefundRequestHandler.php` | 27 | `MethodCallRemoval×12` | S1 |
| `StripePaymentStatusHandler.php` | 22 | `MethodCallRemoval×8` | S2 |
| `CheckoutSessionService.php` | 22 | `ArrayItemRemoval×4` | S3 |
| `StaticContent.php` | 20 | `Coalesce×4` | S4 |
| `CustomerDataSanitizer.php` | 7 | `IncrementInteger×2`, `ReturnRemoval×1` | S5 |
| `CheckoutReturnService.php` | 5 | `ArrayItemRemoval×3` | S7 |
| `ShopId.php` | 3 | `GreaterThanOrEqualTo×2` | S7 |
| `OpcExternalReturnCleanupHandler.php` | 3 | `MethodCallRemoval×2` | S6 |
| `CheckoutSessionExpiredWebhookHandler.php` | 3 | `MethodCallRemoval×2` | S6 |
| `StripeWebhookEventParser.php` | 2 | — | S7 |
| `PaymentIntentResolver.php` | 2 | — | S7 |
| `BasketBuyabilityValidator.php` | 2 | `Foreach×1` | S7 |
| 4 further files | 1 each | — | S7 |
| **Total** | **122** across **16 files** | `MethodCallRemoval` **28** overall | — |

All 122 escapes assigned; none deferred without a story. Full per-mutant list
(file, line, mutator) in the source report.

## Note for the record

This finding has an external counterpart. Hora & Robbes, *"Are Coding Agents
Generating Over-Mocked Tests? An Empirical Study"* (MSR 2026), study exactly this
phenomenon in agent-authored suites — tests that appear complete but pass without
verifying the intended behaviour. Until this run, our own evidence for it was
anecdotal: the Sprint-17 `assertTrue(true)` false-positive tests and the 34%
silent-skip incident. **`MethodCallRemoval = 28/122` is the quantitative version
of the same defect, measured in our own code, after the remediation that was
supposed to have removed it.** Worth citing both ways: the paper explains what we
found, and this sprint is a documented instance of what the paper describes.
