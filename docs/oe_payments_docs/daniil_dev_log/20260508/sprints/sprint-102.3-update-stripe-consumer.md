# Sub-sprint 102.3 — Update `stripe` consumer code

**Parent:** [`sprint-102-rename-payment-component-to-payment-base.md`](sprint-102-rename-payment-component-to-payment-base.md)
**Depends on:** [102.1](sprint-102.1-rename-payment-component-internals.md).
**Independent of:** 102.2 / 102.4 (parallel-safe).
**Repo:** `extensions/stripe`.
**Mode:** sed sweep + the canonical pre-commit gate.

## 1. Why

stripe is the largest consumer (147 mixed-extension files reference
the old namespace per the 2026-05-08 survey). After 102.1, every
one of those files is broken on autoload. This sub-sprint flips
all of them in one atomic commit and runs the canonical pre-commit
script as the gate.

## 2. Goals

- **G1** — Every PHP `use` statement and every FQCN inside `src/`
  and `tests/` swaps `OxidEsales\PaymentComponent\` →
  `OxidEsales\PaymentBase\`.
- **G2** — `services.yaml` (1039 lines per CLAUDE.md) all
  `OxidEsales\PaymentComponent\…` service IDs / aliases /
  argument bindings rewritten.
- **G3** — `metadata.php` `use` statements rewritten.
- **G4** — `tests/PhpStan/phpstan-baseline.neon` and
  `tests/PhpStan/phpstan.neon` re-checked: any namespace literal
  rewritten; baseline file regenerated only if the rewritten code
  surfaces new error messages with the new FQCN strings (acceptable
  baseline diff: identical errors with different FQCN strings).
- **G5** — `tests/PhpMd/phpmd.baseline.xml` and `tests/PhpMd/phpmd.xml`
  rewritten.
- **G6** — All non-dev-log markdown under `docs/` that contains
  PHP code samples referencing the old namespace updated. Prose
  references can stay as a follow-up. Dev-log files
  (`docs/oe_payments_docs/daniil_dev_log/`) are **not** rewritten —
  history stays intact.
- **G7** — `./bin/pre-commit-check.sh --full` green: PHPCS = 0,
  PHPStan max = 0, PHPMD = 0 new (4 baselined), Unit + Integration
  ≥ 1707 tests (Sprint 101 baseline).

Out of scope:
- composer.json `require` line — already patched in 102.1.
- composer.json `repositories[].url` — patched in 102.5.
- Renaming the Stripe module itself (`oxid-esales/stripe-wallet`
  stays).

## 3. Pre-flight inventory

```bash
cd source/extensions/stripe
grep -rln 'OxidEsales\\PaymentComponent' \
  --include='*.php' --include='*.yaml' --include='*.yml' \
  --include='*.json' --include='*.neon' --include='*.xml' \
  --exclude-dir=docs --exclude-dir=vendor --exclude-dir=node_modules \
  . | wc -l
# Expected: 147 (per status.md scope survey).
```

Pre-rename baseline (will be **red** because we are mid-sprint —
the prior sub-sprint, 102.1, intentionally left stripe broken):

```bash
docker compose exec -w /var/www/extensions/stripe -T php \
  vendor/bin/phpunit -c tests/phpunit.xml --testsuite Unit | tail -3
```

Capture the "X tests, Y errors" line for the report.

## 4. Steps

### 4.1 Step 1 — Replace the namespace in PHP

```bash
cd source/extensions/stripe
git ls-files '*.php' \
  | xargs sed -i 's@OxidEsales\\PaymentComponent\\@OxidEsales\\PaymentBase\\@g'
```

Verify:

```bash
grep -rn 'OxidEsales\\PaymentComponent' --include='*.php' src tests metadata.php
# Expect: 0 hits.
```

### 4.2 Step 2 — Replace the namespace in YAML / NEON / XML / JSON

```bash
cd source/extensions/stripe
git ls-files '*.yaml' '*.yml' '*.neon' '*.xml' '*.json' \
  | xargs sed -i 's@OxidEsales\\PaymentComponent\\@OxidEsales\\PaymentBase\\@g'
