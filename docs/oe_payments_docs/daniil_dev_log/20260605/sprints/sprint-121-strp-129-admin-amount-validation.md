# Sprint 121 — STRP-129 (follow-up II) Admin Amount Validation + Remaining Free-Text Fields

**Date planned:** 2026-06-05
**Ticket:** STRP-129 (second admin-side follow-up; seeded by Sprint 120 §10)
**Branch:** feature branch off the Sprint 120 result (suggested: `b-7.4.x-STRP-129-admin-amounts`)
**Repos touched:** `extensions/stripe` ONLY — zero payment-base changes
**Hard dependency:** Sprint 120 MUST be merged first — this sprint reuses
`AdminValidationFeedbackInterface`, the panel alert block, and the admin-lang validation keys.
**Predecessors:**
- Sprint 120 plan: [`./sprint-120-strp-129-capture-reason-validation.md`](./sprint-120-strp-129-capture-reason-validation.md)
- Sprint 119 plan: [`../../20260529/sprints/sprint-119-strp-129-user-address-validation.md`](../../20260529/sprints/sprint-119-strp-129-user-address-validation.md)

---

## 1. Why

### 1.1 The silent full-capture / full-refund footgun (the driver)

`StripePaymentPanelProvider::parseAmount()` (`src/Stripe/Admin/StripePaymentPanelProvider.php:128-137`):

```php
private function parseAmount(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;                       // absent → null
    }
    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (float) $value : null;   // MALFORMED → ALSO null
}
```

