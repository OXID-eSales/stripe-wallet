# payment-base CI integration failures — 2026-05-21

**Failing job:** [Development / integration_tests (8.3, 8.0)](https://github.com/OXID-eSales/payment-base/actions/runs/26159705351/job/76948493649)
**Branch:** `b-7.4.x`
**Run SHA:** `085362b` (HEAD of `b-7.4.x`)

## Summary

4 of 76 integration tests fail in the `payment-base` Development workflow. All four are in `OxidEsales\PaymentBase\Tests\Integration\Contract\ContractCaptureRefundTest`. The root cause is a **stale hydration path in `DoctrineContractRepository`** after the Sprint STRP-135 refactor that moved four contract fields into a `CaptureRefundTracker` collaborator. Persistence still writes the values to the DB; load silently drops them.

## The failures

```
1) ContractCaptureRefundTest::contractStoresCapturedAmount
   Failed asserting that null matches expected 99.99.        (line 85)

2) ContractCaptureRefundTest::contractStoresRefundedAmount
   Failed asserting that null matches expected 25.0.         (line 108)

3) ContractCaptureRefundTest::multipleRefundsAccumulate
   Failed asserting that null matches expected 50.0.         (line 134)

4) ContractCaptureRefundTest::partialRefundDoesNotExceedCaptured
   Failed asserting that 0 matches expected 60.0.            (line 180)
```

All four exercise the same round-trip: `setCapturedAmount()` / `addRefundedAmount()` → `save()` → `findById()` → assert getter. The fifth test, `contractWithNullAmountsLoadsCorrectly`, passes because it never sets a value.

## Root cause

Commit `e10340b` "STRP-135 Refund desision logic transfered to PaymentBase" (May 19) refactored `PaymentContract`:

```diff
- private ?float $capturedAmount = null;
- private ?float $refundedAmount = null;
- private ?DateTimeInterface $capturedAt = null;
- private ?DateTimeInterface $refundedAt = null;
+ private CaptureRefundTracker $refundTracking;
```

`toArray()` was updated to spread the tracker (`...$this->refundTracking->toArray()`), so **save still works** — `prepareContractData()` reads the array and writes `OXCAPTUREDAMOUNT` / `OXREFUNDEDAMOUNT` / `OXCAPTUREDAT` / `OXREFUNDEDAT` correctly.

But `DoctrineContractRepository::setContractPrivateProperties()` was **not** touched in the refactor commit and has not been touched since. It still hydrates by reflecting onto names that no longer exist on `PaymentContract` (`src/Repository/DoctrineContractRepository.php:358-361`):

```php
$this->setPrivateProperty($contract, 'capturedAmount', $this->parseOptionalFloat($data['OXCAPTUREDAMOUNT'] ?? null));
$this->setPrivateProperty($contract, 'refundedAmount', $this->parseOptionalFloat($data['OXREFUNDEDAMOUNT'] ?? null));
$this->setPrivateProperty($contract, 'capturedAt',     $this->parseDateTime($data['OXCAPTUREDAT'] ?? null));
$this->setPrivateProperty($contract, 'refundedAt',     $this->parseDateTime($data['OXREFUNDEDAT'] ?? null));
```

`setPrivateProperty()` swallows `ReflectionException` with a `// Property doesn't exist, skip` comment (lines 386-396). The reflection lookup throws (the props now live on `CaptureRefundTracker`, not `PaymentContract`), the catch silently no-ops, and the hydrated contract has a freshly-constructed `CaptureRefundTracker` with all-null fields.

That is why test 4 fails with `0` instead of `null` — `getCapturedAmount()` and `getRefundedAmount()` return null, the test does `?? 0` for the subtraction, so `0 - 0 = 0`.

## Fix sketch

Replace the four `setPrivateProperty($contract, ...)` calls with a single hydrate of the tracker, e.g.:

```php
$tracker = CaptureRefundTracker::fromArray([
    'capturedAmount' => $this->parseOptionalFloat($data['OXCAPTUREDAMOUNT'] ?? null),
    'refundedAmount' => $this->parseOptionalFloat($data['OXREFUNDEDAMOUNT'] ?? null),
    'capturedAt'     => $data['OXCAPTUREDAT'] ?? null,
    'refundedAt'     => $data['OXREFUNDEDAT'] ?? null,
]);
$this->setPrivateProperty($contract, 'refundTracking', $tracker);
```

(Match the array keys to whatever `CaptureRefundTracker::fromArray()` actually expects — confirm before implementing. The same shape is already used in `PaymentContract::fromArray()`: `$contract->refundTracking = CaptureRefundTracker::fromArray($data);`.)

Bonus: the swallowed `ReflectionException` is what made this silent. Consider making `setPrivateProperty()` at least log when a property is missing — silent skip in a hydration path is a latent bug factory.

## Why local pre-commit passed

```
$ cd ~/osc/strpwt7-nov26/source/extensions/stripe
$ ./bin/pre-commit-check.sh --full
... ✓ PHPUnit tests passed ...
✓ ALL CHECKS PASSED
```

Local pre-commit runs from **`extensions/stripe`**, not from **`extensions/payment-base`**. Two different test suites:

| | Local pre-commit (`extensions/stripe`) | CI (`OXID-eSales/payment-base`) |
|---|---|---|
| Config | `extensions/stripe/tests/phpunit.xml` | `test-module/tests/phpunit-integration.xml` |
| Test root | `extensions/stripe/tests/{Unit,Integration}` | `extensions/payment-base/tests/{Unit,Integration}` |
| Contains `ContractCaptureRefundTest` | **no** | **yes** |
| Test count | 1002 | 76 |

`ContractCaptureRefundTest.php` lives at `extensions/payment-base/tests/Integration/Contract/ContractCaptureRefundTest.php` — it's owned by payment-base and is only exercised by payment-base's `Development` workflow. Stripe's integration tests don't construct a `DoctrineContractRepository` and round-trip capture/refund amounts through it, so the broken hydration path is never hit locally.

Even though payment-base is symlinked into the Stripe dev tree as a vendor module, `phpunit -c extensions/stripe/tests/phpunit.xml` does not discover payment-base's own `tests/` directory. The CI environment, in contrast, runs `composer require oxid-esales/payment-base` against a dedicated `test-module` and points phpunit at payment-base's integration suite.

**Takeaway:** running `pre-commit-check.sh --full` inside `extensions/stripe` does not validate payment-base. To catch this class of break before pushing to payment-base, run payment-base's own test suite — either inside its repo CI locally, or add a step that invokes `vendor/bin/phpunit -c extensions/payment-base/tests/phpunit-integration.xml` (or equivalent) when touching payment-base code.

## Next steps

1. Fix `DoctrineContractRepository::setContractPrivateProperties()` to hydrate the tracker (see sketch above).
2. Add a regression test at the repository level: `save()` a contract with non-null capture/refund amounts, `findById()`, assert the values round-trip — this would have failed on `e10340b`.
3. Decide whether `setPrivateProperty()` should keep silently swallowing `ReflectionException` or surface the missing property. At minimum, log it.
4. Consider running payment-base's integration tests as part of the Stripe pre-commit when payment-base sources are present in `extensions/`, to catch contract-package regressions before they ship.
