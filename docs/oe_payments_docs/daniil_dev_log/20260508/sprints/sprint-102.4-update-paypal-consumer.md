# Sub-sprint 102.4 — Update `paypal` consumer code

**Parent:** [`sprint-102-rename-payment-component-to-payment-base.md`](sprint-102-rename-payment-component-to-payment-base.md)
**Depends on:** [102.1](sprint-102.1-rename-payment-component-internals.md).
**Independent of:** 102.2 / 102.3 (parallel-safe).
**Repo:** `extensions/paypal`.
**Mode:** sed sweep + phpunit gate.

## 1. Why

Symmetric to 102.3, but for paypal. paypal owns 100 mixed-extension
files referencing the old namespace per the 2026-05-08 survey.

## 2. Goals

- **G1** — Every PHP `use` statement and every FQCN inside `src/`
  and `tests/` swaps `OxidEsales\PaymentComponent\` →
  `OxidEsales\PaymentBase\`.
- **G2** — `services.yaml` rewritten end-to-end.
- **G3** — `metadata.php` rewritten.
- **G4** — Phpstan / phpmd / phpcs configs rechecked.
- **G5** — Twig templates referencing `@oe_payment_component`
  (if any) updated.
- **G6** — phpunit Unit + Integration green at ≥ pre-rename
  baseline.

Out of scope:
- composer.json `require` line — already patched in 102.1.
- composer.json `repositories[].url` — patched in 102.5.
- Renaming the PayPal module itself (`oxid-esales/paypal-payment`
  stays; PayPal SDK versioning per memory feedback also stays).

## 3. Pre-flight inventory

```bash
cd source/extensions/paypal
grep -rln 'OxidEsales\\PaymentComponent' \
  --include='*.php' --include='*.yaml' --include='*.yml' \
  --include='*.json' --include='*.neon' --include='*.xml' \
  --exclude-dir=docs --exclude-dir=vendor --exclude-dir=node_modules \
  . | wc -l
# Expected: 100 (per status.md scope survey).
```

Pre-rename baseline (will be **red** post-102.1):

```bash
docker compose exec -w /var/www/extensions/paypal -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit | tail -3
```

## 4. Steps

### 4.1 Step 1 — Replace the namespace in PHP

```bash
cd source/extensions/paypal
git ls-files '*.php' \
  | xargs sed -i 's@OxidEsales\\PaymentComponent\\@OxidEsales\\PaymentBase\\@g'
```

Verify zero residue:

```bash
grep -rn 'OxidEsales\\PaymentComponent' --include='*.php' src tests metadata.php
```

### 4.2 Step 2 — Replace the namespace in YAML / NEON / XML / JSON

```bash
git ls-files '*.yaml' '*.yml' '*.neon' '*.xml' '*.json' \
  | xargs sed -i 's@OxidEsales\\PaymentComponent\\@OxidEsales\\PaymentBase\\@g'
```

### 4.3 Step 3 — Twig templates

```bash
grep -rn '@oe_payment_component' views/ src/ 2>/dev/null
```

Sed-replace if hits.

### 4.4 Step 4 — Phpstan / phpmd / phpcs configs

```bash
grep -n 'OxidEsales\\PaymentComponent\|payment-component\|oe_payment_component' \
  phpstan.neon tests/PhpStan/*.neon tests/PhpMd/*.xml tests/phpcs.xml 2>/dev/null
```

Rewrite namespace literals; leave file paths alone (102.5 handles
those).

### 4.5 Step 5 — Run the suite

```bash
docker compose exec -w /var/www/extensions/paypal -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose exec -w /var/www/extensions/paypal -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Integration
```

### 4.6 Step 6 — Style + static checks

```bash
cd source/extensions/paypal
composer phpcs && composer phpstan && composer phpmd
```

## 5. Walking order (TDD)

Same shape as 102.2 / 102.3:

1. Step 1, then re-run Unit.
2. Step 2, then re-run Integration.
3. Step 3 if needed.
4. Step 4, regenerate phpstan baseline if necessary.
5. Step 5/6 full green.
6. Commit.

## 6. Acceptance criteria

- [ ] No `OxidEsales\PaymentComponent` reference under
      `extensions/paypal/{src,tests,services.yaml,metadata.php,views}`.
- [ ] No `@oe_payment_component` alias inside paypal.
- [ ] phpunit Unit + Integration green; counts ≥ pre-rename
      baseline.
- [ ] phpcs / phpstan / phpmd zero new errors.
- [ ] composer.json `require` block unchanged from end-of-102.1
      (already says `oxid-esales/payment-base`).

## 7. Done definition

- [ ] §6 acceptance.
- [ ] Sub-sprint moved to `done/sprint-102.4-…md` with completion
      report.
- [ ] Status row updated.

## 8. Risk

- **PayPal SDK confusion** — per memory `feedback_paypal_sdk_versioning`,
  do not touch PayPal SDK versions in this sub-sprint. The rename
  is namespace-only; require lines stay at `paypal/paypal-server-sdk
  ^2`.
- **Sed missed translator class names** — Stripe / PayPal both
  expose translator classes that the broker dispatches by FQCN
  string. Verified by step 5's integration tests.