`null` means **full capture / full refund** by contract (`OrderActionDispatcher`: "Null amount =
full capture"). Consequences today:

| Admin types | What happens |
|---|---|
| `12,30 EUR` | `"12.30 EUR"` → not numeric → `null` → **FULL capture**, silently |
| `1.234,50` (German thousands) | `"1.234.50"` → not numeric → `null` → **FULL capture** |
| `-5` | `is_numeric` passes → `-5.0` sent to Stripe (rejected only at the API) |
| `0.001` on EUR | passes → Stripe rejects or rounds at the API |
| `999999` (> capturable) | passes server-side; only the HTML `max` attribute "guards" |

The HTML constraints (`type=number step=0.01 min=0.01 max=…`, template `:344-345`, `:413-414`)
are client-side only. Same code path serves the refund form → same footgun for refunds.

### 1.2 The remaining POST-reachable free-text field

`PaymentAdminController::collectActionRequest()` (payment-base `:235-242`) forwards **`$_POST`
wholesale**. `handleRefund()` reads `$request['refund_description']`
(`StripePaymentPanelProvider.php:99`) → `StripeRefundRequestHandler` puts it into
`$metadata['description']` (`:160-162`) → Stripe. The panel form ships no such input, but the
path is POST-reachable — unvalidated free text into Stripe metadata. One rules entry closes it
with the Sprint 120 machinery as-is.

### 1.3 What needs NO work (verified — record, don't build)

| Field | Why safe already |
|---|---|
| `refund_reason` (select) | `RefundService::validateReason()` whitelists against `VALID_REASONS = ['duplicate','fraudulent','requested_by_customer']`; invalid → `null` (`RefundService.php:30-31,146-152`) |
| `cancel_reason` (select) | `PaymentIntentHelper::cancelPaymentIntent()` `match` whitelists `requested_by_customer/fraudulent/duplicate/abandoned`, default `requested_by_customer` (`PaymentIntentHelper.php:193-196`) |

Phase B pins both with regression tests so the whitelist can't silently disappear.

## 2. Goals

1. A malformed amount is a **rejected request**, never a full capture/refund.
2. Server-side enforcement: amount parseable, `> 0`, currency-correct precision, `≤` the
   PI/charge-derived bound (capturable for capture, remaining-refundable for refund).
3. On failure: event not dispatched, admin sees a translated, code-specific message in the
   Sprint 120 alert block (e.g. *"The amount exceeds the capturable amount."*).
4. `refund_description` is character-level validated like `captureReason`.
5. Absent amount (empty input / not posted) keeps meaning **full capture / full refund** —
   that contract is correct and stays.

## 3. Out of scope (explicit — no overengineering)

- Touching `ValidationBase` / payment-base — char-level lib stays char-level; the amount
  validator is semantic and Stripe-local (memory: provider-local beats widening shared code).
- Stripe per-currency **minimum** amounts (~€0.50 floor) — Stripe rejects with a clear API error
  today; a pre-flight table is a separate concern (driver report G5).
- PayPal adoption; OPC/storefront amount fields (none exist there).
- Client-side JS; re-echoing rejected values into inputs.
- Multi-currency formatting niceties in messages beyond `%s` interpolation of the bound.

## 4. Architecture

### 4.1 Component map (new pieces marked ●)

```
handleCapture()/handleRefund()  (StripePaymentPanelProvider)
        ├─ ● AdminAmountValidator::validate(rawValue, bound, currency)   → AmountValidationResult
        │        ├─ absent      → ok(null)        (full capture/refund — legitimate)
        │        ├─ malformed   → failure(code: amountMalformed)         ← kills the footgun
        │        ├─ ≤ 0         → failure(code: amountNotPositive)
        │        ├─ precision   → failure(code: amountPrecision)         (AmountConverter::decimalsFor)
        │        └─ > bound     → failure(code: amountExceedsBound)
        ├─ ● bound source: AdminActionBoundsInterface
        │        ├─ captureBound(Order)  → OrderRefundViewDataProvider::getCaptureableRaw()      (PI-derived)
        │        └─ refundBound(Order)   → OrderRefundViewDataProvider::getRemainingRefundableRaw() (charge-derived)
        ├─ refund only: UserDataValidatorInterface::validateFieldMap(['refundDescription' => …])  ← Sprint 119/120 chain
        ├─ failures → AdminValidationFeedbackInterface::store() → return   ← Sprint 120, reused as-is
        └─ ok → OrderActionDispatcher::capture()/refund() with the PARSED float (or null)
        ▼ (tab re-renders)
StripePanelViewDataBuilder → ● amount messages via per-code admin-lang keys
                           → existing alert block (Sprint 120 §4.7) — template UNCHANGED
```

### 4.2 `AdminAmountValidator` — semantic, stateless, final

```php
final class AdminAmountValidator
{
    public function validate(mixed $raw, float $bound, string $currency): AmountValidationResult;
}
```

- **Pure function over its inputs** (no deps — `AmountConverter` is the existing static utility;
  memory: static for pure utilities, no speculative interface). `final`, no interface: it has no
  swappable implementation and consumers can use the real thing in tests — it's deterministic.
- `AmountValidationResult` VO: `ok(?float $amount)` | `failure(string $code)`; consumers branch
  on `isOk()`. The parsed float travels forward — **parse once, use the parsed value** (today the
  raw value is parsed and could diverge from what was validated).
- Parsing rules (strict, locale-tolerant): trim; accept `12.50` and `12,50`; reject anything
  with more than one separator (`1.234,50`, `12.3.4`), signs (`-`, `+`), exponent notation
  (`1e3`), whitespace inside, non-digit/non-separator chars. Regex-first, then cast.
- Precision: count decimals against `AmountConverter::decimalsFor($currency)` — JPY (0 decimals)
  rejects `100.5`; EUR rejects `10.123`.
- Bound: strict `>` comparison with an epsilon-free guard — compare in **minor units**
  (`AmountConverter::toMinorUnits()`) to avoid IEEE-754 edge cases at the boundary
  (`100.10 > 100.10` float drift).

### 4.3 `AdminActionBounds` — narrow seam over the existing view-data provider

```php
interface AdminActionBoundsInterface
{
    public function captureBound(Order $order): float;
    public function refundBound(Order $order): float;
}
```

Impl `StripeAdminActionBounds` delegates to the existing
`OrderRefundViewDataProvider::getCaptureableRaw()` / `getRemainingRefundableRaw()` — the **same
sources the form's `max` attribute and displayed amounts use**, both PI/charge-derived (memory:
never gate decisions on webhook-populated `OXCAPTUREDAMOUNT`). Interface justified: ISP — the
panel provider must not depend on the wide view-data class; and both unit-test consumers mock it
(memory: mock interfaces, not concretes). ~25 lines total.

### 4.4 Amount failure codes & messages

