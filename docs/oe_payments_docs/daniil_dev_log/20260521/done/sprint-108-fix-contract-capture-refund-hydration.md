# Sprint 108 — Fix `DoctrineContractRepository` capture/refund hydration

**Repository:** `extensions/payment-base`
**Mode:** single atomic commit on `b-7.4.x`. One repository edit, one regression test, one diagnostic tightening. ~60 lines of production diff + ~80 lines of test diff.
**Trigger:** [payment-base CI run #26159705351 / job #76948493649](https://github.com/OXID-eSales/payment-base/actions/runs/26159705351/job/76948493649) — 4 of 76 integration tests fail with `Failed asserting that null matches expected 99.99` etc. Root cause is documented in `reports/01-payment-base-integration-failures.md`.
**Wait for approval before implementing.**

## 1. Why

After commit `e10340b` (STRP-135 "Refund desision logic transfered to PaymentBase"), `PaymentContract` no longer owns `capturedAmount` / `refundedAmount` / `capturedAt` / `refundedAt` as direct properties. They live on a `CaptureRefundTracker` collaborator.

`DoctrineContractRepository::setContractPrivateProperties()` (`src/Repository/DoctrineContractRepository.php:358-361`) still hydrates the *old* property names via reflection:

```php
$this->setPrivateProperty($contract, 'capturedAmount', ...);
$this->setPrivateProperty($contract, 'refundedAmount', ...);
$this->setPrivateProperty($contract, 'capturedAt',     ...);
$this->setPrivateProperty($contract, 'refundedAt',     ...);
```

`setPrivateProperty()` (lines 386-396) catches `ReflectionException` and silently no-ops (`// Property doesn't exist, skip`). So `findById()` returns a contract whose tracker is freshly-constructed with all-null fields, regardless of what's in the DB. Persistence (`toArray()` → `prepareContractData()`) still writes the four `OX...` columns correctly, so the breakage is asymmetric and only visible on a save → load round-trip.

This sprint fixes the hydration path, removes the silent failure mode, and adds a repository-level regression test that would have caught the break at `e10340b`.

## 2. Goals

- **G1.** All 4 currently-failing integration tests pass:
  - `ContractCaptureRefundTest::contractStoresCapturedAmount`
  - `ContractCaptureRefundTest::contractStoresRefundedAmount`
  - `ContractCaptureRefundTest::multipleRefundsAccumulate`
  - `ContractCaptureRefundTest::partialRefundDoesNotExceedCaptured`
- **G2.** Three new integration tests on `DoctrineContractRepository`, each written first and proven red against current code:
  1. **End-to-end round-trip** (`capturedAndRefundedAmountsRoundTrip`) — `save()` → `findById()` through the public `PaymentContract` API. Catches *any* regression in the load/save chain, but cannot localize which side broke.
  2. **Hydration in isolation** (`hydrationLoadsCaptureRefundColumns`) — raw-SQL insert into `oe_payments_contract`, then `findById()`. Bypasses save entirely; fails iff hydration drops the columns. This is the test that will be RED at HEAD today.
  3. **Persistence in isolation** (`persistenceWritesCaptureRefundColumns`) — `save()` a contract with non-null values, then raw-SQL `SELECT` of the four `OX...` columns. Bypasses hydration entirely; should already be GREEN at HEAD (we expect save to be correct), and stays green after the fix.

  Together, the pair (2) + (3) localizes any future regression to one specific side of the boundary; (1) is the integration safety net that mirrors how `ContractCaptureRefundTest` exercises the code.
- **G3.** `setPrivateProperty()` no longer silently swallows missing-property errors. Either it rethrows on `ReflectionException` for hydration callers, or it logs at warning level with the property name and class. (Decision: see §5.)
- **G4.** No new responsibilities on `DoctrineContractRepository` — keep it the persistence boundary. Tracker hydration goes through `CaptureRefundTracker::fromArray()` (already exists), not by reaching into private fields of the tracker.
- **G5.** No change to `PaymentContract`'s public surface. No change to the database schema. No change to `prepareContractData()` (save side already works).
- **G6.** payment-base CI `Development` workflow green on `b-7.4.x` (76/76 integration tests, 0 failures).
- **G7.** payment-base local `composer phpcs / phpstan / phpmd` clean. Stripe `./bin/pre-commit-check.sh --full` still green (no Stripe-side change is intended).

## 3. Scope inventory

| File | Change |
|---|---|
| `extensions/payment-base/src/Repository/DoctrineContractRepository.php` | Replace the 4 broken `setPrivateProperty($contract, 'capturedAmount'/...)` calls with one call that builds `CaptureRefundTracker::fromArray([...])` from `OXCAPTUREDAMOUNT`/`OXREFUNDEDAMOUNT`/`OXCAPTUREDAT`/`OXREFUNDEDAT`, then assigns it to the `refundTracking` property (single reflection set, same pattern as `PaymentContract::fromArray()` uses). Extract into a small private helper `hydrateRefundTracking(array $data): CaptureRefundTracker` to keep `setContractPrivateProperties()` short. |
| `extensions/payment-base/src/Repository/DoctrineContractRepository.php` (same file) | Tighten `setPrivateProperty()`: either drop the silent catch and let `ReflectionException` propagate (cleaner — bad property name is a programmer error and should fail loudly), or log via the existing logger if one is injected. Pick one in §5. |
| `extensions/payment-base/tests/Integration/Repository/DoctrineContractRepositoryTest.php` | Add `capturedAndRefundedAmountsRoundTrip()` and `capturedAndRefundedTimestampsRoundTrip()` integration tests. These exercise *the repository directly* (not via `ContractCaptureRefundTest` which is contract-level), so the regression is visible at the layer where the bug actually lives. |
| `extensions/payment-base/tests/Unit/Repository/` (if a unit suite exists for the repo) | If the repo can be unit-tested with a fake `Connection`, add a unit test that asserts the hydrated contract carries the four values. Skip if the repo has no unit coverage today — integration is enough. |

### Explicitly *not* touched

- `PaymentContract` class — public surface stays identical.
- `CaptureRefundTracker` — already correct, has working `fromArray()`/`toArray()`.
- `prepareContractData()` and the save path — already correct, proven by the existing `contractWithNullAmountsLoadsCorrectly` test passing.
- The DB schema and migrations (`Version20251031140000.php`).
- The Stripe module. No symbol it consumes is changing.

## 4. Implementation plan — TDD-first, step by step

### Step 1 — Red

Add three integration tests in `tests/Integration/Repository/DoctrineContractRepositoryTest.php`. All three are written before any production-code change. The hydration test must be RED at HEAD; the persistence test should be GREEN at HEAD (we expect save to already be correct, but we lock that down). The round-trip test will be RED at HEAD for the same reason hydration is.

**Test 1 — end-to-end round-trip (integration safety net):**

```php
/**
 * @test
 * @group regression
 * @group sprint-108
 */
public function capturedAndRefundedAmountsRoundTrip(): void
{
    // Arrange: a committed contract with non-null capture + refund set via the
    // public PaymentContract API.
    $contract = $this->createCommittedTestContract();
    $contract->setCapturedAmount(81.50);
    $contract->setCapturedAt(new \DateTimeImmutable('2026-05-20 10:00:00'));
    $contract->addRefundedAmount(11.25);
    $contract->setRefundedAt(new \DateTimeImmutable('2026-05-20 11:00:00'));

    // Act: persist, then load via the same repository (no in-memory cache;
    // findById issues a fresh SELECT). This is a true DB round-trip.
    $this->repository->save($contract);
    $loaded = $this->repository->findById($contract->getId());

    // Assert: every field survives the round-trip.
    $this->assertNotNull($loaded);
    $this->assertEqualsWithDelta(81.50, $loaded->getCapturedAmount(), 0.001);
    $this->assertEqualsWithDelta(11.25, $loaded->getRefundedAmount(), 0.001);
    $this->assertSame('2026-05-20 10:00:00', $loaded->getCapturedAt()?->format('Y-m-d H:i:s'));
    $this->assertSame('2026-05-20 11:00:00', $loaded->getRefundedAt()?->format('Y-m-d H:i:s'));
}
```

**Note on this test's diagnostic power:** if it fails alone, you cannot tell whether save or load is broken — that's what tests 2 and 3 are for. Test 1 is the safety net that mirrors how `ContractCaptureRefundTest` exercises the code; tests 2 and 3 are the diagnostic probes.

**Test 2 — hydration in isolation (bypass save):**

```php
/**
 * @test
 * @group regression
 * @group sprint-108
 *
 * Inserts a row directly via the DBAL Connection, then loads through the
 * repository. If hydration ever drops the four capture/refund columns again
 * (e.g. someone refactors PaymentContract's internals without updating the
 * repository), this test fails in isolation — the save path is not exercised.
 */
public function hydrationLoadsCaptureRefundColumns(): void
{
    // Arrange: write a contract row directly to the DB. Use the minimum
    // schema-required fields plus the four under test. Skip the repository
    // entirely on this side so the save path cannot mask a hydration bug.
    $id = 'sprint108_hydr_' . substr(uniqid(), -6);
    $this->connection->insert('oe_payments_contract', [
        'OXID'              => $id,
        'OXSHOPID'          => 1,
        'OXUSERID'          => 'oxdefaultadmin',
        'OXSTATE'           => 'fulfilled',
        'OXBASKETDATA'      => json_encode(['items' => [], 'totalGross' => 100.0]),
        'OXCONDITIONS'      => json_encode([]),
        'OXCREATED'         => '2026-05-20 09:00:00',
        'OXUPDATED'         => '2026-05-20 09:00:00',
        'OXCAPTUREDAMOUNT'  => 81.50,
        'OXREFUNDEDAMOUNT'  => 11.25,
        'OXCAPTUREDAT'      => '2026-05-20 10:00:00',
        'OXREFUNDEDAT'      => '2026-05-20 11:00:00',
    ]);

    // Act
    $loaded = $this->repository->findById($id);

    // Assert: hydration carried the four values through to the tracker.
    $this->assertNotNull($loaded);
    $this->assertEqualsWithDelta(81.50, $loaded->getCapturedAmount(), 0.001);
    $this->assertEqualsWithDelta(11.25, $loaded->getRefundedAmount(), 0.001);
    $this->assertSame('2026-05-20 10:00:00', $loaded->getCapturedAt()?->format('Y-m-d H:i:s'));
    $this->assertSame('2026-05-20 11:00:00', $loaded->getRefundedAt()?->format('Y-m-d H:i:s'));
}
```

**Test 3 — persistence in isolation (bypass hydration):**

```php
/**
 * @test
 * @group regression
 * @group sprint-108
 *
 * Saves a contract via the repository, then reads the four target columns
 * back with raw SQL. If save ever drops a column (e.g. prepareContractData()
 * omits a field after a refactor), this test fails in isolation — the
 * hydration path is not exercised.
 */
public function persistenceWritesCaptureRefundColumns(): void
{
    // Arrange
    $contract = $this->createCommittedTestContract();
    $contract->setCapturedAmount(81.50);
    $contract->setCapturedAt(new \DateTimeImmutable('2026-05-20 10:00:00'));
    $contract->addRefundedAmount(11.25);
    $contract->setRefundedAt(new \DateTimeImmutable('2026-05-20 11:00:00'));

    // Act
    $this->repository->save($contract);

    // Assert: query the four OX... columns directly, do not go through findById.
    $row = $this->connection->fetchAssociative(
        'SELECT OXCAPTUREDAMOUNT, OXREFUNDEDAMOUNT, OXCAPTUREDAT, OXREFUNDEDAT
         FROM oe_payments_contract WHERE OXID = :id',
        ['id' => $contract->getId()]
    );
    $this->assertNotFalse($row);
    $this->assertEqualsWithDelta(81.50, (float) $row['OXCAPTUREDAMOUNT'], 0.001);
    $this->assertEqualsWithDelta(11.25, (float) $row['OXREFUNDEDAMOUNT'], 0.001);
    $this->assertSame('2026-05-20 10:00:00', $row['OXCAPTUREDAT']);
    $this->assertSame('2026-05-20 11:00:00', $row['OXREFUNDEDAT']);
}
```

**Expected state at HEAD before any production fix:**

| Test | Status at HEAD | What it tells us |
|---|---|---|
| `capturedAndRefundedAmountsRoundTrip` | RED | "Something in save+load is broken" — coarse signal |
| `hydrationLoadsCaptureRefundColumns` | RED | "Hydration drops the columns" — pinpoints the bug |
| `persistenceWritesCaptureRefundColumns` | GREEN | "Save side is correct" — falsifies an alternative hypothesis |

If `persistenceWritesCaptureRefundColumns` is RED at HEAD instead of GREEN, the diagnosis in `reports/01-payment-base-integration-failures.md` is wrong and I will stop and re-investigate before changing anything. **Commit nothing until all three statuses are observed and they match this table.**

### Step 2 — Green (minimal)

In `DoctrineContractRepository`:

```php
// In setContractPrivateProperties(), replace lines 358-361 with:
$this->setPrivateProperty($contract, 'refundTracking', $this->hydrateRefundTracking($data));
```

Add:

```php
/**
 * @param array<string, mixed> $data
 */
private function hydrateRefundTracking(array $data): CaptureRefundTracker
{
    return CaptureRefundTracker::fromArray([
        'capturedAmount' => $data['OXCAPTUREDAMOUNT'] ?? null,
        'refundedAmount' => $data['OXREFUNDEDAMOUNT'] ?? null,
        'capturedAt'     => $data['OXCAPTUREDAT'] ?? null,
        'refundedAt'     => $data['OXREFUNDEDAT'] ?? null,
    ]);
}
```

`CaptureRefundTracker::fromArray()` already handles `int|float|string|null` for the amounts and `string|null` for timestamps, so `parseOptionalFloat()` and `parseDateTime()` are not needed here — DRY across the two paths.

Run the regression test → green. Run the four `ContractCaptureRefundTest` cases → green.

### Step 3 — Refactor (SOLID + DRY)

- Confirm `setContractPrivateProperties()` is still ≤ 25 lines after the substitution. If not, extract another helper.
- Confirm there is no duplicated parse logic between `hydrateRefundTracking()` and `CaptureRefundTracker::fromArray()`. (There should not be — the repo passes raw DB values straight through, the tracker parses.)
- Verify `parseOptionalFloat()` is still used elsewhere; if it was only used by the 4 removed lines, delete it (YAGNI).

### Step 4 — Diagnostic tightening (G3)

See §5 for the open decision. Whichever option is picked, add a unit test that pins it:
- If "rethrow": `setPrivateProperty('bogusName')` throws.
- If "log and continue": `setPrivateProperty('bogusName')` writes a warning to the captured logger, contract is unchanged.

### Step 5 — Final verification

- `composer phpcs` clean.
- `composer phpstan` clean (level max).
- `composer phpmd` clean (no new violations vs. baseline).
- `vendor/bin/phpunit -c tests/phpunit-integration.xml` → 76/76 pass, 0 failures.
- `vendor/bin/phpunit -c tests/phpunit.xml` (unit) — full green.

## 5. Open decision — silent-catch behavior in `setPrivateProperty()`

The current catch (`// Property doesn't exist, skip`) is what hid the bug for two days. Two paths forward, **pick one before Step 4**:

| Option | Pro | Con |
|---|---|---|
| **A. Rethrow `ReflectionException`** (let callers handle) | Loudest, future renames break immediately and visibly. SOLID: hydration is not the place to absorb programmer errors. | Any other caller that depends on the silent-skip semantics breaks. Survey the codebase for other call sites first. |
| **B. Log and continue** | Backwards-compatible; misuse becomes visible in logs without breaking activation. | Logs are easy to miss in CI. Doesn't fail tests. Needs a logger injected into the repo (current ctor has only `Connection`). |

My lean: **Option A**, scoped only to `setPrivateProperty()` callers inside this repository — all current call sites are setting known fields, so rethrowing only catches genuine bugs. Confirm by grepping for `setPrivateProperty(` in payment-base before deciding.

I want approval on this before writing the Step 4 test.

## 6. Risk register

- **R1 — Existing baseline drift.** PHPMD baseline may list `DoctrineContractRepository` complexity entries; refactor must not raise them. Re-run `--generate-baseline` only if explicitly needed (per project rule "don't raise PHPMD thresholds to hide problems").
- **R2 — Other reflection sets affected.** The same `setContractPrivateProperties()` reflects onto other names (`state`, `orderId`, `provider`, `providerOrderId`, `expiresAt`, `createdAt`, `updatedAt`, `committedAt`, `fulfilledAt`, `conditions`, `metadata`). All of those still exist on `PaymentContract` today — verified at `src/Contract/PaymentContract.php` — but Option A above will fail-fast if any of them is renamed in the future, which is the desired behavior.
- **R3 — Stripe consumers.** The Stripe module reads `getCapturedAmount()` / `getRefundedAmount()` in admin display + refund eligibility logic. After this fix, contracts loaded from DB will correctly report non-null amounts where today they spuriously report null. Behavior change is in the *correct* direction, but: re-run Stripe `./bin/pre-commit-check.sh --full` to confirm no test assumed the wrong-null behavior.
- **R4 — Migration not run on a target environment.** Out of scope. The CI workflow runs `composer require oxid-esales/payment-base` which kicks off migrations; the failing test `contractWithNullAmountsLoadsCorrectly` already proves the four `OX...` columns exist in CI's MySQL.

## 7. Acceptance checklist

- [ ] Three regression tests added **first** (see Step 1): round-trip RED, hydration-isolation RED, persistence-isolation GREEN — observed in that exact pattern against current code before any production change. Committed in the same atomic commit as the fix; after the fix all three are GREEN.
- [ ] `DoctrineContractRepository::setContractPrivateProperties()` hydrates `refundTracking` via `CaptureRefundTracker::fromArray()`.
- [ ] `setPrivateProperty()` no longer silently swallows missing-property errors (decision per §5).
- [ ] All 4 `ContractCaptureRefundTest` cases pass.
- [ ] payment-base CI green: 76/76 integration tests, unit suite green, phpcs/phpstan/phpmd clean.
- [ ] Stripe `./bin/pre-commit-check.sh --full` still green.
- [ ] Completion report written to `extensions/payment-base` dev-log or filed under `20260521/done/sprint-108-completion-report.md` (whichever convention the team prefers — flag in PR description).

## 8. Out of scope (explicitly deferred)

- Adding a payment-base integration step to Stripe's pre-commit hook. (Discussed in `reports/01-payment-base-integration-failures.md` as a follow-up; not part of this sprint.)
- Rewriting `setPrivateProperty()` as a typed value-object hydrator. The current reflection approach is acceptable; only the silent-catch is the bug.
- Migrating `PaymentContract::fromArray()` and `DoctrineContractRepository::hydrateContract()` to share a single hydration strategy. Worth doing eventually, but a separate sprint — keep the diff here minimal and focused on the regression.

---

**Status:** ⏸ awaiting approval. Will not start Step 1 until approved.
