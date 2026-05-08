# Sprint 95 — Purge concrete-PSP references from opalreturns tests; harden the arch-guard

**Repo:** `extensions/opalreturns`
**Mode:** TDD-first. The architecture guard is the test that drives
every other change in this sprint. No production code change.
**Trigger report:**
[`../reports/05-opalreturns-tests-leak-stripe-references.md`](../reports/05-opalreturns-tests-leak-stripe-references.md)

## 1. Why

`reports/05-…md` documents the violation: opalreturns tests import
Stripe / PayPal classes and hard-code provider names like `'stripe'`,
even though opalreturns is — by design — provider-agnostic and
production code is fully clean. The arch-guard in
`bin/pre-commit-check.sh` only audits `src/`, so the leak slipped in
silently.

This sprint **does not** change any production behaviour. It moves
opalreturns tests back behind the abstract payment-component
boundary, deletes the leaks, and hardens the guard so future leaks
fail CI immediately.

## 2. Goals

- **G1** — Arch-guard fails on the current `tests/` tree. A new
  failing test (red) drives the guard widening — see §4.1.
- **G2** — `tests/Unit/Listener/PaymentComponentRefundBrokerListenerTest.php`
  no longer references `'stripe'`. All `'stripe'` literals replaced
  by an opaque test-local provider name; assertions on the abstract
  broker contract only.
- **G3** — `tests/Integration/Support/PaymentComponentChainFixture.php`
  is provider-agnostic. No PSP imports; method renamed; default
  provider is a fake; helper exposes a single `brokerSpy` (not
  `stripeSpy` / `stripeCancelSpy`); spy captures abstract events.
- **G4** — `tests/Integration/RefundFlowIntegrationTest.php` (Sprint H)
  removed from opalreturns. Replaced by
  `tests/Integration/Resolve*BrokerDispatchTest.php` files that
  assert on the broker boundary; the Stripe / PayPal end-to-end
  coverage moves to the respective PSP modules' own test suites
  (see §6 for the follow-ups).
- **G5** — `tests/Integration/AdminResolveDispatchesStripeRefundEventTest.php`
  and `...CancelAuthorizationEventTest.php` are renamed and
  rewritten to assert on the broker boundary. Class names no longer
  contain "Stripe"; no PSP imports; assertions target
  `RefundRequestedEvent` / `CancelAuthorizationRequestedEvent`.
- **G6** — Arch-guard goes green. New widened guard reports zero
  violations across `src/` AND `tests/` AND `services.yaml`.
- **G7** — Test count never drops below the pre-sprint baseline:
  `265 → 265` or higher after the sprint; coverage shifts shape
  (broker-level), not size.

## 3. The five pillars — explicit application

### 3.1 SOLID

- **SRP.** The chain fixture today owns three things at once: building
  the dispatcher chain, building a contract mock, and registering
  PSP-specific spies. Sprint 95 splits that into:
  - `OpalReturnsTestChain` (in-memory bus, broker spy, dispatcher).
  - `ContractFixtures` (named factories for the four contract states
    used in the suite: captured / authorized / pending / cancelled).
  - The test itself wires them together.
- **OCP.** The fixture must accept new request-event types
  (CaptureRequested, future) without further edits. After the
  refactor it does — the broker spy captures every
  `AbstractProviderRequestEvent` regardless of subclass.
- **LSP.** Tests must not narrow the type they depend on below the
  production contract. The production code depends on
  `AbstractProviderRequestEvent` (or its specific abstract
  subclasses) — tests must too.
- **ISP.** The chain helper grew six concerns into one God object.
  Split into the two narrow classes above.
- **DIP.** All test seams take interfaces. `EventBrokerInterface`
  (mocked) is the abstraction the tests bind to — never a concrete
  Stripe / PayPal class.

### 3.2 TDD

The walking order is locked in §4. The guard test is **first**,
fails on the current tree, and is what actually forces every
subsequent change.

### 3.3 DRY

- The contract-mock builder is currently duplicated in three test
  files (Sprint H test, broker-listener unit test, chain fixture).
  Sprint 95 collapses those into a single
  `ContractFixtures::captured() / authorized() / pending() / cancelled()`
  helper. The unit test uses it via composition; the integration
  fixture uses it via composition; the legacy test calls it instead
  of building its own.

### 3.4 Liskov

- The `PaymentContractInterface` mock returned by `ContractFixtures`
  is a strict implementation of the production interface. No method
  is shadowed; no behaviour narrowed. Production code can use the
  fixture-built contract without surprise.