Amount failures reuse `FieldValidationFailure` (field `captureAmount` / `refundAmount`,
`addressKind='admin'`, `offendingChar=null`) so `AdminValidationFeedback` carries them unchanged.
BUT the Sprint 119 message template ("Allowed symbols are: …") is wrong for semantic failures —
messages are **per-code**, resolved in `StripePanelViewDataBuilder`'s formatting step:

```php
'STRIPE_VALIDATION_AMOUNT_MALFORMED'      => 'The amount is not a valid number. Use a format like 12.50.',
'STRIPE_VALIDATION_AMOUNT_NOT_POSITIVE'   => 'The amount must be greater than zero.',
'STRIPE_VALIDATION_AMOUNT_PRECISION'      => 'The amount has too many decimal places for %s.',
'STRIPE_VALIDATION_AMOUNT_EXCEEDS_BOUND'  => 'The amount exceeds the maximum available (%s %s).',
```

(de mirrors; keys go to `views/admin_twig/{en,de}/stripe_lang.php` — admin context resolves
there, Sprint 120 §4.6.) Routing rule in the builder: code starts with `amount` → per-code key;
otherwise → existing `UserDataValidationMessageFormatter` (char-level fields). One small private
method, no new formatter class (no overengineering — promote to a class only when a third
message family appears).

### 4.5 `refund_description` — pure Sprint 120 replay

- Rules entry in `src/Resources/validation-rules.php` (same allow/block set as `captureReason`;
  field `refundDescription`).
- `handleRefund()` gate: `validateFieldMap(['refundDescription' => $description], 'admin')`.
- Admin-lang label key `STRIPE_VALIDATION_LABEL_REFUNDDESCRIPTION` (+de). Everything else —
  feedback, alert, formatter — already in place.

### 4.6 Defense in depth (single guards at the convergence points)

`CaptureService::processCapture()` and `RefundService` are the convergence points for ALL
capture/refund paths (admin panel, opalreturns, future callers). Add one early-return guard each:
non-null `amount <= 0` → failure response, never an API call. Two lines + two tests. Bound checks
do NOT move there (they need admin-context bound resolution; Stripe enforces the hard ceiling).

### 4.7 Wiring (`services.yaml`)

```yaml
OxidEsales\Payments\Stripe\Admin\AdminAmountValidator:
  public: false

OxidEsales\Payments\Stripe\Admin\AdminActionBoundsInterface:
  class: OxidEsales\Payments\Stripe\Admin\StripeAdminActionBounds
  arguments:
    $viewDataProvider: '@OxidEsales\Payments\Stripe\Controller\Admin\OrderRefundViewDataProvider'
  public: false

OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider:
  arguments:
    # …Sprint 120 args unchanged…
    $amountValidator: '@OxidEsales\Payments\Stripe\Admin\AdminAmountValidator'
    $actionBounds: '@OxidEsales\Payments\Stripe\Admin\AdminActionBoundsInterface'
```

Appended args only; autowire-sweep exclude check as in Sprint 120 §4.5.

---

## 5. TDD plan (per-phase RED → GREEN → REFACTOR)

Gate after EVERY phase: `./bin/pre-commit-check.sh` green, PHPMD baseline unchanged (3 entries),
zero new suppressions. One commit per phase.

### Phase A — `AdminAmountValidator` + `AmountValidationResult` (pure unit, no mocks)

RED (`tests/Unit/Stripe/Admin/AdminAmountValidatorTest.php`, data-provider driven):

| # | Input (`raw`, bound=100.00, EUR unless noted) | Expect |
|---|---|---|
| A1 | `null`, `''` | `ok(null)` — full capture/refund preserved |
| A2 | `'50'`, `'50.00'`, `'50,00'` | `ok(50.0)` |
| A3 | `'100.00'` (== bound) | `ok(100.0)` — boundary inclusive |
| A4 | `'abc'`, `'12,30 EUR'`, `'1.234,50'`, `'12.3.4'`, `'1e3'`, `' 50'` (inner space variants) | `failure(amountMalformed)` ← the footgun killers |
| A5 | `'-5'`, `'0'`, `'+5'` | `failure(amountNotPositive)` / malformed for `+5` |
| A6 | `'10.123'` EUR; `'100.5'` JPY (0-decimals) | `failure(amountPrecision)` |
| A7 | `'100.01'` (bound 100.00) | `failure(amountExceedsBound)` |
| A8 | float-drift boundary: bound `100.10`, input `'100.10'` | `ok` (minor-units comparison pinned) |
| A9 | array/object raw input | `failure(amountMalformed)`, no TypeError |

