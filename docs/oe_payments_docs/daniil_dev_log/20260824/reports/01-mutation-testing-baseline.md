# Mutation-testing baseline — Stripe module

**Date:** 2026-08-24
**Tool:** Infection 0.31.9 (PHP 8.3, project Docker container)
**Suite state at run time:** 1,509 tests · 3,934 assertions · **green** (0 failures, 0 errors)
**Purpose:** establish, for the first time, whether this module's test suite *detects* defects rather than merely *executing* code.

---

## 1. Headline

| Metric | Value |
|---|---|
| Mutants generated (covered code) | **462** |
| Killed by test framework | **340** |
| Killed by static analysis | 0 |
| **Escaped (undetected)** | **122** |
| Errored / timed out / skipped | 0 / 0 / 0 |
| Mutation code coverage | **100%** |
| **Covered Code MSI** | **73%** |

**Read it as two facts, not one.** The suite executes every line the mutants
were placed in — mutation code coverage is 100%, there are no uncovered
mutants — and it detects roughly three quarters of the semantic changes made to
those lines. 73% is a respectable figure for a real industrial suite. It is also
**27% of mutations in tested code going unnoticed**, and nothing we measured
before this run could have told us which 27%.

For context, the metrics that were already green while this gap existed:

| Existing metric | Value | What it missed |
|---|---|---|
| Line/mutation coverage | 100% (of mutated code) | whether execution implies detection |
| Test-to-source write ratio | 1.69 : 1 | ditto |
| Assertions per test method | ≈ 2.28 (stripe) | assertion *count* is not assertion *strength* |

## 2. Run configuration

```
source.directories = src/Stripe/EventSystem/Handler
                     src/Stripe/Core
                     src/Stripe/Webhook
                     src/Stripe/Service
testFrameworkOptions = --testsuite=Unit
mutators             = @default, minus CastInt and CastString
threads              = 4
```

Mutator exclusions follow the module's pre-existing `infection.json5`.

**Secondary run, for comparison.** The repo's existing narrow config
(`EventSystem/Handler` only, tests filtered to `--filter=Handler`) scores
**415 mutants, 227 killed, 188 escaped — MSI 54%**. The same handler code scores
**54% against its own tests and 73% against the whole unit suite**: cross-cutting
tests supply about a third of the kills. Do not read a per-module MSI as a
property of that module's tests.

## 3. What is strong

**The money path kills everything.**

| Class | Escaped mutants |
|---|---|
| `AmountConverter` | **0** |
| `MinorUnitConverter` | **0** |
| `CapturableAmount` | **0** |

