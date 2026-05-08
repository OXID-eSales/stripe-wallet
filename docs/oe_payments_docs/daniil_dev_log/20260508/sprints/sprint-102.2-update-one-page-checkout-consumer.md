# Sub-sprint 102.2 — Update `one-page-checkout` consumer code

**Parent:** [`sprint-102-rename-payment-component-to-payment-base.md`](sprint-102-rename-payment-component-to-payment-base.md)
**Depends on:** [102.1](sprint-102.1-rename-payment-component-internals.md)
(must be landed and green).
**Repo:** `extensions/one-page-checkout`.
**Mode:** sed sweep + phpunit gate.

## 1. Why

After 102.1, `OxidEsales\PaymentComponent\` is gone from
payment-base; its replacement is `OxidEsales\PaymentBase\`. This
sub-sprint flips one-page-checkout from the old import path to the
new one. Every test in `one-page-checkout/tests/` that touches a
contract / repository / event class is currently red on
`Class "OxidEsales\PaymentComponent\…" not found`.

## 2. Goals

- **G1** — Every PHP `use` statement in `one-page-checkout/src/`
  and `tests/` matching `OxidEsales\PaymentComponent\…` is
  rewritten to `OxidEsales\PaymentBase\…`.
- **G2** — `services.yaml` FQCN service IDs and aliases are
  rewritten the same way.
- **G3** — `metadata.php` `use` statements (if any) rewritten.
- **G4** — Phpstan / phpcs / phpmd configs in
  `one-page-checkout/` re-checked for namespace references and
  rewritten.
- **G5** — phpunit Unit + Integration ≥ pre-rename baseline.

Out of scope:
- composer.json `require` line — already patched in 102.1.
- composer.json `repositories[].url` — patched in 102.5.
- Any feature change. The sub-sprint is rename-only.

## 3. Pre-flight inventory

Inside `extensions/one-page-checkout/`:

```bash
grep -rln 'OxidEsales\\PaymentComponent' \
  --include='*.php' --include='*.yaml' --include='*.yml' \
  --include='*.json' --include='*.neon' --include='*.xml' \
  src tests services.yaml metadata.php composer.json 2>/dev/null
```

Capture the file list. Per the 2026-05-08 survey: 14 files mixed
across all extensions (the consumer specifically owns a subset of
those — confirm with the grep above).

Pre-rename baseline:

```bash
docker compose exec -w /var/www/extensions/one-page-checkout -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose exec -w /var/www/extensions/one-page-checkout -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Integration
```

These will be **red** when run after 102.1 (that is the point —
102.1's commit message acknowledges the breakage). Capture the
"X tests, Y errors, Z failures" line for the report.

## 4. Steps

### 4.1 Step 1 — Replace the namespace in PHP

```bash
cd source/extensions/one-page-checkout
git ls-files '*.php' \
  | xargs sed -i 's@OxidEsales\\PaymentComponent\\@OxidEsales\\PaymentBase\\@g'
```

Verify zero residue under `src/` and `tests/`:

```bash
grep -rn 'OxidEsales\\PaymentComponent' --include='*.php' src tests metadata.php
```

### 4.2 Step 2 — Replace the namespace in YAML / NEON / XML / JSON

```bash
git ls-files '*.yaml' '*.yml' '*.neon' '*.xml' '*.json' \
  | xargs sed -i 's@OxidEsales\\PaymentComponent\\@OxidEsales\\PaymentBase\\@g'
```

`services.yaml` is the main affected file (per the 2026-05-08
inventory: 41 line hits matching the namespace there). Open it and
eyeball the diff — every alias key, class key, argument key
should now read `OxidEsales\PaymentBase\…`.

### 4.3 Step 3 — Twig / template references

`@oe_payment_component` aliases in twig templates (if any inside
one-page-checkout) → `@oe_payment_base`.

```bash
grep -rn '@oe_payment_component' views/ src/ 2>/dev/null
```

If hits, sed-replace.

### 4.4 Step 4 — Run the suite

```bash
docker compose exec -w /var/www/extensions/one-page-checkout -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose exec -w /var/www/extensions/one-page-checkout -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Integration
```

Both must reach ≥ the pre-rename baseline. Diagnose remaining
failures one by one.

### 4.5 Step 5 — Style + static checks

```bash
cd source/extensions/one-page-checkout
composer phpcs
composer phpstan
composer phpmd
```

Baseline-vs-now: zero new errors. If new errors are namespace-only
(rename surfaced a previously-suppressed FQCN), fix in this commit.
Do **not** add new entries to phpstan baseline — fix the code or
extend the namespace replacement to the missed file.

## 5. Walking order (TDD)

1. Step 1 (PHP sed) — re-run phpunit Unit; report what's now green.
2. Step 2 (yaml sed) — re-run phpunit Integration; report what's
   now green.
3. Step 3 (twig) — only if grep returned hits.
4. Step 4 (full suite) — must be green.
5. Step 5 (style) — must be green.
6. Commit.

## 6. Acceptance criteria

- [ ] No `OxidEsales\PaymentComponent\…` reference under
      `extensions/one-page-checkout/{src,tests,services.yaml,metadata.php,views}`.
- [ ] No `@oe_payment_component` template alias under the same
      paths.
- [ ] phpunit Unit + Integration green, count ≥ pre-rename
      baseline.
- [ ] phpcs / phpstan / phpmd zero new errors.
- [ ] `composer.json` `require` block unchanged from end-of-102.1
      (already says `oxid-esales/payment-base`).

## 7. Done definition

- [ ] §6 acceptance.
- [ ] Sub-sprint moved to `done/sprint-102.2-…md` with
      completion report.
- [ ] Status row updated.

## 8. Risk

- **services.yaml typo** — sed handles backslashes correctly only
  if we use the `@` delimiter (used above). If `/` is used as
  delimiter, the regex breaks. Mitigated by the literal command in
  step 1/2.
- **Test fixture references** — JSON fixtures embedding FQCN
  literals (e.g. webhook payload fakes) get rewritten by step 2;
  if any fixture stores a class-name as data the rewrite is what
  we want.
