# Sprint 127 — Admin amount-field prefill bugs: Refund (remaining refundable) & Capture (remaining capturable)

> Two related defects on the same Stripe admin tab (`stripe_panel.html.twig`), both about
> the **prefilled value + `max` guard** of an amount input after a prior partial action.
> **Issue 1 (§1–§8)** — Refund Amount field. **Issue 2 (§9)** — Capture Amount field.
> They share a root pattern (the input `value`/`max` must equal *remaining*, not *original*),
> but have **different** root causes — Issue 1's compute chain is already correct (defect is in
> an untested seam), Issue 2's is a concrete wrong-field bug. Do not assume one fix covers both.

**Repo:** `extensions/stripe` · **Branch:** `b-7.4.x-payment-tab-STRP-15123
**Ticket:** TBD (refund prefill bug) · **Type:** bug fix
**Mode:** TDD-first, **reproduce-before-fix**. Multi-commit (A → B → C), each RED → GREEN → REFACTOR.
**Binding every commit:** TDD · SOLID · DRY · Clean Code · No overengineering · PSR-12 · PHPStan max.

---

## 1. Bug report

**Preconditions**
- Admin is viewing an order with a **partial refund already executed**.
- Remaining refundable amount is lower than the original captured amount.

**Steps**
1. Open the payment details page (Stripe tab) for an order with a partial refund.
2. Note the already-refunded amount.
3. Go to the Refund section.
4. Observe the prefilled value in the **Amount** field.

**Actual:** Amount field is prefilled with the **original captured amount** (ignores prior refunds).
**Expected:** Amount field is prefilled with the **remaining refundable amount** only
(`captured − already-refunded`).

## 2. Where the value comes from (traced this sprint)

The chain that produces the prefilled value is, end to end:

```
stripe_panel.html.twig:425   value="{{ remainingRefundableRaw }}"   (also max="{{ remainingRefundableRaw }}")
  └─ StripePanelViewDataBuilder:92   'remainingRefundableRaw' => $provider->getRemainingRefundableRaw($order)
       └─ OrderRefundViewDataProvider:179  getRemainingRefundableRaw() → chargeAmountResolver->availableForRefund( getLastCharge($order) )
            ├─ getLastCharge() → getPaymentIntent(refresh=false) → cached StripePaymentIntentDto->charge
            │     getPaymentIntent first read per request → StripeOrderApiService::getPaymentIntentWithRefunds()
            │       → retrievePaymentIntent($transId, ['latest_charge.refunds'])
            └─ StripeChargeAmountResolver::availableForRefund():
                 release   = max(0, amount − amountCaptured)
                 customer  = max(0, amountRefunded − release)
                 available = max(0, amountCaptured − customer)        ← already subtracts prior refunds
