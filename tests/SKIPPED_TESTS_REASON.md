# Integration test suites and gating

Sprint 114.13 (T6) reorganised the integration suite to be honest about its
coverage. The former behaviour — silently skipping ~53 tests so the suite
reported green while most of the live-Stripe layer didn't execute — is replaced
by **explicit gating via PHPUnit suite names**.

---

## Default suite: `Integration` (~87 tests)

Run:
```bash
docker compose exec php phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration
```

Contains all integration tests **except**:
- live-Stripe adapter tests (the `requires-stripe-creds` group)
- OXID-container lifecycle tests (the `requires-oxid-container` group)

These are always runnable inside the Docker dev environment with no extra setup.

---

## Live-Stripe suite: `Integration-live-stripe` (~47 tests)

Run:
```bash
# 1. Set STRIPE_TEST_SECRET_KEY in tests/.env
cp tests/.env.dist tests/.env
# Edit tests/.env: STRIPE_TEST_SECRET_KEY=sk_test_...

# 2. Run
docker compose exec php phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration-live-stripe
```

Contains tests in `tests/Integration/Stripe/Adapter/` (all marked
`@group requires-stripe-creds`):

| File | Tests |
|---|---|
| `StripeAdapterIntegrationTest.php` | ~14 |
| `Stripe3DSecureIntegrationTest.php` | ~11 |
| `StripeAuthorizationFlowIntegrationTest.php` | ~11 |
| `StripePaymentMethodIntegrationTest.php` | ~11 |

The base class `StripeIntegrationTestCase` still calls `markTestSkipped`
when credentials are absent — so running this suite without a key still
skips cleanly. The `@group` gate means the default suite no longer counts
those as silently-skipped tests.

---

## OXID-container suite: `Integration-with-container` (~6 tests)

Run **inside Docker after** activating both modules:
```bash
bin/oe-console oe:module:activate oe_payments_stripe_wallet
docker compose exec php phpunit -c extensions/stripe/tests/phpunit.xml --testsuite Integration-with-container
```

Contains tests marked `@group requires-oxid-container`:
- `tests/Integration/Admin/PaymentPanelRegistryIntegrationTest.php`
- `tests/Integration/Module/ModuleLifecycleTest.php`

**Container-boot failures in this suite are hard errors (not skips).**
The `markTestSkipped` call has been removed from both classes — if
`ContainerFactory::getInstance()->getContainer()` throws, the test fails
with an ERROR, which is the correct outcome when the container should be
available.

---

## Remaining skips in the default suite

A small number of tests in the default `Integration` suite still call
`markTestSkipped` for legitimate reasons:

| Test | Reason | Action |
|---|---|---|
| `MetadataTest::testTemplatesDefined` | Module declares no `templates` section | Expected; add `templates` to metadata.php to enable |
| `StripeAdapterIntegrationTest::*` | Stripe credentials absent (redirected to live-stripe suite) | No longer in default suite |
| `tests/Unit/Stripe/Twig/DumpExtensionTest.php:102,119` | `services.yaml not found` | Unit-test guard; fires only if file moves |
| `tests/Unit/Stripe/Model/OrderAddressValidationTest.php:39` | `markTestIncomplete` — requires full OXID bootstrap | Placeholder; tracked in backlog |
| `StripeAdapterIntegrationTest::testCharge*` | `markTestIncomplete` — sandbox data unavailable | Only in live-stripe suite anyway |

---

## Before/after gating summary (T6)

| | Before (Sprint 114.12) | After (Sprint 114.13) |
|---|---|---|
| Default `Integration` suite tests | ~157 | ~87 |
| Silently skipped in default suite | ~53 | ~1 (`MetadataTest`) |
| `Integration-live-stripe` suite | (included in default, skipped) | ~47 explicit |
| `Integration-with-container` suite | (included in default, skipped) | ~6 explicit, hard-fail |
| Total creds-required tests made honest | 0 | 47 |