- The broker spy implements `EventBrokerInterface` directly (real
  PHP class, not PHPUnit mock) so that other tests can substitute it
  in places that need a real broker. This swap is the point of LSP
  in our test seam.

### 3.5 Clean Code / DI

- No statics on the test-internal helpers. Constructor-injected
  collaborators only.
- Each method ≤ 25 lines; long PHPUnit `setUp` blocks split into
  named helpers.
- One assertion-cluster per test method (AAA layout).
- Class / method / constant names contain no PSP brand:
  `Stripe`, `PayPal`, `stripe`, `paypal` are forbidden anywhere
  except the arch-guard test itself.

## 4. The work, in dependency order

### 4.1 Step 1 — TDD seed: a failing arch-guard test

Add `tests/Unit/Architecture/NoConcretePspReferencesTest.php`:

```php
final class NoConcretePspReferencesTest extends TestCase
{
    /**
     * @return array of [path] tuples, keyed by subtree name
     */
    public static function moduleSubtreesProvider(): array
    {
        $root = dirname(__DIR__, 3); // module root
        return [
            'src'           => [$root . '/src'],
            'services.yaml' => [$root . '/services.yaml'],
            'tests'         => [$root . '/tests'],
        ];
    }

    #[DataProvider('moduleSubtreesProvider')]
    public function testNoStripeOrPayPalReferences(string $path): void
    {
        // Regex (PHP-escaped) matches:
        //   - "use OxidEsales\Payments\Stripe..."
        //     and the same for PayPal
        //   - any FQN reference "OxidEsales\Payments\Stripe\..."
        //   - the string literals 'stripe' or 'paypal'
        // The regex source lives in
        // tests/Architecture/psp-blacklist.regex and is loaded at
        // runtime; that file is the single source of truth shared
        // with the bash arch-guard in §4.6 (DRY).
        $hits = $this->grep(
            $path,
            $this->loadBlacklistRegex()
        );
        // Allow the test class itself to mention the names —
        // exempt by absolute path.
        $hits = array_filter(
            $hits,
            fn (string $line): bool
                => !str_contains($line, 'NoConcretePspReferencesTest')
        );

        self::assertSame(
            [],
            $hits,
            "PSP references found under $path:\n" . implode("\n", $hits)
        );
    }

    /** @return list of "file:line: matched-text" strings */
    private function grep(string $path, string $regex): array { /* ... */ }
}
```

This test runs **inside PHPUnit**, so it ships with the suite, runs
in CI, and on every developer machine. The bash-grep guard in
`pre-commit-check.sh` is upgraded to call the same regex against
`src/` + `tests/` + `services.yaml`, but the PHPUnit guard is the
source of truth.

The test must fail at this commit — that is the green light to
start §4.2 onwards. Each subsequent step removes references and the
test moves toward green. The sprint is done when the test is green.

### 4.2 Step 2 — Extract `ContractFixtures` (SRP, DRY)

`tests/Support/Contract/ContractFixtures.php`:

```php
final class ContractFixtures
{
    public static function captured(TestCase $t, ?float $amount = 100.0): PaymentContractInterface
    {
        $contract = $t->createMock(PaymentContractInterface::class);
        $contract->method('getProvider')->willReturn(self::FAKE_PROVIDER);
        $contract->method('getId')->willReturn('contract-captured');
        $contract->method('getCapturedAmount')->willReturn($amount);
        $contract->method('getState')->willReturn(ContractState::committed());
        $contract->method('getStateValue')->willReturn('committed');
        return $contract;
    }
    public static function authorized(TestCase $t): PaymentContractInterface { /* … */ }
    public static function pending(TestCase $t): PaymentContractInterface { /* … */ }

    public const FAKE_PROVIDER = 'opalreturns_test_provider';
}
```

The name `'opalreturns_test_provider'` is intentionally NOT a real
PSP. Any test that wants to verify provider-name plumbing can use
this constant; no test ever asserts `'stripe'`. (The arch-guard
allows this string because it doesn't match `'stripe'` /
`'paypal'`.)

The `PaymentComponentRefundBrokerListenerTest` rewires its 6
`'stripe'` literals to `ContractFixtures::FAKE_PROVIDER`. That
deletes 6 violations.

### 4.3 Step 3 — Replace the chain fixture with `OpalReturnsTestChain`

`tests/Support/Chain/OpalReturnsTestChain.php`:

```php
final class OpalReturnsTestChain
{
    public readonly OpalEventDispatcherInterface $opalDispatcher;
    public readonly EventBrokerInterface $broker;
    public readonly BrokerSpy $brokerSpy;
    public readonly ReturnRepositoryInterface $returns;
    public readonly StatusTransitionServiceInterface $transitionService;
    public readonly TransitionLog $transitionLog;
    public readonly ?PaymentContractInterface $contract;

    public static function build(TestCase $t, ChainOptions $opts): self;
}
```