```

**Critical observation — the obvious fix is WRONG.** The template is already bound to
`remainingRefundableRaw`, not to a captured-amount field, and the resolver already
subtracts prior refunds. `StripeChargeAmountResolverTest` proves it:
`availableForRefund` returns `70.0` for a charge captured `100` / refunded `30`
(`tests/Unit/Stripe/Service/StripeChargeAmountResolverTest.php:59`). So **at unit level
the computation is correct** — changing the template binding or the resolver would break
passing tests without fixing anything. The defect lives in a seam the unit tests don't
cover. **Do not blind-fix. Reproduce first (Phase A).**

## 3. Candidate root causes (ranked) — each with its discriminating signal

| # | Hypothesis | Why plausible | Discriminating test (Phase A) |
|---|---|---|---|
| **H1** | **Stale per-request charge cache.** `getPaymentIntent`/`getLastCharge` cache `apiOrder`/`apiCharge` and the render path reads them with `refresh=false` ("Sprint 104: render-path reads the cached charge"). If anything populates the cache with a **pre-refund** charge earlier in the same request (or the same provider instance survives a mutating action), the re-render serves `amountRefunded=0` → `available = captured`. | Exactly reproduces "prefilled with captured" symptom; only manifests in the action→re-render flow, which no unit test exercises. | Integration: in one provider instance, prime `getLastCharge()` with a pre-refund charge, then have the upstream charge gain a refund, then assert `getRemainingRefundableRaw()` reflects the refund (will FAIL if cache is stale). |
| **H2** | **API charge carries `amount_refunded=0`** despite a refund (e.g. Dashboard refund not reflected, or `getPaymentIntentWithRefunds` returns a charge whose native `amount_refunded` isn't populated by the chosen expansion). | `availableForRefund` would then compute `captured − 0 = captured`. | Mapper/adapter test: feed a Stripe charge with `amount_refunded>0` through `StripeObjectMapper` and assert the DTO's `amountRefunded` is set; contract-test `getPaymentIntentWithRefunds` returns it. |
| **H3** | **Already fixed; missing regression lock.** Wiring to `remainingRefundableRaw` dates to 2026-04-23; resolver has partial-refund tests, but the **builder→view-data→template** assembly has no test. A future regression above the resolver would ship silently. | The static chain reads correct end to end for a fresh GET. | Builder test asserting assembled `remainingRefundableRaw` for a partial-refunded order == `captured − refunded` (may go GREEN → confirms H3). |

Phase A determines which is real. Likely outcome: **H1 or H3**. The sprint fixes whichever
goes RED and, regardless, closes the coverage gap (H3 work is delivered in all cases).

## 4. Design principles applied

- **Single Source of Truth (DRY):** the refund prefill (`remainingRefundableRaw`), the form
  `max` attribute, the "remaining refundable" label, and the "already refunded" display
  (`getStripeRefundedAmount`) must all derive from **one** charge read via **one** resolver —
  never one from the API and another from a stale cache or a DB field. (See memory
  [[feedback_webhooks_inbound_only]] — don't trust webhook-populated fields; query the
  resolver.)
- **SRP / cache invalidation (SOLID):** if H1, cache lifetime is the defect. The render path
  must observe post-mutation state. Fix is a single, well-named refresh/invalidation seam —
  not scattered `refresh=true` flags. The mutating action owns invalidation; the render owns
  one consistent read.
- **No overengineering:** fix exactly the localized layer. No new abstraction unless H1
  forces a cache-invalidation collaborator; if so, keep it to one small interface.

## 5. Phases (TDD, one commit each)

### Phase A — Reproduce & localize (RED, no production change)
Write the three discriminating tests from §3 (H1 integration, H2 mapper/contract, H3 builder
assembly). Run them. **Record which go RED.** This is the regression net and the localization
gate. Commit: `test: characterize refund-amount prefill for partially-refunded orders (repro)`.

### Phase B — Fix the localized layer (GREEN)
Apply the minimal fix for the RED hypothesis:
- **If H1:** ensure the render path reads a charge consistent with post-refund state —
  invalidate the cached `apiOrder`/`apiCharge` after a mutating action (capture/refund/cancel),
  or force a single refresh on the first render read following an action. One seam, no flag soup.
- **If H2:** fix the mapping/expansion so the DTO's `amountRefunded` reflects Stripe.
- **If H3 (all green):** no production change to behavior; proceed to Phase C as the deliverable.
Re-run Phase A: previously-RED tests now GREEN; resolver tests stay GREEN. Commit:
`fix: prefill refund Amount with remaining refundable for partially-refunded orders`.

### Phase C — Lock SSOT + regression coverage (REFACTOR)
- Add the end-to-end assertion the codebase lacks: assembled view-data `remainingRefundableRaw`
  **and** `max` **and** the displayed "already refunded" all reconcile to the same charge
  (`remainingRefundableRaw == captured − refundedAmount`) for a partial-refund fixture.
- Deduplicate any second source of refunded/remaining if found (DRY), routing through the
  resolver. Commit: `test+refactor: single-source refund amounts across prefill, max, and display`.

### Phase D — Gates
PHPUnit (record before/after counts), PHPStan max (0, no new suppressions — fix code, per
[[feedback_never_suppress_static_analysis]] spirit), PHPCS PSR-12, PHPMD (no new baseline).
Optional E2E: Playwright spec opening the Stripe tab on a partial-refunded order asserting the
`#refund_amount` value equals remaining. Commit: `chore: quality gates for refund prefill fix`.

