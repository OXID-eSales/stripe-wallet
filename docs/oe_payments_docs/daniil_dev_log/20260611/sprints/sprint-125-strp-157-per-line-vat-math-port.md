# Sprint 125 — STRP-157 Port Per-Line-VAT into payment-base as a provider-agnostic Math/VAT component

**Repo:** `extensions/payment-base` (additive) · **Branch:** `b-7.4.x-math-STRP-157` (created off `b-7.4.x` @ `57736fd`)
**Source to port:** [Fresh-Advance/OXID-Per-Line-VAT @ b-7.5.x](https://github.com/Fresh-Advance/OXID-Per-Line-VAT/tree/b-7.5.x) (MIT, © MB Arbatos Klubas)
**Mode:** TDD-first, multi-commit per phase (A → B → C). Each phase its own RED→GREEN→REFACTOR.
**Core requirements (binding every commit):** TDD · SOLID · DRY · Clean Code · **No overengineering**.

---

## 1. What the source module does (and why it matters here)

The upstream module is 3 files. Its entire behaviour:

OXID core computes VAT **grouped**: it sums each VAT-rate bucket's net (or gross)
amount across the whole basket, then applies the rate **once** and rounds **once**.
Per-Line-VAT instead computes VAT **per line item**, rounds **each line**, then
sums. The two differ by rounding residue (sum-of-rounded ≠ rounded-of-sum).

Upstream core (`PriceListVatCalculator::getVatInformation`):

```php
foreach ($prices as $onePrice) {
    $vat = $isNettoMode
        ? $onePrice->getPrice() * $onePrice->getVat() / 100
        : $onePrice->getPrice() * $onePrice->getVat() / (100 + $onePrice->getVat());
    $key = (string)$onePrice->getVat();
    $aVatValues[$key] = ($aVatValues[$key] ?? 0) + $this->utils->fRound($vat); // round PER LINE
}
```

It is wired by overriding `\OxidEsales\Eshop\Core\PriceList::getVatInfo()` (a
shop-wide change) and resolves the calculator via `ContainerFacade::get(...)`.

**Why port it into payment-base (the "why" for STRP-157 / `math` branch):** a PSP
payload (Stripe `line_items` + `tax`, PayPal `amount.breakdown`) must have its
per-line amounts and tax reconcile **exactly** to the charged total. OXID's
*grouped* rounding can produce a basket whose per-line sum ≠ the grouped total by
1 cent, which PSPs reject as an amount mismatch. payment-base already centralises
money conversion in Stripe's `AmountConverter` (minor-units), but VAT math lives
nowhere agnostic. This sprint gives the payment layer a **correct, reusable,
framework-free per-line VAT calculator** that Stripe and PayPal can both build
line items from — and (optionally) restores the upstream shop-wide behaviour.

## 2. What we will NOT copy verbatim (clean-code / SOLID redesign)

The upstream code is fine for a standalone module but violates our requirements
if pasted as-is. We redesign, not copy:

| Upstream | Problem under our rules | Our design |
|---|---|---|
| Calculator takes `OxidEsales\Eshop\Core\Price[]` | OXID coupling in the math core (untestable without shop) | Pure VO `TaxableLine` (amount + rate). No OXID in the math namespace. |
| `Utils::fRound` injected into the math | Couples math to the shop runtime (DIP violation, hard to unit-test) | Plain `round($x, $precision)`, `precision` an `int` ctor arg (default 2). **No Rounder interface** (R-9 no-overengineering). |
| Returns raw `array<string,float>` | Stringly-typed; callers re-derive totals | Immutable `VatBreakdown` VO (`vatForRate()`, `totalVat()`, `rates()`). |
| `ContainerFacade::get()` inside `getVatInfo` | Service-locator in business logic | Calculator injected into consumers; the OXID core override isolates the one unavoidable container lookup behind a single overridable seam. |

These are exactly the redesign moves recorded in memory
([[feedback_static_for_pure_utilities]], [[feedback_provider_local_outcome_vo]],
[[feedback_oxid_dao_mocking]]): pure deterministic math, swappable behind a
narrow interface only where a real consumer needs to inject it, VO over raw array,
no speculative abstractions.

## 3. Architecture (target files)

```
extensions/payment-base/                       (additive only)
└── src/
    └── Math/Vat/                              # NEW namespace OxidEsales\PaymentBase\Math\Vat
        ├── TaxableLine.php                    # NEW final, immutable VO: float $amount, float $vatRatePercent
        ├── VatBreakdown.php                   # NEW final, immutable VO over array<numeric-string,float>
        ├── PerLineVatCalculatorInterface.php  # NEW — calculate(array<TaxableLine>, bool $netMode): VatBreakdown
        └── PerLineVatCalculator.php           # NEW final — the pure algorithm; ctor (int $precision = 2)
    └── Eshop/Core/                            # OXID glue (Phase C only)
        ├── PriceList.php                      # NEW extends PriceList_parent; overrides getVatInfo()
        └── PriceToTaxableLineMapper.php       # NEW — maps OXID Price -> TaxableLine (DRY, pure)
```

- **Phase A** ships `Math/Vat/*` (pure, no OXID) — independently useful by PSPs.
- **Phase B** ships the OXID mapper + `PriceList` override class, **unregistered**
  (no behaviour change yet) — tested via a testable subclass.
- **Phase C** registers the override (metadata.php `extend` + services.yaml) —
  the only commit that changes shop-wide behaviour; separately revertable.

### Interface (narrow — ISP)
```php
interface PerLineVatCalculatorInterface
{
    /** @param list<TaxableLine> $lines */
    public function calculate(array $lines, bool $netMode): VatBreakdown;
}
```

### Why an interface here but NOT for rounding
Consumers (Stripe/PayPal line-item builders, the OXID override) **inject** the
calculator and mock it in their own tests → a swappable seam is justified (R-4).
Rounding is a stateless one-liner with no alternative implementation anyone needs
to inject → an `int $precision` arg, not a `RounderInterface` (R-9).

## 4. RESOLVED DECISION (2026-06-15): PSP-direct, shop-wide override opt-in/default-off

The upstream module changes VAT for the **entire shop**. Restoring that in
payment-base means every basket in any shop with payment-base active switches to
per-line VAT — a broad behavioural change for a *payment* package.

**Decision (resolved):** the calculator is consumed **directly by the Stripe/PayPal
line-item builders** — that is the actual STRP-157 win (sum-of-lines reconciles to
the charged total) and it does **not** depend on the shop-wide override. The
shop-wide `getVatInfo` override (Phase C) lands **opt-in via `blPaymentBasePerLineVat`,
default OFF**; never always-on.

**Why not always-on shop-wide:** per-line@2dp is correct *for PSP reconciliation* but
is **not** a strict improvement on grouped VAT — it over-collects on sub-cent rates
(a `0.0001%` tax bills a full cent per line; 100 lines = 1.00 vs a correct ~0.0095;
see §5 matrix and §7 risk). Applying it to every basket shop-wide would silently
change tax on micro-rate / many-line baskets. So: per-line where it's *needed and
correct* (PSP payloads), grouped everywhere else unless an operator deliberately
opts in.

> Exact upstream parity (per-line shop-wide, always-on) is explicitly rejected for
> this reason. Phase C keeps the setting; do not drop it.

## 5. TDD plan

### Phase A — pure Math/Vat core (payment-base, no OXID)

**RED** (`tests/Unit/Math/Vat/`):
1. `PerLineVatCalculatorTest::netMode_singleLine` — one line `amount=100.0, rate=19` → `vatForRate(19)=19.0`.
2. `…::grossMode_singleLine` — `amount=119.0, rate=19` → `19.0` (119*19/119).
3. `…::accumulatesMultipleLinesSameRate` — two `[10.0@19, 20.0@19]` → `5.7` (1.9+3.8).
4. `…::roundsPerLineNotOnSum` — the defining case: three `[0.10@19]` lines →
   per-line `round(0.019,2)=0.02` ×3 = **0.06**, NOT `round(0.057,2)=0.06`… choose
   a case where they diverge, e.g. lines that each round up: `[0.21@19]`×3 →
   per-line `round(0.0399)=0.04`×3=**0.12** vs grouped `round(0.1197)=0.12`. Pick
   concrete values during RED that demonstrably differ and assert the per-line sum.
5. `…::multipleRatesKeyedSeparately` — `[100@19, 100@7]` → two keys `19=>19.0`, `7=>7.0`.
6. `…::emptyListYieldsEmptyBreakdown` — `[]` → `rates()===[]`, `totalVat()===0.0`.
7. `…::zeroRateProducesZeroVat` — `[50@0]` → `0=>0.0`.
8. `…::customPrecisionRespected` — ctor `precision=3` changes a boundary result.
9. `VatBreakdownTest` — `vatForRate(missing)` returns `0.0`; `totalVat()` sums; `rates()` returns keys; VO is immutable (no setters).
10. `TaxableLineTest` — constructs and exposes `amount` / `vatRatePercent`; readonly.

**GREEN:** implement the four classes. Algorithm mirrors upstream (net `a*r/100`,
gross `a*r/(100+r)`, round each line to `precision`, accumulate by `(string)rate`).
`calculate()` ≤ 25 lines, early-return on empty, no `else`.

#### Phase A — big-number / irrational-division stress matrix (added, RED-ready)

Implemented as `tests/Unit/Math/Vat/PerLineVatCalculatorBigNumberTest.php`
(25 cases, currently RED — class-not-found — which is correct TDD RED). All use
a deliberately ugly line amount **9456.31415927** (10 fractional digits) so the
2-dp rounding boundary is always exercised. Expected values precomputed on PHP
8.3 `round()` (half-away-from-zero) at `precision=2`.

**NET mode, single big line** (`vat = amount·rate/100`, rounded 2dp):

| rate % | raw vat | vat@2dp |
|---|---|---|
| 6.66 | 629.790523… | **629.79** |
| 0.0001 | 0.009456314… | **0.01** ← sub-cent tax rounded UP to a full cent |
| 29.27 | 2767.863154… | **2767.86** |
| 19 | 1796.699690… | **1796.70** |
| 7 | 661.941991… | **661.94** |
| 3.3 | 312.058367… | **312.06** |
| 42.34567 | 4004.339588… | **4004.34** |

**GROSS mode, single big line** (`vat = amount·rate/(100+rate)` — irrational division):

| rate % | raw vat | vat@2dp |
|---|---|---|
| 6.66 | 590.465519… | **590.47** |
| 0.0001 | 0.009456304… | **0.01** |
| 29.27 | 2141.148877… | **2141.15** |
| 19 | 1509.831672… | **1509.83** |
| 7 | 618.637374… | **618.64** |
| 3.3 | 302.089416… | **302.09** |
| 42.34567 | 2813.109515… | **2813.11** |

**Per-line vs grouped divergence** (N identical big lines, NET — the PSP-reject the
sprint exists to prevent). per-line is what this calculator MUST emit:

| rate × N | per-line sum | grouped (core) | Δ |
|---|---|---|---|
| 29.27 × 3 | 8303.58 | 8303.59 | −0.01 |
| 7 × 3 | 1985.82 | 1985.83 | −0.01 |
| 29.27 × 100 | 276786.00 | 276786.32 | −0.32 |
| 7 × 100 | 66194.00 | 66194.20 | −0.20 |
| 19 × 100 | 179670.00 | 179669.97 | +0.03 |
| 3.3 × 100 | 31206.00 | 31205.84 | +0.16 |
| **0.0001 × 100** | **1.00** | 0.95 | **+0.05 (over-collection)** |

**Mixed-rate basket** (one big line each @19/7/3.3/42.34567/0.0001, NET): keys kept
separate, `totalVat() = 6775.05`.

**Precision lever** on the 0.0001% line (raw 0.009456314…): `p=2 → 0.01`,
`p=3 → 0.009`, `p=4 → 0.0095`.

**Key-stability boundary:** rate `0.0001` serializes to `'0.0001'` (safe). Pinned by
a guard test because anything `< 1e-4` flips to scientific notation (`'1.0E-5'`) —
see Risk note in §7.

**Gate:** payment-base `composer phpcs && phpstan && phpmd && phpunit` green.
Additive-only ⇒ paypal + OPC + Stripe unit suites byte-identical (this code is not
yet referenced anywhere). **No PHPStan/PHPMD baseline growth.** No commit (working-tree only) unless you say otherwise.

### Phase B — OXID mapper + `PriceList` override (registered NOWHERE yet)

**RED** (`tests/Unit/Eshop/Core/`):
1. `PriceToTaxableLineMapperTest::mapsPriceFields` — a `Price` stub
   (`getPrice()=100.0`, `getVat()=19.0`) maps to `TaxableLine(100.0, 19.0)`.
2. `PriceListOverrideTest::delegatesToCalculatorAndReturnsCoreShape` — testable
   subclass exposing the `_aList` seam + a fake `PerLineVatCalculatorInterface`;
   assert `getVatInfo(true)` maps each `Price` → line, calls `calculate(lines, true)`,
   and returns `array<numeric-string,float>` (drop-in shape parity with core).
3. `…::nettoFlagForwarded` — `getVatInfo(false)` forwards `netMode=false`.
4. `…::emptyListReturnsEmptyArray` — no prices → `[]`.

**GREEN:**
- `PriceToTaxableLineMapper` (pure, ≤15 lines).
- `PriceList extends PriceList_parent`, `getVatInfo($isNettoMode = true)`:
  map `$this->_aList` → lines (verify the inherited property name/visibility on
  the installed 7.4 core during RED — keep the underscore only because it is an
  inherited field, per [[feedback_no_underscore_prefix]]), delegate, return
  `$breakdown->toArray()`. Resolve the calculator through a single protected
  `getVatCalculator()` seam (the only container touch — overridden in tests, not a
  service-locator sprinkled through logic). `@phpstan-ignore` only for the
  virtual `PriceList_parent` per house rules.

**Gate:** as Phase A. The override class exists but is **not** in `metadata.php`,
so still zero behavioural change; PSP/OPC suites unchanged.

### Phase C — activation (the only shop-wide change) + integration

**RED:**
1. `tests/Integration/Math/PerLineVatPriceListTest` — boot container, build a real
   `PriceList` via `oxNew`, add real `Price` items, assert `getVatInfo()` returns
   the **per-line** numbers (differs from core grouped on a chosen basket).
2. (opt-in path) `…::overrideInactiveWhenSettingOff` / `…ActiveWhenSettingOn`.

**GREEN:**
- `metadata.php`: add `extend` `[\OxidEsales\Eshop\Core\PriceList::class => \OxidEsales\PaymentBase\Eshop\Core\PriceList::class]` (currently `'extend' => []`).
- `services.yaml`: register `PerLineVatCalculatorInterface => PerLineVatCalculator`
  (public so the seam resolves it; consumers autowire it).
- Opt-in: new bool setting `blPaymentBasePerLineVat` (default off) consulted in the
  override seam; off ⇒ `return parent::getVatInfo($isNettoMode)`.
- `oe:cache:clear` + `docker compose restart php` after metadata/services edits
  ([[feedback_php_opcache_fpm]]).

**Gate:** full payment-base suite green; integration boots; **paypal + OPC suites
re-run and counts compared** — with the setting **off** they must be byte-identical
(proves no accidental global change). Activation behaviour proven only with the
setting on.

## 6. Requirement-by-requirement compliance

- **TDD** — every phase RED before GREEN; calculator/override tested via real
  instances over a mocked narrow interface (no SUT re-implemented in a double).
- **SOLID** — SRP (calculator ≠ mapper ≠ VO ≠ override); ISP (one-method
  interface); DIP (consumers + override depend on the interface, not the concrete);
  OCP (PSPs add line-item builders without touching the calculator); LSP (final
  impl substitutable behind the interface; VOs immutable).
- **DRY** — one calculator + one mapper reused by the OXID override AND every PSP
  line-item builder; no per-provider VAT math.
- **No overengineering** — no Money library, no Rounder interface, no currency
  table here (precision is an int; non-2-decimal currencies are a *documented
  follow-up* tied to Stripe's `AmountConverter`, not built now); VOs only where
  they remove stringly-typed access.
- **Clean Code** — ≤25-line methods, early returns/no `else`, explicit `use`
  imports, readonly VOs, no magic strings (rate keys derived, not literal).

## 7. Risks & rollback

- **Risk: shop-wide VAT change surprises non-payment flows.** Mitigated by the
  opt-in setting + isolating activation in Phase C (separately revertable). Default off.
- **Risk: `_aList` property name/visibility differs on 7.4 core.** Verify in
  Phase B RED against the installed core; if private, fall back to the public
  accessor the core exposes for the price list.
- **Risk: rounding parity vs `Utils::fRound`.** `fRound` rounds to currency
  decimals (2 for EUR) with PHP half-up; `round($x,2)` matches for 2-decimal
  currencies. A divergence test for a 0/3-decimal currency is the trigger to wire
  the precision from `AmountConverter`'s decimal table — **only if** a real basket
  needs it (no overengineering until proven).
- **Risk: payment-base additive contract.** No existing class touched; only new
  files + (Phase C) `extend`/`services.yaml`/`settings` additions. Run paypal +
  OPC + Stripe suites before and after each phase; A & B must match exactly,
  C must match with the setting off ([[feedback_payment_base_additive_only]]).
- **Rollback:** Phase A standalone (math only). B adds inactive glue. C is the one
  behavioural commit — `git revert` of C restores stock grouped VAT instantly.
- **Risk: `(string)$rate` key is not canonical.** Rates `< 1e-4` serialize to
  scientific notation (`(string)1e-5 === '1.0E-5'`) and irrational rates to 14
  sig-figs, so the bucket key is neither stable nor numerically comparable.
  Normal float drift is *masked* by PHP's 14-digit default precision
  (`(string)(0.1+0.2) === '0.3'`), so this only bites micro-rates / computed
  rates. Mitigation: a boundary guard test now; if real micro-rates appear, key by
  an integer scaled rate (e.g. `(int)round($rate*1000)`) instead of the float string.
- **Risk: per-line rounding OVER-collects on sub-cent rates.** A 0.0001% tax is
  ~0.0000945 per line but rounds to a full **0.01 each**; 100 lines bill 1.00 vs a
  correct ~0.0095 (a 100× over-charge). This is inherent to per-line@2dp, not a bug,
  but it means per-line VAT is *not* universally "more correct" than grouped — it is
  correct **for PSP reconciliation** (sum-of-lines = charged total) and wrong as a
  tax-fairness statement. Document this; do not present per-line as strictly superior.
- **Risk: `float` money + `round()`.** The whole motivation is exact PSP
  reconciliation, yet the core uses binary floats. PHP `round()` carries a
  half-away pre-correction (`round(2.675,2)=2.68` despite the 2.6749… repr), so 2dp
  results are reliable, but callers MUST treat the breakdown as cents and never
  re-sum raw floats. The minor-unit handoff to Stripe's `AmountConverter` is what
  actually closes the loop — flag if any consumer compares these floats with `===`.

## 8. Definition of Done

- [ ] Phases A–C implemented TDD-first on `b-7.4.x-math-STRP-157`; commits deferred to review.
- [ ] `composer phpcs && phpstan && phpmd && phpunit` green per phase; no baseline growth.
- [ ] paypal + OPC + Stripe suites unchanged (A/B always; C with setting off).
- [ ] Upstream MIT attribution noted in the new files' headers (© MB Arbatos Klubas, derived work).
- [x] Open decision in §4 resolved (2026-06-15): PSP builders consume the calculator
      directly; shop-wide override is opt-in `blPaymentBasePerLineVat`, default OFF
      (always-on rejected — micro-rate over-collection).
- [ ] Completion report `done/sprint-125-completion.md` with before/after test+assertion counts.
- [ ] Memory: new project note that payment-base owns `Math\Vat\PerLineVatCalculator`
      (provider-agnostic per-line VAT), and the opt-in shop-wide override decision.
- [ ] (Follow-up, not this sprint) Stripe/PayPal line-item builders consume the
      calculator to reconcile PSP line totals — separate ticket.
