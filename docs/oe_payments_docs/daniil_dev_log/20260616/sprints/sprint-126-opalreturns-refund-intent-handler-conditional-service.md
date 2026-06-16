# Sprint 126 — opalreturns: make `RefundIntentHandler` wiring tolerant of an absent/old payment-base

**Repo:** `extensions/opalreturns` · **Branch:** `b-7.4.x-agnosticism` (off `fbda203`)
**Ticket:** STRP-135 (refund decision logic transferred to payment-base)
**Companion report:** `../reports/01-opalreturns-refund-intent-handler-hard-dependency.md`
**Mode:** TDD-first, multi-commit per phase (A → B → C → D). Each phase RED → GREEN → REFACTOR, its own commit.
**Binding every commit:** TDD · SOLID · DRY · Clean Code · DI · **No overengineering** · PSR-12 · PHPStan max.

---

## 0. The bug in one paragraph

`oe:module:activate opalreturns` fatals at container compile when the installed
payment-base lacks `OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler`:

```
Invalid service "opalreturns.refund_intent_handler":
class "OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler" does not exist.
```

`services.yaml:245` declares a service whose `class:` **is** that payment-base FQCN.
Symfony validates `class:` against the autoloader at compile time
(`AbstractRecursivePass`), so a missing class is a hard fatal — the `@?` on the
*arguments* is irrelevant (it only nullifies argument resolution, never the `class:`).

## 1. Constraints that decide the design (verified this session)

1. **payment-base is OPTIONAL.** `composer.json` lists it under `suggest`, not
   `require`: *"opalreturns falls back to manual admin refund when payment-base is
   not installed."* → **Clean activation without payment-base is a requirement.**
2. **Only ONE service fatals.** The other 5 opalreturns files that touch payment-base
   (`ReturnEligibilityService`, `RefundableAmountService`, `PaymentBaseResolutionHandler`,
   `PaymentRefundedReturnResolver`, `DispatchRefundCommand`) reference it **only via
   `@?`-optional constructor args**. PHP reflection tolerates a missing parameter
   type, and `@?` resolves it to `null` → they compile fine. Don't touch them.
3. **Second, latent landmine:** `src/Domain/Event/ReturnRefundRequestedEvent.php`
   `implements OxidEsales\PaymentBase\EventSystem\Event\Request\RefundIntentEventInterface`
   (hard `implements` + top-of-file `use`). This fatals on **autoload of the event
   class** whenever payment-base is absent — *if* that event is ever loaded in the
   no-payment-base path. Must be assessed (Phase C).
4. **OXID 7.4 modules cannot reliably register a Symfony compiler pass.** The shop
   builds the container and merges module `services.yaml` via generated project YAML;
   there is no documented module hook to push a `CompilerPassInterface`
   (the compiler passes that exist — `ViewControllerPass` etc. — are shop-core, not
   module-registered). → **Reject the "compiler pass" option.** It's the colleague's
   option 1 and the most fragile here.