## 6. Files in scope

- `views/twig/admin/panel/stripe_panel.html.twig` (refund input — likely unchanged; verify only)
- `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` (cache/refresh — H1)
- `src/Stripe/Service/StripeChargeAmountResolver.php` (verify only — proven correct)
- `src/Stripe/Adapter/StripeObjectMapper.php` + `StripeOrderApiService.php` (expansion/mapping — H2)
- `src/Stripe/Admin/StripePanelViewDataBuilder.php` (assembly — H3 coverage)
- `src/Stripe/Model/Order.php` (`getStripeRefundedAmount` — SSOT cross-check)
- Tests under `tests/Unit/Stripe/...` (+ optional `tests/e2e/playwright`)

## 7. Definition of done
- [ ] Phase A reproduction test committed; the failing layer is identified in the commit msg
      (or documented as H3/already-correct with evidence).
- [ ] Refund Amount field + `max` prefill = `captured − already-refunded` for partial-refund orders.
- [ ] "Already refunded" display and the prefill derive from one charge read (SSOT).
- [ ] New regression test covers the builder→view-data assembly (the current gap).
- [ ] PHPUnit/PHPStan(max)/PHPCS/PHPMD clean; no new suppressions or baseline entries.
- [ ] No behavior change to full-refund / no-refund / partial-capture paths (existing resolver tests stay green).

## 8. Risks / watch-outs
- **Don't "fix" the template or the resolver** — both are correct; doing so breaks green tests
  and hides the real seam.
- **Partial-capture interaction:** the resolver's `release` term means partial-capture orders
  already exclude the auth-released remainder. Any cache/mapping fix must keep
  `StripeChargeAmountResolverTest` (incl. the partial-capture cases) green.
- **Same-request action→render** is the suspected trigger (H1); a fresh GET may already be
  correct — so test the action flow, not just an isolated GET.

---

## 9. Issue 2 — Capture "Amount" field prefilled with full authorized amount (exceeds remaining capturable)

### 9.1 Bug report

**Preconditions**
- Order with an **authorized** payment; a **partial capture** has already been executed.
- A remaining amount is still available for capture.

**Steps**
1. Open the payment details page (Stripe tab) for a partially-captured order.
2. Go to the **Capture Payment** section.
3. Review the remaining capturable amount; observe the prefilled **Amount** field.

**Actual:** Amount field is prefilled with a value that **exceeds** the remaining capturable
amount.
**Expected:** Amount field is prefilled with the **exact remaining capturable** amount.
**Reporter note:** capturing more than the remaining is also *possible*, and the post-capture
behavior "seems broken."

### 9.2 Root cause — DEFINITIVE (not a hypothesis)

Unlike Issue 1, this is a concrete wrong-source bug, located:

```
stripe_panel.html.twig:357   value="{{ capturableRaw }}"   max="{{ capturableRaw }}"
  └─ StripePanelViewDataBuilder:90   'capturableRaw' => $provider->getCaptureableRaw($order)
       └─ OrderRefundViewDataProvider:135  getCaptureableRaw():
              return AmountConverter::toMajorUnits($paymentIntent->amount, $currency);   ← FULL AUTHORIZED amount
```

`getCaptureableRaw()` returns `StripePaymentIntentDto->amount` — the **total authorized**
amount — and never subtracts what has already been captured. The correct source is Stripe's
own **`amount_capturable`** field on the PaymentIntent ("how much can still be captured").

Compounding fact: **`StripePaymentIntentDto` does not even carry `amount_capturable`** (it only
has `amount`; see `src/Stripe/Adapter/Dto/StripePaymentIntentDto.php:34-41`), and the mapper
(`StripeObjectMapper:96`) maps only `amount: $pi->amount`. So the provider has no remaining-
capturable value available today — the DTO must be widened first.