```

`services.yaml` is the headline file — open after the sweep and
eyeball the diff. Confirm aliases, classes, arguments all switched.

### 4.3 Step 3 — Twig / templates

```bash
grep -rn '@oe_payment_component' views/ src/ 2>/dev/null
```

If hits, sed-replace `@oe_payment_component` →
`@oe_payment_base`.

### 4.4 Step 4 — Phpstan / phpmd / phpcs configs

`tests/PhpStan/phpstan-baseline.neon` typically contains messages
of the form `'…OxidEsales\\PaymentComponent\\…'`. Run
`composer phpstan -- --generate-baseline tests/PhpStan/phpstan-baseline.neon`
to regenerate AFTER step 1/2 land. Diff the regenerated baseline
against the old: only the FQCN strings should differ.

`tests/PhpMd/phpmd.baseline.xml` paths are file-paths, not
namespace strings — sed step 2 already rewrote any namespace tokens
inside. Verify by grep:

```bash
grep -n 'OxidEsales\\PaymentComponent\|payment-component\|oe_payment_component' \
  tests/PhpStan/phpstan*.neon tests/PhpMd/*.xml tests/phpcs.xml 2>/dev/null
```

### 4.5 Step 5 — Markdown that ships code samples

```bash
grep -rln 'OxidEsales\\PaymentComponent\|use OxidEsales\\\\PaymentComponent' docs/ \
  --include='*.md' | grep -v daniil_dev_log
```

Each hit: open and judge whether it's a code block (rewrite) or
prose (leave for follow-up). For 102.3 we rewrite code blocks
only. Commit message lists the touched files.

### 4.6 Step 6 — Pre-commit

```bash
cd source/extensions/stripe
./bin/pre-commit-check.sh --full
```

Acceptance: zero PHPCS errors, zero PHPStan max errors, zero new
PHPMD findings (4 stay baselined per memory note), 1707 tests
green.

If a test file references a payment-base class via a string literal
(reflection-style), step 1 would have missed it. Re-run step 1 and
2 with broader scope (`--include='*'`) on a per-file basis.

## 5. Walking order (TDD)

1. Step 1 (PHP). Re-run Unit suite — should jump from "completely
   red" to "mostly green".
2. Step 2 (yaml/neon/xml/json). Re-run Integration suite.
3. Step 3 (twig) only if hits.
4. Step 4 (phpstan baseline regen).
5. Step 5 (docs code blocks).
6. Step 6 (pre-commit-check.sh --full). Must be 100 % green.
7. Commit.

## 6. Acceptance criteria

- [ ] No `OxidEsales\PaymentComponent` reference under
      `extensions/stripe/{src,tests,services.yaml,metadata.php,views}`.
- [ ] No `@oe_payment_component` alias inside the module.
- [ ] `./bin/pre-commit-check.sh --full` green.
- [ ] Unit + Integration test counts ≥ 1707 (Sprint 101 baseline).
- [ ] PHPStan baseline diff: only FQCN-string changes; no new
      suppressions added.
- [ ] PHPMD baseline unchanged (paths still match; namespace inside
      paths is irrelevant).
- [ ] Doc files updated: code samples only (prose stays).

## 7. Done definition

- [ ] §6 acceptance.
- [ ] Sub-sprint moved to `done/sprint-102.3-…md` with completion
      report.
- [ ] Status row updated with the pre-/post-rename test count.

## 8. Risk

- **Sed missed string literals** — class names embedded as strings
  (rare in stripe, but webhook-handler dispatch tables and
  `$type === 'OxidEsales\\PaymentComponent\\…'` checks could exist).
  Mitigated by the test suite — any miss surfaces as "class not
  found" or "test asserts wrong FQN".
- **PHPStan baseline diff explosion** — if the regenerated baseline
  differs in row order or structure beyond FQCN strings, a
  hand-curated diff is needed. Mitigated by regenerating once the
  rename is otherwise green.
- **Symfony DI autowire sweep** (per memory feedback) — the
  `resource: 'src/.../*'` glob could pull a renamed file that now
  has unbound deps. Verified by running
  `bin/oe-console oe:cache:clear` and exercising the activate path
  in 102.5; for 102.3, container compilation success in phpunit
  bootstrap is enough.
