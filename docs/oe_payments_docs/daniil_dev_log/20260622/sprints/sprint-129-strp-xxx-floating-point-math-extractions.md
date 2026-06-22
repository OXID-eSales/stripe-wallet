# Sprint 129 — Extract & de-duplicate floating-point monetary math (payment-base + stripe)

> Follow-up to the floating-point code review (`reports/02-floating-point-math-code-review.md`).
> The review found the Stripe wire path well-handled (integer minor units, centralised
> `AmountConverter`) but the OXID-float domain carrying **inline, untested, and duplicated**
> monetary arithmetic. This sprint implements the review's §5 extraction backlog: turn the
> scattered float math into small pure, tested units, and collapse three duplications into one
> home each. **No BCMath migration** — the review concluded it is not warranted (§6).

**Repos:** `extensions/payment-base` (additive-only) + `extensions/stripe` · **Branch:** `b-7.4.x`
**Ticket:** STRP-XXX (TBD) · **Type:** refactor / tech-debt (behaviour-preserving)
**Mode:** TDD-first. Each unit: write tests → extract → keep existing suites green as the parity net.
**Binding:** TDD · SOLID · DRY · Clean Code · No overengineering · PSR-12 · PHPStan max · static-for-pure-utilities.

---

## 1. Motivation (from the review)

Money is represented two ways, and math quality tracked the split:

- **Stripe wire amounts** — integer minor units (cents), centralised in `AmountConverter`. Good.
- **OXID shop amounts** — IEEE-754 `float`, guarded ad-hoc by `round()` + `0.005` epsilons. Mixed:
  some extracted & tested (`PerLineVatCalculator`, `CaptureRefundTracker`), some **inline & untested**.

Three concrete debts the review flagged:

| Review § | Debt | Risk |
|----------|------|------|
| 5.1 | `ContractService::extractProductItems()` computes `price × qty` inline in an array literal, 0 tests | feeds contract snapshot → Stripe line items; a wrong total is a PSP amount-mismatch reject |
| 5.2 | Two byte-identical, **currency-blind** `(int) round($x*100)` helpers in the MCP formatters; duplicates stripe's `AmountConverter` | wrong amounts for JPY (0-dec) / BHD (3-dec); cross-module duplication |
| 5.3 | `0.005` epsilon copy-pasted under 3 names (`FULL_REFUND_EPSILON`, `FULL_SUM_EPSILON`, `AMOUNT_EPSILON`) | drift between copies; re-derived comparisons |
| 5.4 | `CaptureService` over-capture math inline, only testable via the full service | boundary conditions not unit-isolated |

---

## 2. What was built

All new pure units live in `payment-base/src/Math/Money/` (a sibling to the proven `Math/Vat/`),
except the stripe-local capture helper.

### §5.1 — `LineItemAmount` (payment-base `Math\Money`)
`final readonly` VO with `forQuantity(unitPrice, netPrice, vatValue, quantity)`. Multiplies each
per-unit float by the integer quantity exactly as the old inline code did (no rounding introduced).
`ContractService::extractProductItems()` now calls it and reads `->totalPrice/->netPrice/->vatValue`.

### §5.2 — `MinorUnitConverter` (payment-base `Math\Money`)
Canonical currency-aware major↔minor converter (`decimalsFor` / `toMinorUnits` / `toMajorUnits`),
`(int) round()` to defeat IEEE-754 drift; owns the 0/2/3-decimal currency lists.
- Both MCP formatters (`AcpResponseFormatter`, `UcpResponseFormatter`) now call it with the snapshot
  currency and their private `toMinorUnits()` are **deleted** → JPY/BHD bug fixed, duplication gone.
- stripe `AmountConverter` now **delegates** all three methods to it → the currency lists live in
  **one** place. Public API unchanged; its 13 tests + 3 batch-characterization suites are the parity net.

### §5.3 — `Money` (payment-base `Math\Money`)
Single `HALF_CENT_EPSILON = 0.005` + `equals / greaterThan / atLeast / atMost`. Migrated all three
sites, each mapping exactly to the prior expression:
- `CaptureRefundTracker::getRemainingRefundableAmount()` (`< EPSILON`), `isFullyRefunded()` (`atLeast`)
- `RefundIntentHandler::isFullAmount()` (`equals`), `isPositiveAndWithinAuthorized()` (`atMost`)
- stripe `CaptureService` over-capture check (`greaterThan`)
The three private constants are **removed**.

