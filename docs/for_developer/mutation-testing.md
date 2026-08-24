# Mutation testing — how to get a number you can trust

Sprint 135. Infection is a declared dev-dependency with a committed config, but
until 2026-08-24 it had never produced a usable baseline. Two things were wrong;
both are fixed here, and **both will bite again if you skip the steps below**.

## 1. The suite cannot run against the dev shop as configured

Eight modules are active locally and the `mollie → paypal` class-extension chain
aborts the bootstrap:

```
Class "OxidEsales\Payments\PayPal\Controller\PaymentController_parent" not found
  at ModuleChainsGenerator->createClassExtension(...)
```

CI activates only `oe_payment_base` and `oe_payments_stripe_wallet`. Match it:

```bash
for m in oe_payments_mollie opalreturns opalsubscription \
         oe_onepage_checkout oe_payments_paypal; do
  docker compose exec php php bin/oe-console oe:module:deactivate $m
done
```

**Snapshot `source/var/configuration` first and restore it afterwards** — this is
your shop, and you will want your modules back.

## 2. Do NOT let Infection run its own initial test suite

This is the important one. Infection's generated initial-test configuration runs
PHPUnit with a **random seed** and its run terminates early at a variable point,
so the coverage it derives is partial *and different every time*. Symptom:

| Run | Mutants | Covered Code MSI |
|---|---|---|
| 1 | 805 | 70% |
| 2 | 1019 | 66% |
| 3 | 701 | 64% |
| 4 | 235 | 73% |
| 5 | **0** | **0%** |

A single invocation returns a confident-looking percentage, so the instability is
invisible unless you run it three times. Note this is **not** a coverage-driver
problem — PHPUnit's own coverage is perfectly stable (185 files, 3,539 covered
statements, identical across runs).

**The fix: generate coverage yourself, then hand it to Infection.**

```bash
# 1. coverage from PHPUnit directly — deterministic
docker compose exec php php vendor/bin/phpunit \
  -c extensions/stripe/tests/phpunit.xml --testsuite Unit \
  --coverage-xml /tmp/cov/coverage-xml --log-junit /tmp/cov/junit.xml

# 2. mutate against it, skipping Infection's own initial run
docker compose exec -w /var/www/extensions/stripe php \
  php -d memory_limit=3G vendor/bin/infection \
  --threads=4 --no-progress --coverage=/tmp/cov --skip-initial-tests
```

Verified identical across `--threads=1`, `4` and `8`, three consecutive runs each:
**1,592 mutants · 1,123 killed · 469 escaped · Covered Code MSI 70%**.

## 3. Coverage driver

`xdebug.mode` in this image is `debug,profile` — it does **not** include
`coverage`. Install pcov and let it be the coverage driver while xdebug keeps the
debugger:

```bash
docker compose exec -u root php pecl install pcov
docker compose exec -u root php sh -c 'printf "pcov.enabled=1\npcov.directory=/var/www/extensions/stripe/src\n" \
  > /usr/local/etc/php/conf.d/zz-pcov-coverage.ini'
```

PHPUnit then reports `Runtime: PHP 8.3.33 with PCOV`. This is a **container-local
change and is lost on rebuild** — to make it permanent, add the two lines to the
SDK's PHP image.

> Note on `conf.d` ordering: files load alphabetically, so a `99-*.ini` is
> overridden by `xdebug.ini`. Use a `zz-` prefix.

## 4. Reading the result

- **`Covered Code MSI`** is the number to track: of the mutations placed in code
  the tests execute, how many the tests detect.
- **Not every escape is a bug.** Equivalent mutants cannot be killed; record them
  in `tests/MUTATION_EQUIVALENTS.md` with an argument, never with a test that
  asserts an implementation detail.
- **`MethodCallRemoval` escapes are the ones to fix first** — they mean a call can
  be deleted with the suite still green, the signature of asserting on a mock
  instead of on behaviour.
- **A per-module MSI is not a property of that module's tests**: the same handler
  code scores differently depending on which test suite is selected, because
  cross-cutting tests contribute kills.