5. **OXID auto-imports only the module-root `services.yaml`.** There is no native
   `class_exists`-guarded YAML include. → **Reject the "conditional YAML include"
   option** (colleague's option 3) — it would need the same unavailable hook.

**Chosen mechanism: a factory service (colleague's option 2).** It is pure
`services.yaml` + one opalreturns-owned class, needs no container hook, is fully
unit-testable, and — bonus — handles *absent* and *too-old* payment-base identically
(both → graceful manual-refund fallback), which a version constraint cannot.

## 2. Target design

```
src/Integration/PaymentBase/                 # the ONLY opalreturns dir allowed to name payment-base symbols at runtime
├── RefundIntentListenerFactory.php          # factory: returns real handler XOR null-listener
└── NullRefundIntentListener.php             # opalreturns-owned no-op __invoke (manual-refund fallback)
```

`services.yaml` (replaces the hard `class:` block at lines 245–256):

```yaml
  opalreturns.refund_intent_handler:
    # class: intentionally omitted — resolved by the factory at runtime so the
    # container never class_exists()-validates a payment-base FQCN at compile time.
    factory: ['@Opal\OpalReturns\Integration\PaymentBase\RefundIntentListenerFactory', 'create']
    tags:
      - { name: 'kernel.event_listener', event: 'Opal\OpalReturns\Domain\Event\ReturnRefundRequestedEvent' }
    public: true

  Opal\OpalReturns\Integration\PaymentBase\RefundIntentListenerFactory:
    arguments:
      $contractRepository: '@?OxidEsales\PaymentBase\Repository\ContractRepositoryInterface'
      $broker:             '@?OxidEsales\PaymentBase\EventSystem\Broker\EventBrokerInterface'
      $captureStatusQuery: '@?OxidEsales\PaymentBase\Service\PaymentCaptureStatusQueryInterface'
      $logger:             '@?Psr\Log\LoggerInterface'
    public: false
```

```php
final class RefundIntentListenerFactory
{
    public function __construct(
        private readonly ?ContractRepositoryInterface $contractRepository = null,
        private readonly ?EventBrokerInterface $broker = null,
        private readonly ?PaymentCaptureStatusQueryInterface $captureStatusQuery = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @return callable(object):void */
    public function create(): callable
    {
        if (!$this->paymentBaseAvailable()) {
            return new NullRefundIntentListener();          // manual-refund fallback
        }
        return new RefundIntentHandler(                     // guarded → autoload only when present
            $this->contractRepository,
            $this->broker,
            $this->captureStatusQuery,
            $this->logger,
        );
    }

    // protected seam so a testable subclass can force both branches without payment-base installed
    protected function paymentBaseAvailable(): bool
    {
        return class_exists(RefundIntentHandler::class);
    }
}
```

> **Why `class:` is omitted, not set to the payment-base FQCN:** Symfony's
> `EventDispatcherPass` registers a *lazy* listener that references the service **id**;
> it does not need the produced class at compile time. Omitting `class:` is what keeps
> the container clean when payment-base is gone. (If a future OXID/Symfony bump starts
> demanding `class:` for factory services, set it to the **factory's** own class, never
> the payment-base one.)

> **The `use OxidEsales\PaymentBase\…\RefundIntentHandler;` at the top of the factory
> file is safe** — a bare `use` never triggers autoload; only the guarded `new` does,
> and `class_exists()` returns `true` there only when the class is really present.

## 3. Phases (TDD, one commit each)

### Phase A — Characterization net (RED first, no production change)
Lock in CURRENT behavior before refactoring (per our refactor discipline).
- **Test A1 (no payment-base):** boot the opalreturns service container *without*
  payment-base on the autoload path; assert it compiles and
  `opalreturns.refund_intent_handler` is **absent/degraded** today → this is the
  failing reproduction of the activate fatal. Mark it the regression guard.
- **Test A2 (with payment-base):** with payment-base present, assert the handler
  service exists, is `public`, is `__invoke`-able, and is tagged as a
  `kernel.event_listener` for `ReturnRefundRequestedEvent`.
- Commit: `STRP-135 test: characterize refund_intent_handler wiring (both deps states)`.

### Phase B — Factory + null-listener (GREEN the activate fatal)
- Add `NullRefundIntentListener` (opalreturns namespace, `__invoke(object): void` no-op).
- Add `RefundIntentListenerFactory` with the `paymentBaseAvailable()` seam.
- Unit-test the factory **both branches** via a testable subclass overriding the seam
  (no need to actually uninstall payment-base): returns `RefundIntentHandler` when
  available, `NullRefundIntentListener` when not.
- Rewrite `services.yaml:245–256` to the factory form (Section 2). Register the factory
  service with the `@?` deps.
- Re-run Phase A tests: A1 now compiles cleanly with the null-listener; A2 still green
  with the real handler. **The activate fatal is gone.**
- Commit: `STRP-135 fix: resolve refund_intent_handler via factory (tolerate absent/old payment-base)`.

### Phase C — Decouple the event from the optional interface (latent landmine)
- **Verify first:** can `ReturnRefundRequestedEvent` be autoloaded when payment-base is
  absent? Trace its dispatch sites (`PaymentBaseResolutionHandler` and any
  credit-resolution path). Two outcomes:
  - **C-skip:** if the event is *only ever* constructed on a payment-base-present code
    path, the hard `implements` is latently safe. Document it explicitly in the class
    docblock + a guard test that fails if a non-payment-base path starts dispatching it.
    No structural change. (Preferred if true — no overengineering.)
  - **C-decouple:** if a no-payment-base path can load it, remove
    `implements RefundIntentEventInterface` from the event and instead have the factory
    wrap the opalreturns event in an `Integration/PaymentBase/` adapter that implements
    the interface (adapter loaded only when present), so `RefundIntentHandler`'s
    `instanceof RefundIntentEventInterface` check still matches. The event becomes
    payment-base-agnostic; all payment-base contact stays in `Integration/PaymentBase/`.
- Commit: either `STRP-135 docs+test: assert ReturnRefundRequestedEvent stays on payment-base path`
  or `STRP-135 refactor: decouple ReturnRefundRequestedEvent from RefundIntentEventInterface via adapter`.

### Phase D — Quality gates + integration proof
- `vendor/bin/phpunit` (opalreturns suite) — all green; record before/after counts.
- `phpstan` (module `phpstan.neon`) — 0 new errors; no new suppressions (memory rule).
- PHPCS PSR-12, PHPMD — clean.
- **Integration proof, both states**, in Docker on the real tree (no worktree — breaks
  the docker mount; sequential, not parallel):
  1. payment-base **absent** → `oe:module:activate opalreturns` → exit 0, shop HTTP 200,
     a credit return falls back to manual admin refund (no fatal).
  2. payment-base **present** (a build that ships `RefundIntentHandler`) →
     activate → HTTP 200 → a credit return routes a real `RefundIntentEvent` through
     the broker.
- Commit: `STRP-135 chore: quality reports + dual-state activation proof`.

## 4. Prong A — payment-base side ✅ RESOLVED (2026-06-16)

The factory makes opalreturns *safe*; real refund routing also needs payment-base to
ship the contract on the branch opalreturns/CI pulls. **This is now on canonical
`origin/b-7.4.x` @ `7bc2ef9`** ("STRP-157 Per-line summation in CO-session") — verified
via `git ls-tree -r origin/b-7.4.x`:
- `EventSystem\Handler\RefundIntentHandler` ✅
- `EventSystem\Event\Request\RefundIntentEventInterface` ✅
- the handler's DI registration (`services.yaml:94`, `public: true`) ✅

So a clean `composer update` against canonical payment-base ships the class, and the
factory resolves the **real** handler. **No composer constraint change** — payment-base
stays `suggest`; the factory treats "too old" exactly like "absent."

Still open (separate, not blocking activation): `EventBrokerInterface` is `@?` on both
sides with no concrete binding yet — the **active PSP** (Stripe/PayPal) must bind it for
routing to actually fire; until then the handler early-returns (safe degradation).

## 5. Definition of done
- [ ] `oe:module:activate opalreturns` exits 0 with payment-base **absent** (regression test A1 green).
- [ ] Real refund routing works with a payment-base that ships `RefundIntentHandler` (A2 + integration green).
- [ ] All payment-base runtime references confined to `src/Integration/PaymentBase/` (or event landmine documented as path-safe per C-skip).
- [ ] PHPUnit / PHPStan(max) / PHPCS / PHPMD clean; no new suppressions.
- [x] Report + this sprint linked; quality reports refreshed.
- [x] Prong A merged on canonical `origin/b-7.4.x` @ `7bc2ef9` (verified 2026-06-16) — merge gate green.

## 6. Risks / watch-outs
- **Omitted `class:` on a factory listener** — confirm OXID 7.4's `EventDispatcherPass`
  accepts it (Phase B test A2 covers this). Fallback: set `class:` to the *factory's* class.
- **Null-listener must truly no-op** — assert `__invoke` does nothing for any event, so
  the manual-refund fallback path is unaffected.
- **Don't widen the fix to the 5 `@?`-only files** — they already degrade correctly;
  touching them is scope creep.
- **Docker-mounted module** — sequential agent/manual runs only; no `worktree` isolation.