### §5.4 — `CapturableAmount` (stripe `Service`, stripe-local)
Pure `remaining(authorized, captured)` + `isExceededBy(requested, authorized, captured)` (uses
`Money::greaterThan`). `CaptureService::processCapture()` delegates its inline over-capture guard to it.
Kept stripe-local (single consumer) per `feedback_provider_local_outcome_vo` + YAGNI.

---

## 3. Tests added (TDD)

| Unit | Test file | Tests |
|------|-----------|-------|
| `LineItemAmount` | `payment-base/.../Unit/Math/Money/LineItemAmountTest.php` | 9 |
| `MinorUnitConverter` | `payment-base/.../Unit/Math/Money/MinorUnitConverterTest.php` | 9 |
| `Money` | `payment-base/.../Unit/Math/Money/MoneyTest.php` | 20 |
| `CapturableAmount` | `stripe/.../Unit/Stripe/Service/CapturableAmountTest.php` | 13 |

Existing suites re-run as the behaviour-parity net: `CaptureRefundTrackerTest` (34),
`RefundIntentHandlerTest` (17), `AmountConverterTest` + 3 batch characterizations, MCP formatter tests,
`CaptureServiceTest` (25), `ContractServiceTest`.

---

## 4. Verification gates (all green, 2026-06-22)

```
payment-base Unit         1097 pass  (was 1054, +43)
payment-base Integration    85 pass  (1 skip)   — tests/phpunit-integration.xml
stripe       Unit         1296 pass
stripe       Integration    87 pass
PHPCS  0  ·  PHPStan 0 (level max)  ·  PHPMD 0 new (no suppressions added)  — both modules
```

> Note: payment-base integration tests are NOT in the module `phpunit.xml` "Unit" suite — they use the
> shop bootstrap via `tests/phpunit-integration.xml`. Running them through the unit config errors in
> setUp (`Registry::get()` undefined) — that is a wrong-harness symptom, not a regression.

---

## 5. Edit boundaries & decisions

- **payment-base additive-only** (`feedback_payment_base_additive_only`): only new classes + internal
  body swaps; no signature, return-type, or ctor-arg changes. paypal/OPC consumers unaffected.
- **Stripe wire path stays integer cents** — explicitly NOT migrated to BCMath (review §6). Integer
  minor units are exact and idiomatic; a rewrite would gain nothing and risk the characterization suite.
- **`AmountConverter` kept as the public facade** — stripe code + tests depend on it; delegation removes
  duplication without churning ~22 call sites.
- **Deferred (review action #6):** a string/BCMath-backed `Money` value object — only if a concrete
  decimal defect surfaces in the OXID-float VAT path. Not done (no defect observed).

---

## 6. DONE criteria

- [x] §5.1 `LineItemAmount` extracted + wired into `ContractService`, tested.
- [x] §5.2 `MinorUnitConverter` canonical; MCP formatters currency-aware (JPY/BHD fixed); stripe
      `AmountConverter` delegates; duplication removed.
- [x] §5.3 single `Money::HALF_CENT_EPSILON` + helpers; 3 epsilon copies removed; 3 sites migrated.
- [x] §5.4 `CapturableAmount` extracted + wired into `CaptureService`, tested.
- [x] All Unit + Integration suites green on both modules; PHPCS/PHPStan(max)/PHPMD clean, no new
      suppressions.
- [x] Report §7 action table + `status.md` updated.
- [ ] Commit across both repos (held — awaiting ticket number / explicit go).

---

## 7. Artifacts

- Review report: `reports/02-floating-point-math-code-review.md` (§1–§6 = pre-change inventory; §7 = done)
- New source: `payment-base/src/Math/Money/{LineItemAmount,MinorUnitConverter,Money}.php`,
  `stripe/src/Stripe/Service/CapturableAmount.php`
- Changed source: `payment-base/src/Service/ContractService.php`,
  `payment-base/src/Mcp/{Acp/AcpResponseFormatter,Ucp/UcpResponseFormatter}.php`,
  `payment-base/src/Contract/CaptureRefundTracker.php`,
  `payment-base/src/EventSystem/Handler/RefundIntentHandler.php`,
  `stripe/src/Stripe/Core/AmountConverter.php`, `stripe/src/Stripe/Service/CaptureService.php`