This single wrong source explains **both** reported symptoms:
- **Prefill too high:** `value="{{ capturableRaw }}"` = full authorized, not remaining.
- **Over-capture not blocked:** `max="{{ capturableRaw }}"` uses the same too-large number, so the
  browser guard is wrong; if server-side `CaptureService` also doesn't validate against
  `amount_capturable`, an over-capture reaches Stripe and corrupts state ("broken after").

### 9.3 Stripe domain nuance (must be resolved in Phase A2 — affects whether the section even shows)

Stripe PaymentIntents **do not support multiple sequential partial captures**: a partial
`capture` captures the requested amount and **auto-releases the uncaptured remainder**; the PI
moves to `succeeded` with `amount_capturable = 0`. In that model, after a partial capture the
capture section should **not** render at all (`isOrderCapturable()` checks
`status === requires_capture`, which is then false).

So Phase A2 must first establish what "partial capture with remaining available" means in this
module:
- **(a)** If the module only ever does single captures → after a partial capture `amount_capturable`
  is 0, the section hides, and the visible bug is really "**fresh** authorized order prefilled with
  full `amount`" — which is *coincidentally correct* (amount == amount_capturable) until any state
  where they diverge. The fix (use `amount_capturable`) is still correct and future-proofs it.
- **(b)** If a flow leaves the PI in `requires_capture` with `amount_capturable < amount` (e.g.
  multi-capture via separate PIs, or an incremental-authorization path), the bug is directly
  visible. Either way the fix is the same: **bind to `amount_capturable`.**