GREEN: implement validator + VO. REFACTOR: table-driven private steps (parse → positive →
precision → bound), each ≤10 lines.

### Phase B — bounds seam + already-safe selects pinned

RED:
- `StripeAdminActionBoundsTest`: delegates to the right provider method per action (mocked
  `OrderRefundViewDataProvider` — not final; if mocking the concrete proves brittle, add a
  protected-seam testable subclass per memory `feedback_oxid_dao_mocking`).
- Regression pins (extend existing suites): `RefundServiceTest` — invalid `refund_reason` string
  → `null` reaches Stripe params (whitelist pinned); `PaymentIntentHelperTest` — unknown
  `cancel_reason` → `requested_by_customer` (match-default pinned).

GREEN: implement `StripeAdminActionBounds` (~25 lines).

### Phase C — wire the gates into `handleCapture()` / `handleRefund()`

RED (`StripePaymentPanelProviderTest`, mocked dispatcher + validator deps + bounds + feedback):

| # | Behaviour |
|---|---|
| C1 | malformed `capture_amount` → **`capture()` NEVER called**, feedback stored with `amountMalformed` (the sprint's raison d'être) |
| C2 | `capture_amount` > capturable bound → not dispatched, `amountExceedsBound` |
| C3 | valid `capture_amount` `'50,00'` → `capture()` called with **parsed `50.0`** (not re-parsed raw) |
| C4 | empty `capture_amount` → `capture()` with `null` (full capture unchanged) |
| C5 | mirror C1–C4 for `refund_amount` → `refund()` with refund bound |
| C6 | amount failure AND reason failure in one POST → both failures stored, single feedback entry set, nothing dispatched |
| C7 | POSTed `refund_description` with `<` → `refund()` not dispatched, char-level failure stored |
| C8 | bound resolution throws (PI fetch fails) → action rejected with `amountExceedsBound`-distinct code `amountBoundUnavailable`, nothing dispatched (fail-closed, not fail-open) |

GREEN: gates per §4.1; `parseAmount()` is **deleted** from the provider (replaced by the
validator — DRY, single parser). REFACTOR: keep `handleCapture`/`handleRefund` ≤25 lines via
small private helpers.

### Phase D — messages + rules entry + translations

RED:
- `StripePanelViewDataBuilderTest`: per-code routing (amount codes → `STRIPE_VALIDATION_AMOUNT_*`
  with bound/currency interpolation; char codes → existing formatter); mixed-failure ordering
  stable.
- `UserDataValidatorTest` (+`ValidationRulesProviderTest` count 14→15): `refundDescription`
  entry end-to-end over the real rules file (umlauts pass, `<` blocked, control char rejected).

GREEN: builder routing method, rules entry, 5+1 admin-lang keys ×2 locales.

Manual verify (both locales): cache clear + `docker compose restart php` + `rm -rf source/tmp/*`;
on the tab — `12,30 EUR` in capture amount → red alert, full amount NOT captured, Stripe
Dashboard untouched; `50,00` → captures exactly 50.00.

### Phase E — defense-in-depth guards (time-boxed)

RED: `CaptureServiceTest` / `RefundServiceTest` — explicit `amount = -5.0` / `0.0` → failure
response, adapter factory NEVER invoked.
GREEN: one early-return guard per service. If either service's test setup makes this >1h, defer
with a note in the completion report — the panel gate already protects the admin path.

---

## 6. Files touched (complete list)

**New:**
- `src/Stripe/Admin/AdminAmountValidator.php`
- `src/Stripe/Admin/AmountValidationResult.php`
- `src/Stripe/Admin/AdminActionBoundsInterface.php`
- `src/Stripe/Admin/StripeAdminActionBounds.php`
- `tests/Unit/Stripe/Admin/AdminAmountValidatorTest.php`
- `tests/Unit/Stripe/Admin/StripeAdminActionBoundsTest.php`

