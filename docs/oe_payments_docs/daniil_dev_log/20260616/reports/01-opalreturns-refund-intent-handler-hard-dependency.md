# opalreturns ↔ payment-base: hard service dependency on `RefundIntentHandler`

**Date:** 2026-06-16
**Author:** Daniil Tkachev
**Ticket:** STRP-135 (refund decision logic transferred to payment-base)
**Branches in play:**
- `opalreturns` @ `b-7.4.x-agnosticism` (`fbda203` — *"STRP-135 Refund desision logic transfered to PaymentBase"*)
- `payment-base` local @ `b-7.4.x-math-STRP-157` (`57736fd` — *"RC-1 release"*)
- Colleague's last-testable payment-base: `OXID-eSales/payment-base@b-7.4.x:01aa868` (this morning)
- Colleague is parked on opalreturns `b-7.4.x` @ `f2757ab`, shop HTTP 200, waiting on this fix.

---

## 1. Symptom

`oe:module:activate opalreturns` fatals during container compilation when payment-base
does **not** contain `RefundIntentHandler`:

```
PHP Fatal error:  Symfony\Component\DependencyInjection\Exception\RuntimeException:
Invalid service "opalreturns.refund_intent_handler":
class "OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler" does not exist.
in vendor/symfony/dependency-injection/Compiler/AbstractRecursivePass.php:180
```

## 2. Root cause

opalreturns `services.yaml` (lines 245–256 on `b-7.4.x-agnosticism`) declares a
second service id that **re-states the FQCN** so it can attach a
`kernel.event_listener` tag for its own concrete event without clobbering
payment-base's own definition (Symfony resolves duplicate service ids as
last-write-wins, *including* tag arrays):

```yaml
opalreturns.refund_intent_handler:
  class: OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler
  arguments:
    $contractRepository: '@?OxidEsales\PaymentBase\Repository\ContractRepositoryInterface'
    $broker:             '@?OxidEsales\PaymentBase\EventSystem\Broker\EventBrokerInterface'
    $captureStatusQuery: '@?OxidEsales\PaymentBase\Service\PaymentCaptureStatusQueryInterface'
    $logger:             '@?Psr\Log\LoggerInterface'
  tags:
    - { name: 'kernel.event_listener', event: 'Opal\OpalReturns\Domain\Event\ReturnRefundRequestedEvent' }
  public: true
```

**The `@?` optionality is a red herring.** `@?` only governs *argument* resolution —
it lets an argument resolve to `null` when the referenced service is missing.
It does **nothing** for the `class:` key. Symfony validates `class:` against the
real autoloader at compile time (`AbstractRecursivePass`), so a missing class is a
hard fatal regardless of how optional the constructor args are.

So the service definition is only loadable when the class physically exists on the
autoload path — i.e. only when a payment-base that ships `RefundIntentHandler` is
installed.

## 3. Current local reality (why it works on my box, not the colleague's)

On my local payment-base (`b-7.4.x-math-STRP-157` @ `57736fd`) the contract is
**already fulfilled**:

| Artifact | Path | Status |
|---|---|---|
| `RefundIntentHandler` | `payment-base/src/EventSystem/Handler/RefundIntentHandler.php` | ✅ present |
| `RefundIntentEventInterface` | `payment-base/src/EventSystem/Event/Request/RefundIntentEventInterface.php` | ✅ present |
| Handler DI registration | `payment-base/services.yaml:94` | ✅ present (`public: true`, same `@?` args) |