Do not "fix" by inventing `amount − amountCaptured` math on the charge — use Stripe's authoritative
`amount_capturable` on the PaymentIntent (SSOT; the charge's captured amount is a different lens).

### 9.4 Solution

1. **Widen the DTO (additive):** add `public int $amountCapturable` (and consider
   `$amountReceived`) to `StripePaymentIntentDto` with a safe default, so existing constructors
   keep working ([[feedback_payment_base_additive_only]] discipline — append-only, safe defaults).
2. **Map it:** `StripeObjectMapper` → `amountCapturable: (int) ($pi->amount_capturable ?? 0)`.
3. **Fix the source:** `getCaptureableRaw()` returns `toMajorUnits($paymentIntent->amountCapturable)`
   instead of `->amount`. `getCaptureableAmount()` follows automatically (it delegates).
4. **Server-side guard:** `CaptureService` must reject `amount > amount_capturable` (don't rely on
   the browser `max`). This closes the "over-capture possible / broken after" note. Validate against
   the PI's `amount_capturable`, return a clean admin error on violation.
5. **SSOT:** the "Capturable amount" label (`capturableAmount`), the prefill (`capturableRaw`), and
   the `max` attribute all already read `getCaptureableRaw`/`getCaptureableAmount` — keep them on the
   one corrected method; do not add a parallel computation.

### 9.5 Phases (TDD, one commit each — run after / alongside Issue 1)

- **A2 — Reproduce & decide domain (RED):** unit test `getCaptureableRaw()` returns *remaining
  capturable* (not full authorized) for a PI with `amount=100, amount_capturable=40` → RED today
  (DTO lacks the field; method returns 100). Also a `CaptureService` test: capturing `> amount_capturable`
  is rejected → RED. Document domain outcome (a)/(b) from §9.3. Commit:
  `test: characterize capture prefill + over-capture guard (repro)`.
- **B2 — Widen DTO + map (GREEN-enabling):** add `amountCapturable` to the DTO + mapper. Keep all
  existing PI-DTO constructor call sites compiling (additive, defaulted). Commit:
  `feat(dto): carry PaymentIntent amount_capturable through StripePaymentIntentDto`.
- **C2 — Fix source + guard (GREEN):** `getCaptureableRaw()` → `amountCapturable`; add the
  `CaptureService` over-capture validation. A2 tests now GREEN. Commit:
  `fix: prefill capture Amount with remaining capturable + reject over-capture`.
- **D2 — SSOT + regression:** builder-assembly test asserting `capturableRaw == amount_capturable`
  and `max` matches; assert label/prefill/max reconcile. Commit:
  `test: single-source capturable amount across label, prefill, and max`.

### 9.6 Files in scope (Issue 2)
- `src/Stripe/Adapter/Dto/StripePaymentIntentDto.php` (add `amountCapturable`)
- `src/Stripe/Adapter/StripeObjectMapper.php` (map `amount_capturable`)
- `src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` (`getCaptureableRaw`)
- `src/Stripe/Service/CaptureService.php` (server-side over-capture guard)
- `views/twig/admin/panel/stripe_panel.html.twig` (verify `value`/`max` — no change expected)
- `src/Stripe/Admin/StripePanelViewDataBuilder.php` (assembly coverage)
- Tests under `tests/Unit/Stripe/...`

### 9.7 Definition of done (Issue 2)
- [ ] Capture Amount prefill + `max` = remaining `amount_capturable` (not full authorized).
- [ ] Server-side capture rejects `amount > amount_capturable` with a clean admin error.
- [ ] `StripePaymentIntentDto` widening is additive; all existing call sites compile, all prior tests green.
- [ ] Domain outcome (a)/(b) from §9.3 recorded in the repro commit.
- [ ] Builder-assembly regression test added; label/prefill/max single-sourced.
- [ ] PHPUnit/PHPStan(max)/PHPCS/PHPMD clean; no new suppressions.

### 9.8 Risks / watch-outs (Issue 2)
- **DTO widening is additive only** — append the field with a default; never reorder constructor
  args ([[feedback_payment_base_additive_only]]).
- **Don't compute capturable from the charge** (`amount − amountCaptured`); use the PI's
  `amount_capturable` (authoritative, handles auth-release correctly).
- **Browser `max` is not a guard** — the server-side `CaptureService` validation is the real fix
  for over-capture; the `max` attribute is UX only.
- **Same caching caveat as Issue 1 H1** — `getCaptureableRaw` reads `getPaymentIntent(refresh=false)`;
  if Issue 1 turns out to be the stale-cache bug, the same staleness can mis-prefill capture after a
  same-request action. Fix the cache seam once for both.

---

## 10. Detailed solution — apply-ready code

> All snippets are written against the current files (line numbers as of this sprint).
> Issue 2 is fully concrete (root cause known). Issue 1 ships the **H3 regression net** in all
> cases plus the **H1 fix** to apply *iff* Phase A proves the stale-cache hypothesis.

### 10.1 Issue 2 — Capture prefill (full concrete implementation)

#### Step B2 — widen the DTO (additive, safe default)

`src/Stripe/Adapter/Dto/StripePaymentIntentDto.php`

```php
    /**
     * @param int                  $amount           Authorized amount in Stripe minor units
     * @param int                  $amountCapturable Amount still capturable in minor units (Stripe `amount_capturable`)
     * ...
     */
    public function __construct(
        public string $id,
        public string $status,
        public int $amount,
        public string $currency,
        public int $created,
        public ?string $latestChargeId,
        public ?StripeChargeDto $charge = null,
        public int $amountCapturable = 0,   // ← NEW, appended with default → all existing call sites compile
    ) {
    }
```

> Appended **after** `$charge` with a default, so positional and named existing constructions
> keep working ([[feedback_payment_base_additive_only]]). Run the full Stripe suite before/after —
> counts must match exactly.

`src/Stripe/Adapter/StripeObjectMapper.php` (the `StripePaymentIntentDto` construction, ~line 93)

```php
        return new StripePaymentIntentDto(
            id: (string) ($pi->id ?? ''),
            status: (string) ($pi->status ?? ''),
            amount: (int) ($pi->amount ?? 0),
            currency: (string) ($pi->currency ?? ''),
            created: (int) ($pi->created ?? 0),
            latestChargeId: $latestChargeId,
            charge: $chargeDto,
            amountCapturable: (int) ($pi->amount_capturable ?? 0),   // ← NEW
        );
```

#### Step C2 — fix the source method

`src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php:135`

```php
    // BEFORE — returns the full authorized amount:
    public function getCaptureableRaw(Order $order): float
    {
        $paymentIntent = $this->getPaymentIntent($order);
        if ($paymentIntent === null) {
            return 0.0;
        }
        $currency = strtoupper($paymentIntent->currency);
        return AmountConverter::toMajorUnits($paymentIntent->amount, $currency);   // ← BUG
    }

    // AFTER — returns the remaining capturable (Stripe's authoritative field):
    public function getCaptureableRaw(Order $order): float
    {
        $paymentIntent = $this->getPaymentIntent($order);
        if ($paymentIntent === null) {
            return 0.0;
        }
        $currency = strtoupper($paymentIntent->currency);
        return AmountConverter::toMajorUnits($paymentIntent->amountCapturable, $currency);
    }
```

`getCaptureableAmount()` (the formatted label) delegates to this method, so it is fixed
automatically — no second change, no parallel computation (DRY). The template
(`stripe_panel.html.twig:357`) needs **no change**: `value`/`max` already read `capturableRaw`,
which is now correct.

#### Step C2 — server-side over-capture guard

The browser `max` is UX only; the real guard belongs in `CaptureService`. Validate against the
contract's authorized-minus-captured remaining (no extra Stripe call needed — the contract already
tracks both via `getAmount()` and `getCapturedAmount()`).