**Modified:**
- `src/Stripe/Admin/StripePaymentPanelProvider.php` (gates; `parseAmount()` removed; +2 ctor args)
- `src/Stripe/Admin/StripePanelViewDataBuilder.php` (per-code message routing)
- `src/Resources/validation-rules.php` (+1 entry: `refundDescription`)
- `src/Stripe/Service/CaptureService.php`, `src/Stripe/Service/RefundService.php` (Phase E guards)
- `views/admin_twig/en/stripe_lang.php`, `views/admin_twig/de/stripe_lang.php` (+6 keys each)
- `services.yaml` (2 new services, 2 arg additions)
- `tests/Unit/Stripe/Admin/StripePaymentPanelProviderTest.php` (+8 cases)
- `tests/Unit/Stripe/Admin/StripePanelViewDataBuilderTest.php` (+3 cases)
- `tests/Unit/Stripe/Service/UserDataValidatorTest.php`, `ValidationRulesProviderTest.php` (count → 15)
- `tests/Unit/Stripe/Service/RefundServiceTest.php`, `tests/Unit/.../PaymentIntentHelperTest.php` (pins)

## 7. Known ripple effects

1. **`parseAmount()` deletion** — grep for other callers first (`grep -rn 'parseAmount' src/`);
   as of planning it is private to the provider.
2. **Rules-file count assertions** move 14 → 15 (`ValidationRulesProviderTest`); OPC footer
   `fieldAllowed` map grows by `refundDescription` (harmless, Sprint 120 §7.2 reasoning).
3. **C8 fail-closed decision**: if the PI fetch fails, today's behaviour would have allowed the
   action and let Stripe decide. Fail-closed is a deliberate behaviour change — call it out in
   the completion report.
4. **opalreturns path**: refund convergence goes through the same broker events; Phase E guard in
   `RefundService` is the shared protection. Run the opalreturns-relevant integration tests if
   the suite tags them.

## 8. Risks & rollback

| Risk | Mitigation |
|---|---|
| Legit admin input rejected (locale formats) | A2 accepts both `.` and `,` decimals; only ambiguous multi-separator input rejects — and the message says what to type |
| Bound source flakes (Stripe API hiccup) | C8 fail-closed + distinct message; admin retries — safer than fail-open full capture |
| Behaviour change: malformed input used to "work" (as full capture) | That IS the fix; completion report documents it; A4/C1 pin it forever |
| Float boundary false rejections | A8 pins minor-units comparison |
| Phase E destabilizes converged refund paths (opalreturns) | guard is `amount <= 0` only — null (full) and positive amounts unaffected; paypal/OPC suites re-run per memory `feedback_payment_base_additive_only` discipline |

Rollback: revert the provider wiring commit — `parseAmount()` restoration is contained in one
commit's diff; rules entry and Phase E guards are independently revertable.

## 9. Definition of Done

- [ ] All phases RED→GREEN; one commit per phase, `STRP-129` prefix
- [ ] `'12,30 EUR'` capture input → rejected with message, full amount NOT captured (manually
      verified against Stripe test mode)
- [ ] Negative / zero / over-bound / wrong-precision amounts → rejected, event not dispatched
- [ ] Empty amount → full capture/refund exactly as before
- [ ] `refund_description` POST injection → rejected, char-level message
- [ ] `refund_reason` / `cancel_reason` whitelists pinned by regression tests
- [ ] `./bin/pre-commit-check.sh --full` green; PHPCS 0, PHPStan level max 0, PHPMD baseline
      unchanged (3 entries), zero new suppressions
- [ ] No payment-base / paypal / one-page-checkout file modified
- [ ] Completion report in `done/` with per-phase deltas; §7 ripple items + C8 fail-closed
      decision explicitly documented

## 10. Follow-ups seeded (NOT in scope)

1. Stripe per-currency **minimum** amount pre-flight (driver report G5) — needs a static
   minimums table or account-limits cache; separate decision.
2. Country/currency backend re-validation at checkout preconditions (driver report G4) —
   storefront concern, unrelated to the admin tab.
3. Memory note candidate after completion: "admin amount inputs — absent means full-action by
   contract; malformed must never degrade to absent" as a feedback memory if the pattern
   recurs in PayPal's panel.
