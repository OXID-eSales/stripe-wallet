# Sprint 103 — Fix partial-capture negative "Available for refund" amount

**Module:** `extensions/stripe`
**Mode:** single atomic commit. One resolver introduced, two consumers
re-pointed at it, two new test files added. No sub-sprint split — the
fix is one cohesive seam change and splitting it would leave the
codebase in a transiently red state for no measurable gain. The grep
gate from §5.3 only goes green when all three pieces land together.
**Trigger:** [report `01-partial-capture-negative-refund-amount.md`](../reports/01-partial-capture-negative-refund-amount.md)

## 1. Why

On a manual-capture Stripe order where the admin captures less than the
authorised amount, Stripe records the auto-released remainder as a
Refund on the charge and increments `charge.amount_refunded` by that
released amount. Our refund-form helpers treat
`charge.amount_refunded` as the customer-refunded total, which on a
397 → 100 partial capture produces:

- Refund-section display: `Refunded Amount = 297,00 EUR` (false —
  the customer was refunded zero).
- `<input ... max="{{ remainingRefundableRaw }}">` rendered as
  `max="-197"`, which the browser rejects on every keystroke with
  *"Minimum value (0.01) must be less than the maximum value (-197)"*.

The operator cannot submit a partial-capture refund through the admin
UI at all. They have to refund from the Stripe Dashboard and reconcile
by hand.

The report names two consumers and the math. This sprint pins both
behaviour changes against tests first, then routes both consumers
through a single resolver that owns the formula.

## 2. Goals

- **G1.** For every partial-capture order with `amount_captured < amount`
  and `amount_refunded == amount − amount_captured` (the just-partial-
  captured shape Stripe returns), `getRemainingRefundableRaw()` returns
  `amount_captured / 100` and never a negative number.
- **G2.** For the same shape, `Order::getStripeRefundedAmount()`
  returns the empty string (the template renders nothing rather than a
  misleading red "297,00 EUR").
- **G3.** For a partial-capture order with a *later* real customer
  refund of R cents, both helpers report
  `customerRefunded = R / 100` and `available = (C − R) / 100`.
- **G4.** Full-capture path is unchanged (regression-checked): when
  `amount_captured == amount`, the new resolver collapses to the old
  formula `(C − R_stripe) / 100`.
- **G5.** The literal string `amount_refunded` appears in
  `src/Stripe/Controller/` and `src/Stripe/Model/` exactly inside the
  new resolver — nowhere else. (Webhook-side reads in
  `WebhookHandler/ChargeRefundedHandler.php`, `Adapter/Helper/
  PaymentIntentHelper.php`, and `Webhook/StripeWebhookProcessor.php`
  are out of scope; the refund-form math is the only consumer that
  conflates auth-release with customer-refund.)
- **G6.** `./bin/pre-commit-check.sh --full` green; test totals ≥
  pre-sprint baseline + the new cases listed in §6.

## 3. Scope inventory

| File | Concern | Line range (verified against source today) |
|---|---|---|
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | `getRemainingRefundableRaw()` returns `(C − R_stripe) / 100` | 153–160 |
| `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` | `isOrderRefundable()` compares `amount_refunded` to `amount` directly | 129–140 |
| `src/Stripe/Model/Order.php` | `getStripeRefundedAmount()` formats `R_stripe / 100` | 162–175 |
| `src/Stripe/Model/Order.php` | `hasStripeRefunds()` returns `R_stripe > 0` | 180–188 |
| `services.yaml` | One new service registration for the resolver | append near line 958 (the existing `OrderRefundViewDataProvider` block) |

### Net-new files

| File | Purpose |
|---|---|
| `src/Stripe/Service/ChargeAmountResolverInterface.php` | Narrow ISP-compliant interface — only the accessors the two consumers actually need today. |
| `src/Stripe/Service/StripeChargeAmountResolver.php` | `final` implementation. Owns the `R_release = max(0, A − C)` and `R_customer = max(0, R_stripe − R_release)` formulas. |
| `tests/Unit/Stripe/Service/StripeChargeAmountResolverTest.php` | Pins the formula in isolation against a `\Stripe\Charge` value-object built with `Charge::constructFrom([...])`. |
| `tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php` | Pins `getRemainingRefundableRaw()` and `isOrderRefundable()` against the partial-capture shape. |
| `tests/Unit/Stripe/Model/OrderStripeAmountsTest.php` | Pins `getStripeRefundedAmount()` / `hasStripeRefunds()` against the partial-capture shape. |