`src/Stripe/Service/CaptureService.php::processCapture()` — insert after the existing
`> 0.0` check (line 60), before the state check:

```php
        if ($amount !== null && $amount <= 0.0) {
            return CaptureResponse::failure('Capture amount must be greater than zero');
        }

        // Issue 2: reject capture amounts above the remaining capturable. Browser `max`
        // is not a guard; an over-capture otherwise reaches Stripe and corrupts state.
        if ($amount !== null) {
            $remaining = $contract->getAmount() - ($contract->getCapturedAmount() ?? 0.0);
            if ($amount > $remaining + self::AMOUNT_EPSILON) {
                return CaptureResponse::failure(sprintf(
                    'Capture amount %.2f exceeds remaining capturable %.2f',
                    $amount,
                    max(0.0, $remaining)
                ));
            }
        }
```

with a class constant alongside the others:

```php
    /** Half a cent — currency-equality tolerance, matches the resolver's epsilon. */
    private const AMOUNT_EPSILON = 0.005;
```

> **Source-of-truth note:** the guard uses the **contract** (authorized − captured) because it is
> already in hand and needs no API round-trip ([[feedback_webhooks_inbound_only]] — query state we
> own, not a webhook-populated field). The UI prefill uses the **PI** `amount_capturable`. After a
> Stripe partial capture these converge to the same "remaining"; Phase A2's domain finding (§9.3)
> confirms whether they can legitimately diverge — if they can, switch the guard to read
> `amount_capturable` via the adapter so guard and prefill are single-sourced.

#### Step A2 — reproduction tests (write FIRST, RED today)

`tests/Unit/Stripe/Controller/Admin/OrderRefundViewDataProviderTest.php` (new case)

```php
public function testGetCaptureableRawReturnsRemainingCapturableNotFullAuthorized(): void
{
    // PI authorized 100.00, of which only 40.00 is still capturable.
    $pi = new StripePaymentIntentDto(
        id: 'pi_1', status: 'requires_capture', amount: 10000,
        currency: 'eur', created: 0, latestChargeId: null, charge: null,
        amountCapturable: 4000,
    );
    $provider = $this->providerReturning($pi);     // testable subclass stubbing getPaymentIntent()

    self::assertSame(40.0, $provider->getCaptureableRaw($this->order));   // RED today → returns 100.0
}
```

`tests/Unit/Stripe/Service/CaptureServiceTest.php` (new case)

```php
public function testProcessCaptureRejectsAmountAboveRemainingCapturable(): void
{
    $contract = $this->authorizedContract(amount: 100.0, captured: 60.0);   // remaining = 40.0
    $response = $this->captureService->processCapture($contract, 50.0, []); // 50 > 40

    self::assertFalse($response->isSuccessful());                            // RED today (no guard)
    self::assertStringContainsString('exceeds remaining capturable', (string) $response->getError());
}
```

#### Step D2 — builder-assembly SSOT regression

`tests/Unit/Stripe/Admin/StripePanelViewDataBuilderTest.php`