`BrokerSpy` is a real class (not a PHPUnit mock) implementing
`EventBrokerInterface`:

```php
final class BrokerSpy implements EventBrokerInterface
{
    /** @var array of dispatched AbstractProviderRequestEvent items */
    public array $dispatched = [];

    public function dispatch(AbstractProviderRequestEvent $event): AbstractProviderRequestEvent
    {
        $this->dispatched[] = $event;
        return $event;
    }

    public function lastDispatched(): ?AbstractProviderRequestEvent { /* ... */ }

    public function lastOf(string $abstractEventClass): ?AbstractProviderRequestEvent
    {
        foreach (array_reverse($this->dispatched) as $e) {
            if ($e instanceof $abstractEventClass) return $e;
        }
        return null;
    }
}
```

This is the test seam that replaces the old PSP-spy listeners on the
PC dispatcher. **No translator is registered.** opalreturns dispatches
a `RefundRequestedEvent` or `CancelAuthorizationRequestedEvent`; the
spy captures it. End of opalreturns's responsibility.

`ChainOptions` is a small immutable value object:

```php
final readonly class ChainOptions
{
    public function __construct(
        public string $returnId,
        public string $orderId,
        public string $contractState = 'captured', // 'captured'|'authorized'|'pending'
        public ?float $capturedAmount = null,
        public bool $contractFound = true,
    ) {}
}
```

### 4.4 Step 4 — Rewrite the controller-rooted integration tests

Files to land:

1. `tests/Integration/AdminResolveDispatchesRefundOnCapturedContractTest.php`
   (renamed from `AdminResolveDispatchesStripeRefundEventTest`).
   Asserts `$chain->brokerSpy->lastOf(RefundRequestedEvent::class)`
   has the right amount / orderId / contractId / reason.
2. `tests/Integration/AdminResolveDispatchesCancelAuthOnAuthorizedContractTest.php`
   (renamed from `AdminResolveDispatchesStripeCancelAuthorizationEventTest`).
   Asserts `$chain->brokerSpy->lastOf(CancelAuthorizationRequestedEvent::class)`
   has the right context.
3. `tests/Integration/AdminResolveLogsAndSkipsOnUnsupportedContractStateTest.php`
   New. The pending-state path that today only has unit-level
   coverage gets a controller-rooted integration test too.

Each test imports **only** payment-component types. None mention
Stripe.

### 4.5 Step 5 — Decide on Sprint H's `RefundFlowIntegrationTest`

Today's test exercises the cross-module fan-out using real
`StripeEventTranslator` / `PayPalEventTranslator` classes. This
sprint **deletes** the file from opalreturns/tests/. The same
coverage moves out of opalreturns:

- A new `extensions/stripe/tests/Integration/StripeTranslatorRoutesAbstractRefundToStripeHandlerTest.php`
  asserts the Stripe translator's translation contract.
- A symmetric file lands (or is filed as a follow-up sprint) in
  `extensions/payment-component/tests/...` (or paypal-payment) for
  PayPal.

Until the Stripe-side test is in place, the deletion is **only**
acceptable if the Stripe module already has equivalent translator
coverage (verified in Sprint 95 §6 follow-ups). If it does not, the
test is **moved**, not deleted, and the sprint defers G4 to a
follow-up sprint.

### 4.6 Step 6 — Harden the bash arch-guard

`extensions/opalreturns/bin/pre-commit-check.sh` is updated so its
PSP-reference check:

1. Loads its regex source from
   `tests/Architecture/psp-blacklist.regex` — the same file consumed
   by the PHPUnit guard in §4.1, so the two cannot drift (DRY).
2. Searches `src/`, `services.yaml`, and `tests/` (was: only `src/`).
3. Excludes the guard test itself
   (`tests/Unit/Architecture/NoConcretePspReferencesTest.php`).

Pseudocode:

- the script runs `BLACKLIST="$(cat tests/Architecture/psp-blacklist.regex)"`
- it then runs
  `grep -rnE "$BLACKLIST" src services.yaml tests --exclude='NoConcretePspReferencesTest.php'`
- non-empty output fails the check.

The PHPUnit-resident guard from §4.1 is the canonical rule; this bash
copy is the fast pre-commit feedback loop.

### 4.7 Step 7 — Tidy

- Delete `tests/Integration/Support/PaymentComponentChainFixture.php`
  and `PaymentComponentChainHandles.php` (replaced by §4.3).