Verified constructor signature (matches opalreturns' arg names 1:1):

```php
public function __construct(
    private readonly ?ContractRepositoryInterface $contractRepository = null,
    private readonly ?EventBrokerInterface $broker = null,
    ?PaymentCaptureStatusQueryInterface $captureStatusQuery = null,
    ?LoggerInterface $logger = null,
) { ... }
```

`__invoke()` already degrades safely: it early-returns when `$contractRepository`
or `$broker` is null — so the `@?` optional args are handled correctly **at runtime**.
The problem is strictly **compile-time class resolution**, which runtime guards can't help.

**The gap is a sequencing/branch-skew problem, not a code-correctness problem:**
the class exists on `b-7.4.x-math-STRP-157` but has **not yet landed on the canonical
`OXID-eSales/payment-base@b-7.4.x`** that the colleague (and CI) pull. Their morning
checkout `01aa868` predates it → fatal.

## 4. What has to converge for `b-7.4.x-agnosticism` to be mergeable

Two independent prongs, both required (confirms Comment 213810):

**Prong A — payment-base fulfills the service contract on `b-7.4.x`.**
Merge the `math-STRP-157` (or its STRP-135 subset) work into canonical
`payment-base@b-7.4.x` so it carries:
- `OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler`
- `OxidEsales\PaymentBase\EventSystem\Event\Request\RefundIntentEventInterface`
- the handler's own DI registration (already at `services.yaml:94`).
- `EventBrokerInterface` is referenced `@?` on both sides and has no concrete
  binding in payment-base yet — fine for now (resolves to `null`, handler early-returns),
  but the active PSP must bind it for refund routing to actually fire.

**Prong B — opalreturns stops hard-referencing the FQCN.**
Make `opalreturns.refund_intent_handler` tolerant of a payment-base that lacks the class,
so activation degrades (no refund routing) instead of fataling. Options, cleanest first:

1. **Tag the existing payment-base service via a compiler pass** (preferred).
   payment-base *already* registers the handler (`services.yaml:94`). opalreturns
   doesn't need a second definition at all — a `CompilerPassInterface` can, guarded by
   `$container->has(RefundIntentHandler::class)`, append the
   `kernel.event_listener` tag (event = `ReturnRefundRequestedEvent`) to the existing
   definition. No FQCN in YAML → no compile-time fatal; pass is a no-op when payment-base
   is absent. Sidesteps the last-write-wins tag clobbering entirely.

2. **Class-existence-guarded YAML include.** Move the `opalreturns.refund_intent_handler`
   block into `services_with_payment_base.yaml` and load it from the module's
   `ConfigurableServices`/`ServicesYaml` only when
   `class_exists(RefundIntentHandler::class)`.

3. **Factory service.** Replace `class:`+`arguments:` with a `factory:` that returns the
   handler when available and a null-object otherwise. Heaviest; only if a tag can't be
   attached to payment-base's definition for some reason.

> **Caveat on "standalone activation":** opalreturns hard-depends on payment-base for
> its refund-routing brain regardless — "activate without payment-base" is graceful
> degradation, not a supported feature. Prong B buys a clean error path / clean CI;
> Prong A is the actual unblock.

## 5. Recommendation / next action

- Implement **Prong B option 1** (compiler pass) on `opalreturns@b-7.4.x-agnosticism` —
  it's behavior-preserving when payment-base is present and removes the fatal when it isn't.
  Write a characterization test first (container compiles + listener tag attached when
  the class exists; compiles cleanly when it doesn't).
- Coordinate **Prong A**: get STRP-135's handler/interface onto canonical
  `payment-base@b-7.4.x`. Until then, pin opalreturns CI's payment-base to the branch
  that carries the class, or the merge gate stays red.
- Once both land: verification run on a clean checkout (`composer update
  --with-all-dependencies` → `make cache-clear` → `oe:module:activate opalreturns`),
  confirm shop HTTP 200 and a real refund routes through the broker, then merge.

## 6. Verification log (this session)

- Confirmed `opalreturns@b-7.4.x-agnosticism` `services.yaml:245` declares the hard FQCN.
- Confirmed `RefundIntentHandler` + `RefundIntentEventInterface` **present** on local
  payment-base `b-7.4.x-math-STRP-157`, with matching ctor arg names and `services.yaml:94`
  registration.
- Confirmed `__invoke()` runtime null-guards the optional deps — runtime is safe; the
  failure is compile-time only.
- No changes made to the dev setup; this report is documentation only.

## 7. Resolution (2026-06-16, same day)

Both prongs are now satisfied — the merge gate is green.

**Prong A — DONE.** `RefundIntentHandler` + `RefundIntentEventInterface` are on
**canonical `origin/b-7.4.x` @ `7bc2ef9`** ("STRP-157 Per-line summation in CO-session"),
verified by `git ls-tree -r origin/b-7.4.x`. No longer parked on `b-7.4.x-math-STRP-157`.
A clean `composer update` against canonical payment-base now ships the class, so
opalreturns' factory resolves the **real** handler. No composer constraint change —
payment-base stays `suggest`.

**Prong B — DONE, but NOT via the compiler pass recommended in §5.** Sprint 126
analysis overturned that recommendation: OXID 7.4 modules have no reliable
compiler-pass hook, and OXID auto-imports only the root `services.yaml` (no native
`class_exists` guard). The implemented mechanism is the **factory service** (§4
option 3 / colleague's option 2): `opalreturns.refund_intent_handler` now resolves
through `RefundIntentListenerFactory::create()`, returning the real handler when
`class_exists(RefundIntentHandler::class)` else a no-op `NullRefundIntentListener`.
Two OXID-7.4 compile-pass requirements surfaced and were handled: `class:` set to the
**factory's own** class (never the payment-base FQCN) for `CheckDefinitionValidityPass`,
and `method: '__invoke'` on the listener tag for `RegisterListenersPass`. The factory
treats *absent* and *too-old* payment-base identically → graceful manual-refund fallback.

`ReturnRefundRequestedEvent`'s hard `implements RefundIntentEventInterface` was assessed
as **path-safe (C-skip)**: it is only constructed in `PaymentBaseResolutionHandler::resolve()`,
reachable only when the `@?` contract repo is non-null, so it never autoloads without
payment-base. A guard test pins that invariant.

Implemented in **Sprint 126** (`../sprints/sprint-126-…`), opalreturns
`b-7.4.x-agnosticism`, commits `7adeb87 → 8478dc0 → 9ba9f50 → 91947b8`. Unit 297→308
(+11, green), PHPStan max 0, PHPCS 0. Activation proven HTTP 200 with payment-base
present; absent-state proven at container-compile level.

### End-to-end verification run (2026-06-16, canonical payment-base present)

Ran against the live dev shop with payment-base path-symlinked into vendor (working
tree carries `RefundIntentHandler`). Sequence + evidence:

1. `oe:cache:clear` → "Cleared cache files".
2. `oe:module:deactivate opalreturns` → deactivated (clean slate).
3. `oe:module:activate opalreturns` → **"Module was activated", exit 0** — the exact
   step that previously fataled. **No fatal.**
4. `curl http://localhost.local/` → **HTTP 200**.
5. Booted-container introspection:
   - `opalreturns.refund_intent_handler` resolves to
     `OxidEsales\PaymentBase\EventSystem\Handler\RefundIntentHandler` — the **REAL**
     handler (factory chose it, not the null-listener), `is_callable` = YES.
   - `class_exists(RefundIntentHandler::class)` = YES (canonical payment-base on autoload).
6. Compiled container (`var/cache/container/container_cache_shop_1.php`, the file the
   HTTP-200 shop runs) registers the listener in `getEventDispatcherInterface2Service`:
   ```
   $instance->addListener('Opal\OpalReturns\Domain\Event\ReturnRefundRequestedEvent',
     [closure → opalreturns.refund_intent_handler (factory: RefundIntentListenerFactory)], '__invoke', 0);
   ```
   It sits in the **same dispatcher-builder method as all 5 pre-existing opalreturns
   listeners** (ReturnInspected, PaymentRefunded, ReturnApproved, ReturnRequested,
   ReturnResolved) → peer-identical wiring. (A throwaway CLI probe of
   `EventDispatcherInterface` showed 0 listeners — a CLI artifact: OXID attaches
   listeners to the `…Interface2` instance, not the one that bare id resolves to.)

**Note on `composer update`:** deliberately skipped. payment-base is a composer
**path-symlink** repo, so `composer update` never advances its git state (it's a no-op
for the dependency under test) and risks the known unified-namespace-generator
local-CI quirk. The symlinked working tree already carries canonical `b-7.4.x` @ `7bc2ef9`,
which is what the shop autoloads — so the run reflects the real merged state.

**Verdict: both prongs verified end-to-end. `b-7.4.x-agnosticism` is mergeable.**
