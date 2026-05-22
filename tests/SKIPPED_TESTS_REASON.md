# Why ~53 integration tests are reported as "Skipped"

On a fresh `docker compose exec php phpunit --testsuite Integration` the tally
typically reads:

```
Tests: 157, Assertions: 431, Skipped: 53.
```

The 53 skips come from three known, intentional sources. None of them indicate
broken code; each test calls `$this->markTestSkipped(...)` with a specific
reason when the local environment cannot satisfy its preconditions.

---

## 1. Stripe live-credential tests (~40 of the 53)

Tests extending `tests/Integration/Stripe/StripeIntegrationTestCase.php`
exercise the real Stripe PHP SDK against the real Stripe sandbox API (HTTPS
requests to `api.stripe.com`). They need a working test secret key.

**Skip trigger** (`StripeIntegrationTestCase.php:45`):

```php
if (empty($this->testSecretKey) || $this->testSecretKey === 'sk_test_your_key_here') {
    $this->markTestSkipped(
        'Stripe test credentials not configured. ' .
        'Set STRIPE_TEST_SECRET_KEY in tests/.env file. ' .
        'Get test keys from https://dashboard.stripe.com/test/apikeys'
    );
}
```

**To enable:**

```bash
cp tests/.env.dist tests/.env
# Edit tests/.env, set STRIPE_TEST_SECRET_KEY=sk_test_…
docker compose exec php phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration
```

CI does **not** run these — they would consume Stripe API quota and require
storing real credentials in CI secrets. They are useful as a manual smoke gate
when changing the Stripe API call surface.

---

## 2. OXID-shop-context tests (~10 of the 53)

Two test files need a fully-booted OXID shop (active module, configured
database, populated service container):

- `tests/Integration/Admin/PaymentPanelRegistryIntegrationTest.php` (~5 tests)
- `tests/Integration/Module/ModuleLifecycleTest.php` (~4 tests)

**Skip trigger** (e.g. `PaymentPanelRegistryIntegrationTest.php:42`):

```php
try {
    $this->container = ContainerFactory::getInstance()->getContainer();
} catch (\Throwable $e) {
    $this->markTestSkipped('OXID container could not be initialised — …');
}
```

These pass when the integration suite runs through `make` against a fully
configured shop (the same setup used by the e2e tests). The skip path fires on
bare developer environments where the shop isn't fully bootstrapped.

---

## 3. Conditional "feature-not-present" tests (1 of the 53)

These tests inspect optional sections of `metadata.php`. When the section is
absent there is no shape to validate, so the test skips. As of today there is
exactly **one** such test in the module:

| Test | File | Skip condition |
|---|---|---|
| `MetadataTest::testTemplatesDefined` | `tests/Integration/Module/MetadataTest.php:345` | `!isset($this->moduleData['templates'])` — module declares no `templates` section |

```php
if (!isset($this->moduleData['templates'])) {
    $this->markTestSkipped('Module does not define templates');
}
```

To re‑enable the test, add a `'templates' => [...]` entry to `metadata.php`.
The other `MetadataTest::*` methods (id, version, title, description,
controllers, settings, events) are required and do **not** skip — if those
sections are missing the test fails (which is the desired behaviour).

---

## Related: other skip / incomplete sources (not "feature-not-present", but visible in the report)

These don't fall into any of the three categories above, but they appear in
the test output and are worth knowing about so you can recognise them:

| Site | Reason | Class of skip |
|---|---|---|
| `tests/Unit/Stripe/Twig/DumpExtensionTest.php:102` | `'services.yaml not found'` | Test‑helper guard — fires only if the file moves; not a runtime concern |
| `tests/Unit/Stripe/Twig/DumpExtensionTest.php:119` | `'services.yaml not found'` | Same as above (second test in the same file) |
| `tests/Unit/Stripe/Model/OrderAddressValidationTest.php:39` | `markTestIncomplete` — "requires OXID framework fully initialized" | The test documents desired behaviour that isn't yet implemented; placeholder |
| `tests/Integration/Stripe/Adapter/StripeAdapterIntegrationTest.php:367` | `markTestIncomplete('Charge data not available in test mode')` | Stripe's sandbox doesn't populate `Charge::charges` reliably; assertion can't run |

`markTestIncomplete` shows up in the suite tally as `Incomplete: 1` (the
OrderAddressValidationTest case is the persistent one). The
StripeAdapterIntegrationTest one only triggers when its containing test runs
(requires Stripe credentials — see §1).

---

## What to do if you see more than 53 skips

A growing skip count is usually a regression, not a feature. Common causes:

- The OXID shop is in maintenance mode (`oxconfig.blShopStopped = 1`) and the
  container falls back to the skip path. See dev-log
  `20260522/done/sprint-110-…md` for the EE grace-period fix.
- The module's `metadata.php` was reverted to declare templates but the shape
  changed.
- A new test was added that `markTestSkipped()`s for an env reason that
  shouldn't apply locally.

Run with `--display-skipped` to see the exact reason per test:

```bash
docker compose exec php phpunit \
    -c extensions/stripe/tests/phpunit.xml \
    --testsuite Integration --display-skipped
```