This is the Sprint-114.7 consolidation ("~22 hand-coded `* 100` / `/ 100` sites
→ 1") and the four real-money truncation bugs it surfaced. The refactor did not
merely centralise the arithmetic — it left it **genuinely verified** at the
strongest level of test-effectiveness evidence available to us. These three
classes are the regression bar for Sprint 135.

## 4. What is weak

Escapes concentrate in the **orchestration layer** — handlers and services that
wire the verified value types together.

| File | Escaped |
|---|---|
| `StripeRefundRequestHandler.php` | 27 |
| `StripePaymentStatusHandler.php` | 22 |
| `CheckoutSessionService.php` | 22 |
| `StaticContent.php` | 20 |
| `CustomerDataSanitizer.php` | 7 |
| `CheckoutReturnService.php` | 5 |
| `ShopId.php` | 3 |
| `OpcExternalReturnCleanupHandler.php` | 3 |
| `CheckoutSessionExpiredWebhookHandler.php` | 3 |
| 7 further files | 1–2 each |
| **Total** | **122** across **16 files** |

### Mutators, ranked

| Mutator | Escapes | What an escape means |
|---|---|---|
| **`MethodCallRemoval`** | **28** | **the call can be deleted and the suite stays green** |
| `ArrayItemRemoval` | 18 | an array entry can vanish undetected |
| `ArrayItem` | 16 | an array value can change undetected |
| `Coalesce` | 7 | `??` operands can swap undetected |
| `LogicalAnd` | 6 | a compound condition can be weakened undetected |
| `ConcatOperandRemoval` | 6 | a string can lose an operand undetected |
| `IncrementInteger` | 5 | an off-by-one passes |
| `Concat` / `DecrementInteger` | 4 each | — |
| `CastFloat` | 3 | — |
| others | 1–2 each | — |

**`MethodCallRemoval` at 28/122 is the finding.** It is the over-mocking
signature: a test builds a double, exercises the unit, and asserts against the
double's canned return rather than against what the unit actually did. The call
is executed, never verified.

This module has met this defect before. Sprint 17 removed
"false-positive tests" whose assertions were hidden inside
`willReturnCallback` and effectively ran `assertTrue(true)`; rule **R-1.5** was
written to ban re-implementing the method under test inside a double. **106
`willReturnCallback` occurrences remain in the two trees.** The rule addressed
the double faking the *logic*; it did not address the assertion being
satisfiable without the *call*.

### Single most alarming individual mutant

`CustomerDataSanitizer.php` — **`ReturnRemoval`**. A return statement can be
deleted with the suite green, in the GDPR payload-redaction path (finding H7).
Low file total (7), highest severity per mutant.

## 5. Environment prerequisite — and why this had never been run

The suite **cannot be run against the developer shop as configured.** Eight
modules are active locally, and the `mollie → paypal` class-extension chain
aborts the bootstrap:

```
Class "OxidEsales\Payments\PayPal\Controller\PaymentController_parent" not found
  at ModuleChainsGenerator->createClassExtension(...)
```

CI activates only `oe_payment_base` and `oe_payments_stripe_wallet`. Matching
that locally — deactivating `oe_payments_mollie`, `opalreturns`,
`opalsubscription`, `oe_onepage_checkout`, `oe_payments_paypal` — makes the suite
green (1,509 / 3,934) and the mutation run possible.

This is a textbook local-vs-CI divergence, and it is the likely reason a
mutation baseline was never established despite Infection having been a declared
dev-dependency with a committed config. **It belongs in `docs/for_developer/`.**
The shop configuration for this run was snapshotted beforehand and restored
bit-for-bit afterwards; no module state was left changed.

## 6. Caveats on these numbers

- **Covered code only.** `--with-uncovered` aborts on shop-coupled classes
  (`ViewConfig` extends the OXID chain). MSI is therefore over code the unit
  tests already execute, **not** over the module.
- **`stripe` only.** `payment-base` (915 test methods) has **no mutation
  baseline at all**.
- **No equivalent-mutant triage.** Some of the 122 are inevitably harmless;
  Infection says so itself. None were manually classified — Sprint 135 DoD #3
  requires that each survivor is either killed or argued to be equivalent.
- **Unit suite only.** Integration and E2E defences are uncredited; some escapes
  may already be covered there.

## 7. Per-mutant detail

Full list, escaped mutants only, ordered by file then line.

### `src/Stripe/EventSystem/Handler/StripeRefundRequestHandler.php` — 27 escaped

| Line | Mutator |
|---|---|
| 62 | `MethodCallRemoval` |
| 65 | `MethodCallRemoval` |
| 72 | `ArrayItemRemoval` |
| 72 | `MethodCallRemoval` |
| 73 | `ArrayItem` |
| 74 | `ArrayItem` |
| 75 | `ArrayItem` |
| 78 | `MethodCallRemoval` |
| 116 | `Coalesce` |
| 181 | `MethodCallRemoval` |
| 182 | `MethodCallRemoval` |
| 199 | `Coalesce` |
| 211 | `MethodCallRemoval` |
| 213 | `ArrayItem` |
| 213 | `ArrayItemRemoval` |
| 214 | `ArrayItemRemoval` |
| 215 | `ArrayItem` |
| 216 | `ArrayItem` |
| 217 | `ArrayItem` |
| 227 | `MethodCallRemoval` |
| 228 | `MethodCallRemoval` |
| 229 | `MethodCallRemoval` |
| 230 | `MethodCallRemoval` |
| 232 | `ArrayItemRemoval` |
| 232 | `MethodCallRemoval` |
| 233 | `ArrayItem` |
| 234 | `ArrayItem` |

### `src/Stripe/EventSystem/Handler/StripePaymentStatusHandler.php` — 22 escaped

| Line | Mutator |
|---|---|
| 50 | `MethodCallRemoval` |
| 53 | `MethodCallRemoval` |
| 62 | `MethodCallRemoval` |
| 64 | `ArrayItemRemoval` |
| 64 | `MethodCallRemoval` |
| 77 | `LogicalAnd` |
| 81 | `ArrayItemRemoval` |
| 81 | `MethodCallRemoval` |
| 91 | `ArrayItemRemoval` |
| 91 | `MethodCallRemoval` |
| 92 | `ArrayItem` |
| 93 | `ArrayItem` |
| 115 | `ArrayItemRemoval` |
| 115 | `MethodCallRemoval` |
| 116 | `ArrayItem` |
| 137 | `NotIdentical` |
| 170 | `ArrayItemRemoval` |
| 170 | `MethodCallRemoval` |
| 171 | `ArrayItem` |
| 173 | `Concat` |
| 173 | `ConcatOperandRemoval` |
| 173 | `ConcatOperandRemoval` |

### `src/Stripe/Service/CheckoutSessionService.php` — 22 escaped

| Line | Mutator |
|---|---|
| 87 | `ArrayItemRemoval` |
| 129 | `ArrayItemRemoval` |
| 131 | `ArrayItem` |
| 132 | `ArrayItem` |
| 157 | `ReturnRemoval` |
| 228 | `LogicalAnd` |
| 236 | `LogicalOrAllSubExprNegation` |
| 236 | `LogicalOrSingleSubExprNegation` |
| 236 | `LogicalAnd` |
| 237 | `CastFloat` |
| 238 | `OneZeroFloat` |
| 246 | `LogicalAnd` |
| 246 | `DecrementInteger` |
| 246 | `IncrementInteger` |
| 264 | `ArrayItemRemoval` |
| 267 | `ArrayItemRemoval` |
| 287 | `Concat` |
| 287 | `ConcatOperandRemoval` |
| 295 | `NotIdentical` |
| 296 | `Concat` |
| 296 | `ConcatOperandRemoval` |
| 296 | `ConcatOperandRemoval` |

### `src/Stripe/Service/StaticContent.php` — 20 escaped

| Line | Mutator |
|---|---|
| 49 | `Continue` |
| 114 | `ProtectedVisibility` |
| 117 | `MethodCallRemoval` |
| 121 | `Ternary` |
| 126 | `FalseValue` |
| 126 | `Coalesce` |
| 126 | `CastBool` |
| 127 | `DecrementInteger` |
| 127 | `IncrementInteger` |
| 127 | `Coalesce` |
| 127 | `CastFloat` |
| 128 | `DecrementInteger` |
| 128 | `IncrementInteger` |
| 128 | `Coalesce` |
| 128 | `CastFloat` |
| 129 | `Coalesce` |
| 132 | `MethodCallRemoval` |
| 149 | `ProtectedVisibility` |
| 158 | `LogicalOr` |
| 173 | `MethodCallRemoval` |

### `src/Stripe/Service/CustomerDataSanitizer.php` — 7 escaped

| Line | Mutator |
|---|---|
| 25 | `DecrementInteger` |
| 25 | `IncrementInteger` |
| 28 | `ReturnRemoval` |
| 42 | `MBString` |
| 42 | `GreaterThan` |
| 43 | `IncrementInteger` |
| 43 | `MBString` |

### `src/Stripe/Service/CheckoutReturnService.php` — 5 escaped

| Line | Mutator |
|---|---|
| 53 | `ArrayItemRemoval` |
| 87 | `ArrayItemRemoval` |
| 103 | `UnwrapStrToUpper` |
| 124 | `ArrayItemRemoval` |
| 126 | `ArrayItem` |

### `src/Stripe/Core/ShopId.php` — 3 escaped

| Line | Mutator |
|---|---|
| 36 | `GreaterThanOrEqualTo` |
| 40 | `LogicalAnd` |
| 40 | `GreaterThanOrEqualTo` |

### `src/Stripe/EventSystem/Handler/OpcExternalReturnCleanupHandler.php` — 3 escaped

| Line | Mutator |
|---|---|
| 72 | `ArrayItemRemoval` |
| 72 | `MethodCallRemoval` |
| 88 | `MethodCallRemoval` |

### `src/Stripe/Webhook/Handler/CheckoutSessionExpiredWebhookHandler.php` — 3 escaped

| Line | Mutator |
|---|---|
| 50 | `MethodCallRemoval` |
| 54 | `ArrayItemRemoval` |
| 54 | `MethodCallRemoval` |

### `src/Stripe/Service/BasketBuyabilityValidator.php` — 2 escaped

| Line | Mutator |
|---|---|
| 37 | `Foreach` |
| 50 | `ArrayOneItem` |

### `src/Stripe/Service/PaymentIntentResolver.php` — 2 escaped

| Line | Mutator |
|---|---|
| 48 | `Concat` |
| 48 | `ConcatOperandRemoval` |

### `src/Stripe/Webhook/StripeWebhookEventParser.php` — 2 escaped

| Line | Mutator |
|---|---|
| 96 | `LogicalAnd` |
| 97 | `UnwrapStrToUpper` |

### `src/Stripe/Service/ModuleConfigurationService.php` — 1 escaped

| Line | Mutator |
|---|---|
| 386 | `CastBool` |

### `src/Stripe/Service/OxidUserFieldReader.php` — 1 escaped

| Line | Mutator |
|---|---|
| 57 | `InstanceOf` |

### `src/Stripe/Service/Result/CheckoutReturnResult.php` — 1 escaped

| Line | Mutator |
|---|---|
| 126 | `Coalesce` |

### `src/Stripe/Webhook/StripeWebhookProcessor.php` — 1 escaped

| Line | Mutator |
|---|---|
| 52 | `MethodCallRemoval` |