- Update the existing
  `tests/Unit/Listener/PaymentComponentRefundBrokerListenerTest.php`
  to use `ContractFixtures` and `ContractFixtures::FAKE_PROVIDER`.
- Run full pre-commit; full green required to land.

## 5. Acceptance criteria

The sprint is done when **all** are simultaneously true:

- [ ] `tests/Unit/Architecture/NoConcretePspReferencesTest.php`
      exists and is green.
- [ ] The following grep against `extensions/opalreturns/src`,
      `extensions/opalreturns/tests`, and
      `extensions/opalreturns/services.yaml` reports zero matches
      outside of the arch-guard test itself:

  ```bash
  grep -rE 'OxidEsales\\Payments\\(Stripe|PayPal)|['\''"](stripe|paypal)['\''"]' "$paths"
  ```

- [ ] No file under `extensions/opalreturns/tests` `use`-imports any
      `OxidEsales\Payments\*` namespace.
- [ ] Class names under `tests/Integration/` contain neither
      "Stripe" nor "PayPal".
- [ ] `bin/pre-commit-check.sh --no-smoke` passes (PHPCS 0, PHPStan
      max 0, arch-guards passed, PHPUnit fully green).
- [ ] PHPUnit test count is at least 265 (the sprint's pre-state
      baseline).
- [ ] Test deprecations stay at the baseline (4) and risky stays at
      the baseline (2).

## 6. Out of scope / follow-ups

- **Stripe-side translator test.** A test that asserts
  `StripeEventTranslator::translate(RefundRequestedEvent)` returns a
  `StripeRefundRequestEvent` belongs in
  `extensions/stripe/tests/Unit/EventSystem/Translator/StripeEventTranslatorTest.php`,
  not in opalreturns. If absent today, sprint 96 lands it.
- **PayPal-side translator test.** Symmetric; lives in
  `extensions/paypal-payment/tests/...`.
- **PaymentComponent broker integration test.** The "broker picks
  the supporting translator" assertion is part of payment-component
  itself and should live in
  `extensions/payment-component/tests/...`.
- **A `PaymentAuthorizationCancelledEvent` outgoing event.** Listed
  as pending in `status.md`; out of scope here.

## 7. TDD walking order (concrete)

1. **Red.** Land §4.1 (`NoConcretePspReferencesTest`) — fails because
   the current tree is full of violations.
2. **Green ← step.** §4.2 (`ContractFixtures`) + rewrite of
   `PaymentComponentRefundBrokerListenerTest`. Run only that test
   file plus the guard test — both green.
3. **Green ← step.** §4.3 + §4.4 (chain fixture + controller-rooted
   integration tests). Run integration suite — green.
4. **Decision point.** §4.5: confirm Stripe-side translator test
   exists; if yes delete, if no move. Either path keeps the suite
   green.
5. **Green ← step.** §4.6 (bash arch-guard widening). Trigger the
   bash guard locally to verify it now matches what PHPUnit asserts.
6. **Green ← step.** §4.7 (delete dead helpers). Full pre-commit.
7. **Land.** Move sprint to `done/`, write completion report.

## 8. Done definition (checklist)

- [ ] Acceptance criteria from §5, every box.
- [ ] Sprint moved to `done/sprint-95-…md`.
- [ ] `done/sprint-95-completion-report.md` filed alongside.
- [ ] `status.md` updated with the new test counts and a row noting
      the architecture-rule enforcement is now live in PHPUnit.
- [ ] Pending list in `status.md` updated to remove the now-resolved
      "PSP references in opalreturns tests" entry (if added in the
      meantime) and to add the §6 follow-ups for the PSP modules.

## 9. Risk register

- **Risk:** §4.5 deletes coverage we did not yet replicate elsewhere.
  **Mitigation:** §4.5 is gated on confirming the Stripe (and
  PayPal) module already have translator-level tests; if not, the
  sprint defers G4 (one row of the acceptance list) to a follow-up
  sprint and the file remains, but with an `@group cross-module`
  tag and `@group skip-on-opalreturns-only` so a stand-alone
  opalreturns CI can skip it. The arch-guard exempts files in
  `tests/CrossModule/` (a new dir) only if explicitly allow-listed
  with a reason.
- **Risk:** the bash-side and PHPUnit-side guards drift.
  **Mitigation:** factor the regex into a single shared definition
  (`tests/Architecture/psp-blacklist.regex` file consumed by both).
- **Risk:** new test files re-introduce the leak.
  **Mitigation:** the PHPUnit guard is the canonical rule; CI will
  catch it within seconds of opening a PR.