The two consumer test files are net-new — `tests/Unit/Stripe/Model/`
contains only `OrderAddressValidationTest.php` and `PaymentTest.php`
today, and `tests/Unit/Stripe/Controller/Admin/` does not exist yet.

### Explicitly *not* touched

- `services.yaml` autowiring block at the top — no new `_defaults`.
- `metadata.php`, `module.xml`, OXID smart templates / twig templates,
  the contract state machine, the webhook handlers, OXPAID
  reconciliation, the `StripeAdapter`.
- The transaction-history table at `views/twig/admin/panel/stripe_panel.html.twig`
  (the "Refund 297,00 EUR succeeded" row mentioned in the report §5).
  Tagging that row as "Authorization release" is a UI-clarity fix, not
  a math fix, and it is **deferred to Sprint 103.x** (see §8).
- DB migrations. None. The fix is read-only display math.

## 4. The new resolver — design defended

Name: `StripeChargeAmountResolver` (concrete) /
`ChargeAmountResolverInterface` (contract). Namespace
`OxidEsales\Payments\Stripe\Service`.

### 4.1 Why "ChargeAmountResolver" is genuinely one responsibility

The class converts a single input — a Stripe `Charge` value-object —
into a small set of *named* derived monetary quantities expressed in
shop-display units (floats, currency-agnostic, never negative). It
does not know about orders, formatting, currency, controllers, or the
admin UI. Two consumers exist today; both currently re-implement the
same arithmetic over the same input. After this sprint, both consumers
ask the resolver named questions ("how much has the customer actually
been refunded?", "how much of the capture is still refundable?") and
the formula `R_customer = max(0, R_stripe − max(0, A − C))` exists in
exactly one method.

A critical reader's likely objection — *"why not a static method on a
DTO?"* — is answered by the fact that the resolver is the seam that
makes the consumers unit-testable without a `\Stripe\Charge` fixture
in each consumer test. A static helper would force every consumer
test to construct the SDK object directly; an injected resolver lets
each consumer test stub the resolver with the three return values it
needs.

### 4.2 Interface (ISP-narrow, exactly what is needed today)

```php
interface ChargeAmountResolverInterface
{
    /**
     * Amount actually refunded to the customer, in shop currency units (not cents).
     * Computed as max(0, amount_refunded − max(0, amount − amount_captured)).
     * Never negative. Never exceeds amount_captured.
     */
    public function customerRefundedAmount(\Stripe\Charge $charge): float;

    /**
     * Amount still refundable through the admin form, in shop currency units.
     * Computed as max(0, amount_captured − customerRefundedAmount(charge) * 100) / 100.
     * Never negative.
     */
    public function availableForRefund(\Stripe\Charge $charge): float;

    /**
     * True iff the customer has been refunded any non-zero amount.
     * Equivalent to customerRefundedAmount(charge) > 0.
     */
    public function hasCustomerRefund(\Stripe\Charge $charge): bool;
}
```

No `authReleaseAmount()` accessor yet — the transaction-history badge
fix is deferred (§8), so YAGNI / ISP says the interface stays at three
methods. OCP is preserved: when 103.x ships the badge, a fourth
accessor (`authReleasedAmount`) is *added* to the interface without
modifying the existing methods or their consumers.

### 4.3 Services.yaml diff

Appended right after the existing `OrderRefundViewDataProvider` block
(currently lines 958–959). Two-line addition for the alias keeps the
shape symmetric with the other interface aliases in this file (see
e.g. `ModuleConfigurationServiceInterface` at line 18):

```yaml
  # Sprint 103: Resolver for derived charge amounts (customer-refunded,
  # available-for-refund). Owns the partial-capture math — the formula
  # `R_customer = max(0, amount_refunded − max(0, amount − amount_captured))`
  # exists nowhere else in src/Stripe/{Controller,Model}/.
  OxidEsales\Payments\Stripe\Service\ChargeAmountResolverInterface:
    alias: OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver
    public: true

  OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver:
    public: true
```

Service is `public: true` because `Order` (the OXID class-extension)
must fetch it via the OXID `ContainerFactory` — class extensions
cannot use constructor DI (the parent `Order_parent` is dynamically
generated). The `OrderRefundViewDataProvider` does use constructor DI
and receives the interface, not the concrete class — see §5.5.

## 5. The five pillars — explicit application

### 5.1 SOLID

- **SRP.** `StripeChargeAmountResolver` owns the auth-release-vs-
  customer-refund math and nothing else. Neither
  `OrderRefundViewDataProvider` nor `Order` performs subtraction on
  `amount_refunded` after the sprint; they ask the resolver named
  questions. The pre-sprint situation has the same arithmetic in two
  files plus a third subtly-wrong variant in
  `isOrderRefundable()` — three call-sites, three opportunities for
  the bug to recur.
- **OCP.** New derived quantities (e.g. `authReleasedAmount` for the
  103.x badge) extend the interface without modifying the existing
  three methods. Existing consumers stay untouched when 103.x ships.
- **LSP.** The interface contract is stated as a precondition on the
  charge input (must expose `amount`, `amount_captured`,
  `amount_refunded` as non-negative integers in cents) and a
  postcondition on every accessor (return value is a non-negative
  float in shop currency units, never exceeds `amount_captured`).
  Any future PSP-specific subtype that respects those bounds
  substitutes cleanly. See §5.4 for the explicit substitutability
  contract.
- **ISP.** Three methods. No `authReleasedAmount`, no
  `formattedCustomerRefund`, no charge-introspection accessors —
  none of those are needed by the two consumers today.
- **DIP.** `OrderRefundViewDataProvider` receives the resolver via
  constructor injection, type-hinted on the interface. The two
  consumers depend on `ChargeAmountResolverInterface`, never on
  `StripeChargeAmountResolver`. `Order` (class extension, cannot
  constructor-inject) fetches the resolver through
  `ContainerFactory::getInstance()->getContainer()->get(
  ChargeAmountResolverInterface::class)` — same pattern it already
  uses for `StripeOrderApiService` at line 200. This is the OXID
  class-extension wart, not a DIP violation: the resolver type is
  still the interface, and the test suite swaps the container's
  binding to stub the resolver.

### 5.2 TDD — red tests first, named explicitly

Walking order is **resolver tests → resolver implementation →
consumer tests → consumer rewires**. The consumer tests are red
against the *pre-fix* production code (they hit `-197.0` / `297,00`).
The resolver tests are red against an empty implementation (the
class file does not yet exist).

**`StripeChargeAmountResolverTest.php` — pin the formula in isolation.**

| Test method | Charge fixture (cents) | Expected `customerRefundedAmount` | Expected `availableForRefund` | Expected `hasCustomerRefund` |
|---|---|---|---|---|
| `testFullCaptureNoRefund` | A=10000, C=10000, R=0 | 0.0 | 100.0 | false |
| `testFullCaptureWithCustomerRefund` | A=10000, C=10000, R=3000 | 30.0 | 70.0 | true |
| `testPartialCaptureNoCustomerRefund` | A=39700, C=10000, R=29700 | **0.0** | **100.0** | **false** |
| `testPartialCaptureWithLaterCustomerRefund` | A=39700, C=10000, R=34700 | **50.0** | **50.0** | **true** |
| `testZeroCaptureZeroRefund` | A=10000, C=0, R=0 | 0.0 | 0.0 | false |
| `testRoundingDownGuard` | A=10000, C=9999, R=10000 | 0.0 (clamped, not −0.01) | 0.0 | false |

Expected pre-fix red message for `testPartialCaptureNoCustomerRefund`:
`Error: Class OxidEsales\Payments\Stripe\Service\StripeChargeAmountResolver not found.`
(Resolver does not exist yet.)

**`OrderRefundViewDataProviderTest.php` — pin the consumer.**

| Test method | Charge fixture (cents) | Asserts |
|---|---|---|
| `testRemainingRefundableRawForFullCaptureNoRefundReturnsCapturedAmount` | A=10000, C=10000, R=0 | `getRemainingRefundableRaw() === 100.0` |
| `testRemainingRefundableRawForFullCaptureWithCustomerRefundReturnsResidual` | A=10000, C=10000, R=3000 | `getRemainingRefundableRaw() === 70.0` |
| `testRemainingRefundableRawForPartialCaptureNoCustomerRefundReturnsCapturedAmount` | A=39700, C=10000, R=29700 | `getRemainingRefundableRaw() === 100.0` (regression case — the screenshot) |
| `testRemainingRefundableRawForPartialCaptureWithCustomerRefundReturnsResidual` | A=39700, C=10000, R=34700 | `getRemainingRefundableRaw() === 50.0` |
| `testIsOrderRefundableTrueForPartialCaptureNoCustomerRefund` | A=39700, C=10000, R=29700 | `isOrderRefundable() === true` |
| `testIsOrderRefundableFalseWhenCaptureFullyRefundedToCustomer` | A=10000, C=10000, R=10000 | `isOrderRefundable() === false` |
| `testIsOrderRefundableFalseWhenChargeIsNull` | charge = null | `isOrderRefundable() === false` |

Expected pre-fix red message for
`testRemainingRefundableRawForPartialCaptureNoCustomerRefundReturnsCapturedAmount`:
`Failed asserting that -197.0 matches expected 100.0.`

**`OrderStripeAmountsTest.php` — pin the model extension.**

| Test method | Charge fixture (cents) | Asserts |
|---|---|---|
| `testGetStripeRefundedAmountEmptyForFullCaptureNoRefund` | A=10000, C=10000, R=0 | `getStripeRefundedAmount() === ''` |
| `testGetStripeRefundedAmountFormattedForFullCaptureWithCustomerRefund` | A=10000, C=10000, R=3000 | `getStripeRefundedAmount()` includes "30," |
| `testGetStripeRefundedAmountEmptyForPartialCaptureNoCustomerRefund` | A=39700, C=10000, R=29700 | `getStripeRefundedAmount() === ''` (regression — red shows "297,00 EUR" today) |
| `testGetStripeRefundedAmountFormattedForPartialCaptureWithCustomerRefund` | A=39700, C=10000, R=34700 | `getStripeRefundedAmount()` includes "50," |
| `testHasStripeRefundsFalseForPartialCaptureNoCustomerRefund` | A=39700, C=10000, R=29700 | `hasStripeRefunds() === false` |
| `testHasStripeRefundsTrueAfterCustomerRefund` | A=39700, C=10000, R=34700 | `hasStripeRefunds() === true` |

Expected pre-fix red for
`testGetStripeRefundedAmountEmptyForPartialCaptureNoCustomerRefund`:
`Failed asserting that '297,00 €' is identical to ''.` (exact
formatted string varies by locale fixture; assertion is on emptiness).

Test order: resolver tests must turn green first (resolver implemented
in isolation), then consumer tests turn green as the consumers are
rewired. There is no "refactor" step after green beyond the standard
"are the methods 15–25 lines and no `else`?" pass — see §5.5.

### 5.3 DRY — the grep gate

After the sprint:

```bash
grep -rn 'amount_refunded' source/extensions/stripe/src/Stripe/Controller/ \
                            source/extensions/stripe/src/Stripe/Model/
```

must return exactly zero matches. The only remaining hits in `src/`
are in:

- `src/Stripe/Service/StripeChargeAmountResolver.php` (the new
  resolver, the one allowed home).
- `src/Stripe/WebhookHandler/ChargeRefundedHandler.php` (webhook
  persistence; out of scope, reads the raw field for OXTRANSSTATUS
  recording).
- `src/Stripe/Adapter/Helper/PaymentIntentHelper.php` (mapping the
  full Stripe object into the contract — out of scope).
- `src/Stripe/Webhook/StripeWebhookProcessor.php` (parses the
  webhook event payload — out of scope).

Pre-sprint baseline of `amount_refunded` matches in `src/`: 7. Post-
sprint expected: 5 (the four out-of-scope ones plus exactly one new
match in the resolver).

### 5.4 Liskov — explicit substitutability contract

For any implementation `R` of `ChargeAmountResolverInterface`, and
any `\Stripe\Charge` `$c` satisfying the **precondition**:

- `$c->amount` is a non-negative integer (cents).
- `$c->amount_captured` is a non-negative integer ≤ `$c->amount`.
- `$c->amount_refunded` is a non-negative integer ≤ `$c->amount`.

the following **postconditions** must hold:

- `R::customerRefundedAmount($c)` returns a `float` in `[0.0,
  $c->amount_captured / 100]`. Never negative, never exceeds the
  captured amount.
- `R::availableForRefund($c)` returns a `float` in `[0.0,
  $c->amount_captured / 100]`. Never negative, and
  `availableForRefund($c) + customerRefundedAmount($c) ==
  $c->amount_captured / 100` (within float epsilon).
- `R::hasCustomerRefund($c) === (R::customerRefundedAmount($c) >
  0.0)`.

`StripeChargeAmountResolver` is `final` — any future PSP-specific
subtype substitutes through the interface, not by extending the class.

### 5.5 Clean Code / DI

- Methods 15–25 lines, early returns, no `else`. The resolver's
  longest method is `customerRefundedAmount(\Stripe\Charge $c): float`
  at ~8 lines; the other two delegate.
- Magic numbers behind one named constant: `CENTS_PER_UNIT = 100`
  (private const on the resolver). The division `/100` is centralised
  there. Consumers receive floats already in shop-currency units.
- No `Registry::get(...)` to fetch the resolver from
  `OrderRefundViewDataProvider`; constructor DI only.
- `Order` (class extension) fetches the resolver via
  `ContainerFactory::getInstance()->getContainer()->get(
  ChargeAmountResolverInterface::class)` — same locator pattern the
  class already uses for `StripeOrderApiService`. The locator is an
  OXID-imposed wart on class extensions; isolating it to the existing
  `getStripeCharge()` accessor pattern keeps the new code DI-clean.
- No new comments unless they explain *why* an apparently-redundant
  `max(0, …)` clamp is there: the float comparison can produce
  `−0.00…001` on edge fixtures, and the clamp prevents that leaking
  into the rendered HTML `max` attribute as `-0.00`.

## 6. Test matrix (the cases the existing suite misses today)

| # | Authorize A | Capture C | `amount_refunded` R | Customer refund | Expected `R_customer` | Expected `available` | `isOrderRefundable` | `getStripeRefundedAmount` |
|---|---:|---:|---:|---:|---:|---:|:---:|:---|
| 1 | 100 | 100 | 0 | 0 | 0,00 | 100,00 | true | "" |
| 2 | 100 | 100 | 30 | 30 | 30,00 | 70,00 | true | "30,00 EUR" |
| 3 | **397** | **100** | **297** | **0** | **0,00** | **100,00** | **true** | **""** |
| 4 | 397 | 100 | 347 | 50 | 50,00 | 50,00 | true | "50,00 EUR" |
| 5 | 100 | 100 | 100 | 100 | 100,00 | 0,00 | false | "100,00 EUR" |
| 6 | 100 | 0 | 0 | — (still requires_capture) | n/a — refund section not rendered | n/a | false | "" |

Row 3 is the exact screenshot case from the report. Row 4 is the
"refund a partial-capture order later" case. Row 6 is the zero-capture
sanity case: the refund block should not render at all, asserted via
`$this->assertFalse($provider->isOrderRefundable($order))` on a charge
with `amount_captured == 0`.

## 7. Acceptance gates

The sprint is done when **all** are simultaneously true:

- [ ] `./bin/pre-commit-check.sh --full` exits 0. Test total ≥ pre-
      sprint baseline + 19 (6 resolver + 7 provider + 6 model).
- [ ] PHPStan max level: 0 new errors.
- [ ] PHPCS: 0 errors, 0 warnings on the four touched/new files.
- [ ] PHPMD: 0 new findings (baseline unchanged).
- [ ] DRY grep gate:
      ```bash
      grep -rn 'amount_refunded' source/extensions/stripe/src/Stripe/Controller/ \
                                 source/extensions/stripe/src/Stripe/Model/
      ```
      returns zero lines.
- [ ] Manual smoke on the reporter's setup: order with manual
      capture, authorise 397, capture 100, reload the Stripe tab.
      "Available for refund" reads 100,00 EUR; the refund input
      accepts a value of 0,01–100,00 and the form can be submitted.
- [ ] No diff in `metadata.php`, `views/twig/admin/panel/stripe_panel.html.twig`,
      `services.yaml` autowiring block, or any webhook handler.

## 8. Out of scope / explicit deferrals

- **Transaction-history badge fix.** The "Refund — 297,00 EUR —
  succeeded" row in the transaction history (report §5, third item)
  is a UI-clarity issue: the Stripe API genuinely returned a refund
  object, our display is truthful, but the operator reads it as
  "customer was refunded 297". The fix is to tag the row
  *"Authorization release"* when its amount matches `A − C` and the
  capture is `partial`. **Deferred to Sprint 103.1** to keep this
  sprint a single-seam fix. Sprint 103.1 will add an
  `authReleasedAmount()` accessor to the resolver interface
  (extending the ISP-narrow contract, OCP-cleanly).
- **Underlying `StripeAdapter` / `PaymentIntentHelper`.** Both also
  touch `amount_refunded` — but for transaction-history and contract
  metadata, not for the refund-form math. No bug there.
- **OXPAID reconciliation path.** Reads `amount_captured`, not
  `amount_refunded`. Unaffected.
- **DB migration.** None. Read-only display math.
- **PHPMD / PHPStan baseline.** Not raised. The new code clears all
  gates clean.

## 9. Risk register

- **Risk:** float epsilon leaks `-0.0` into the rendered HTML5 `max`
  attribute. **Mitigation:** the resolver's
  `max(0, …)` clamp on the final return; covered by
  `testRoundingDownGuard` in §5.2.
- **Risk:** `Order`'s container locator can throw if the resolver
  service is not registered at activation time (boot-order
  regression). **Mitigation:** the locator is wrapped in the same
  `try { ... } catch (\Throwable $e) { return null; }` shape that
  `getStripeCharge()` already uses at lines 198–209. If the resolver
  is unavailable, the helper returns the empty string and the panel
  renders nothing — the same fail-soft behaviour as for a missing
  charge.
- **Risk:** existing manual fixtures in
  `StripePaymentPanelProviderTest.php` accidentally depend on the
  pre-fix `getStripeRefundedAmount()` returning the raw
  `amount_refunded`. **Mitigation:** that test uses full-capture
  fixtures (`amount_captured == amount`), which by G4 produce the
  identical output before and after the fix. Re-run the suite to
  confirm.
- **Risk:** OXID container cache stale across the sprint commit.
  **Mitigation:** `rm -rf source/tmp/*` and
  `bin/oe-console oe:cache:clear` after the merge — same procedure
  as sprint-102.5 §4.

## 10. Done definition

- [ ] §7 acceptance, every box.
- [ ] Sprint markdown moves from `sprints/` to `done/` with a
      `done/sprint-103-completion-report.md` recording:
      - the pre-fix vs post-fix `grep -c amount_refunded src/Stripe/
        Controller/ src/Stripe/Model/` count,
      - the pre-sprint vs post-sprint phpunit totals,
      - a screenshot of the refund tab on the reporter's partial-
        capture order showing "Available for refund: 100,00 EUR".
- [ ] `status.md` updated with the new test total and a row noting
      the partial-capture refund math is fixed.
- [ ] The follow-up "auth-release badge" item is filed as
      Sprint 103.1 in `reports/` so 103.x has a hook.