```php
public function testCapturableRawAndMaxAreSingleSourcedToAmountCapturable(): void
{
    // amount_capturable = 40.00 → assembled view-data prefill == 40.0
    $data = $this->buildFor($this->orderWithPi(amount: 10000, amountCapturable: 4000));

    self::assertSame(40.0, $data['capturableRaw']);          // prefill value
    self::assertSame('40,00', $data['capturableAmount']);    // formatted label (locale-dependent — adjust)
}
```

### 10.2 Issue 1 — Refund prefill (regression net always; H1 fix if confirmed)

#### Always — H3 builder-assembly regression (the missing coverage)

`tests/Unit/Stripe/Admin/StripePanelViewDataBuilderTest.php`

```php
public function testRemainingRefundableReconcilesToCapturedMinusRefunded(): void
{
    // charge captured 100.00, customer-refunded 30.00 → remaining 70.00
    $data = $this->buildFor($this->orderWithCharge(amountCaptured: 10000, amountRefunded: 3000));

    self::assertSame(70.0, $data['remainingRefundableRaw']);                  // prefill
    self::assertSame($data['remainingRefundableRaw'], $data['refundMaxRaw'] ?? $data['remainingRefundableRaw']);
    // SSOT cross-check: captured − refunded == remaining shown
}
```

> If this is GREEN, Issue 1 was already correct for the fresh-GET path (confirms **H3**); the value
> of the sprint is this lock + the H1 action-flow test below.

#### If Phase A proves H1 (stale per-request cache) — the fix

The render path reads `getPaymentIntent(refresh=false)`. The defect is that a charge cached
*earlier in the same request* (before a mutating action) is reused on re-render. Fix = a single
explicit cache-invalidation seam owned by the mutating-action path, not scattered `refresh=true`.

`src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php` — add an invalidation method:

```php
    /**
     * Drop the per-request PaymentIntent/charge cache so the next read re-fetches.
     * Called by the admin action path after a capture/refund/cancel mutates Stripe state,
     * so the re-rendered panel reflects post-action amounts.
     */
    public function invalidateApiCache(): void
    {
        $this->apiOrder  = null;
        $this->apiCharge = null;
        $this->apiError  = null;
    }
```

`src/Stripe/Controller/Admin/OrderActionDispatcher.php` (or the PaymentAdmin controller that
dispatches the action) — after a successful capture/refund/cancel and before the panel re-renders:

```php
        // The action just changed Stripe-side amounts; force the panel to re-read fresh state.
        $this->viewDataProvider->invalidateApiCache();
```

H1 reproduction test (RED before the fix):

```php
public function testRenderAfterRefundReflectsNewRefundNotStaleCache(): void
{
    $provider = $this->providerReturning($this->chargeFactory);  // factory returns evolving charge
    $provider->getRemainingRefundableRaw($this->order);          // primes cache: pre-refund (refunded=0)

    $this->chargeFactory->applyRefund(3000);                     // a refund happens in-request
    $provider->invalidateApiCache();                             // ← the fix; remove this line to see RED

    self::assertSame(70.0, $provider->getRemainingRefundableRaw($this->order)); // stale cache → 100.0
}
```

> Keep `refresh=false` on the normal render read (it avoids a redundant API call on plain GETs —
> the Sprint 104 optimization). Correctness comes from the action path **invalidating** once after a
> mutation, not from making every render refresh. One seam, no flag soup (SRP).

### 10.3 Why these fixes respect the constraints

- **DRY / SSOT:** capture prefill, `max`, and label all flow through the one corrected
  `getCaptureableRaw`; refund prefill, `max`, and "already refunded" all reconcile to one charge
  read. No second computation introduced.
- **SOLID:** the DTO widening is the Interface-Segregation-safe additive change; the cache fix puts
  invalidation responsibility on the mutator (SRP), the over-capture rule in the service that owns
  capture policy (not the controller or the template).
- **No overengineering:** no new interfaces or collaborators — one DTO field, one mapper line, one
  method body change, one guard block, one invalidation method. Everything else is tests.
