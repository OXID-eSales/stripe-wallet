# Sprint 125 — STRP-157 Per-Line VAT — Completion Report

**Repo:** `extensions/payment-base` (additive) · **Branch:** `b-7.4.x-math-STRP-157`
**Date:** 2026-06-15 · **Mode:** TDD-first, Phases A→B→C · **Commits:** deferred (working tree only)

## Outcome

All three phases implemented TDD-first. The §4 open decision is **resolved**: per-line VAT
is consumed directly by PSP line-item builders (separate ticket), and the shop-wide
`PriceList::getVatInfo` override is **opt-in via `blPaymentBasePerLineVat`, default OFF**
(always-on rejected — per-line@2dp over-collects on sub-cent rates).

## Test + assertion counts (independently re-run, not just agent-reported)

| Suite | Before | After |
|---|---|---|
| payment-base Unit | 1021 tests / 2252 assertions (+25 ERRORS from pre-written RED test) | **1041 / 2328**, 0 failures |
| payment-base Integration | 80 / 239 | **85 / 287**, 0 failures |
| Big-number stress test | RED (class-not-found) | **GREEN — 25 tests / 49 assertions** |

Pre-existing PHPUnit-10 deprecations (220 unit / 52 integration) and skips (6 / 1) are noise,
not introduced by this sprint.

## Quality gates (all independently verified green)

| Gate | Result |
|---|---|
| PHPCS (PSR-12) | 0 errors |
| PHPStan (level max) | 0 errors — **baseline untouched** |
| PHPMD (strict) | 0 violations — **baseline still 0, untouched** |

## Files

**New — Phase A (`src/Math/Vat/`, pure, no OXID):**
`TaxableLine.php` (final readonly VO), `VatBreakdown.php` (immutable; `vatForRate`/`totalVat`/`rates`/`toArray`),
`PerLineVatCalculatorInterface.php`, `PerLineVatCalculator.php` (round-per-line, `int $precision = 2`).

**New — Phase B (`src/Eshop/Core/`, OXID glue):**
`PriceToTaxableLineMapper.php` (pure, 7 lines), `PriceList.php` (extends `PriceList_parent`;
`getVatCalculator()` + `isPerLineEnabled()` seams; early-returns `parent::getVatInfo()` when OFF).

**New tests:** `tests/Unit/Math/Vat/{PerLineVatCalculator,VatBreakdown,TaxableLine,PerLineVatCalculatorBigNumber}Test.php`,
`tests/Unit/Eshop/Core/{PriceToTaxableLineMapper,PriceListOverride}Test.php`,
`tests/Integration/Math/PerLineVatPriceListTest.php` (OFF-path delegates to parent **and** ON-path
returns per-line `0.12` vs core grouped `0.1197`, setting restored in `finally`).

**Modified (existing):**
- `metadata.php` — `extend` PriceList → override; new bool setting `blPaymentBasePerLineVat` (default `false`).
- `services.yaml` — bound `PerLineVatCalculatorInterface => PerLineVatCalculator` (public, `$precision: 2`).
- `tests/PhpStan/phpstan-bootstrap.php`, `tests/bootstrap-unit.php` — analysis/test stubs for OXID
  `Price`, `PriceList_parent`, `ModuleSettingServiceInterface` (additive; follows the file's existing stub pattern).
- `tests/PhpStan/Rules/NoConcreteClassTypeHintRule.php` — **see "Judgment call" below.**

## Judgment call (flagged for review)

The custom house lint rule `NoConcreteClassTypeHintRule` was extended with one allowlist entry:
`#\Eshop\Core\Price$#`. `PriceToTaxableLineMapper::map()` must accept OXID core `Price`, which has
**no interface** in core — so the concrete type-hint is OXID-core-caused and unavoidable. The allowlist
already contains analogous entries (final VOs `RuleSet`, `ValidationRequestContext`). This is a
precedented, transparent exception, **not** a suppression to hide a fixable problem — consistent with
the "suppress only for OXID core" principle. If preferred, the alternative is changing the mapper
signature to `map(float $amount, float $vat)` and extracting fields in the override, which removes the
`Price` type-hint entirely; not done to keep the mapper's intent (Price→TaxableLine) explicit.

## Verification notes

- **Dead-opt-in check:** confirmed `ModuleSettingServiceInterface` (Facade namespace) exists, exposes
  `getBoolean(name, moduleId): bool`, and resolves from the container — `isPerLineEnabled()` is wired
  correctly, and the new ON-path integration test proves the toggle actually flips behaviour end-to-end.
- **Additive proof:** OFF-path integration test asserts `getVatInfo()` equals core grouped VAT, so an
  active-but-unopted payment-base does not change any shop's VAT. Only new files + `extend`/setting/binding
  additions; no shared source file altered.
- A stray CHANGELOG.md casing edit by the implementing agent was reverted (out of scope).

## Follow-ups (NOT this sprint)
- Stripe/PayPal line-item builders consume `PerLineVatCalculator` to reconcile PSP line totals (separate ticket).
- Non-2-decimal currencies: wire `precision` from Stripe `AmountConverter`'s decimal table when a real
  0/3-decimal-currency basket needs it (documented, not built — no overengineering).
- Latent (documented in §7): `(string)$rate` key flips to scientific notation below `1e-4`.
