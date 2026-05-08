# Sub-sprint 102.5 — Directory rename + final cross-module sweep

**Parent:** [`sprint-102-rename-payment-component-to-payment-base.md`](sprint-102-rename-payment-component-to-payment-base.md)
**Depends on:** all of [102.1](sprint-102.1-rename-payment-component-internals.md),
[102.2](sprint-102.2-update-one-page-checkout-consumer.md),
[102.3](sprint-102.3-update-stripe-consumer.md),
[102.4](sprint-102.4-update-paypal-consumer.md) — all four green
on every prior gate.
**Repo:** monorepo top-level (touches every extension's
`composer.json` and the shop-root composer / database).
**Mode:** atomic directory move + composer/path-repo update +
OXID module re-activation.

## 1. Why

After 102.1–102.4 the package is named, namespaced, and consumed
as `payment-base` everywhere — except for one stubborn fact: the
directory on disk is still `extensions/payment-component/`. The
remaining work is:

1. Move the directory.
2. Update the path-repo URL in every composer.json that points at
   it (one shop-root + three consumer files).
3. Re-run `composer update` so the lock file rewrites.
4. Deactivate the old OXID module ID `oe_payment_component` and
   install/activate the new ID `oe_payment_base`.
5. Sanity-check the shop database for stale module-ID rows
   (`oxconfig.OXMODULE`, `oxmodule_settings`, etc.).
6. Re-run all four module test suites end-to-end.

This is the riskiest sub-sprint because it touches the disk
layout, the composer lock, the OXID install registry, and the
database. Steps are explicit, atomic where possible, and rollback-
documented.

## 2. Goals

- **G1** — `extensions/payment-component/` no longer exists.
  `extensions/payment-base/` exists with identical content (except
  the rename done in 102.1).
- **G2** — `source/composer.json` and every consumer composer.json
  has its `repositories[].url` updated:
  - shop root: `./extensions/payment-component` →
    `./extensions/payment-base`
  - consumers: `../payment-component` → `../payment-base`
- **G3** — `composer update` in the shop root succeeds; the lock
  file's package entry resolves through the new URL.
- **G4** — `bin/oe-console oe:module:deactivate oe_payment_component`
  succeeds (clean) or is a no-op (already deactivated). Then
  `bin/oe-console oe:module:install extensions/payment-base` and
  `bin/oe-console oe:module:activate oe_payment_base` succeed.
- **G5** — Database: any row referencing the literal module ID
  `oe_payment_component` is migrated by step 5 (one-shot SQL or
  the OXID activation flow itself).
- **G6** — Final cross-module test sweep, all four modules
  simultaneously green:
  - payment-base Unit + Integration
  - one-page-checkout Unit + Integration
  - stripe `./bin/pre-commit-check.sh --full` (≥ 1707 tests)
  - paypal Unit + Integration
- **G7** — Twig template cache and OXID DI cache cleared. Admin
  order-detail Stripe tab loads in browser; admin Capture button
  triggers a Stripe API call (test mode).

## 3. Pre-flight inventory

### 3.1 Path-repo URL audit

```bash
cd /home/dtkachev/osc/strpwt7-nov26
grep -nE '"url"[[:space:]]*:[[:space:]]*"[^"]*payment-component"' \
  source/composer.json \
  source/extensions/{stripe,paypal,one-page-checkout}/composer.json
```

Expected hits:
- `source/composer.json`: 1 hit (`./extensions/payment-component`)
- each consumer: 1 hit (`../payment-component`)

### 3.2 Database audit

Inside the PHP container:

```bash
docker compose exec mysql mysql -uroot -proot -D oxid -e "
  SELECT * FROM oxconfig WHERE OXVARNAME LIKE '%aDisabledModules%' OR OXVARVALUE LIKE '%oe_payment_component%' LIMIT 50;
  SELECT * FROM oxmodule_settings WHERE OXMODULEID = 'oe_payment_component' LIMIT 50;
  SELECT name FROM oe_doctrine_migration_versions WHERE name LIKE '%PaymentComponent%' LIMIT 50;
"
```

(Adjust DB name to `.env` actual.) Capture every hit; step 5 fixes
them.

### 3.3 OXID install-dir audit

```bash
ls -la source/source/out/modules/ | grep payment
```

Expected: a symlink `oe_payment_component → ../../../vendor/oxid-esales/payment-component`.
After this sub-sprint: symlink `oe_payment_base → ../../../vendor/oxid-esales/payment-base`.

## 4. Steps

### 4.1 Step 1 — Atomic directory move

```bash
cd /home/dtkachev/osc/strpwt7-nov26/source/extensions
git mv payment-component payment-base
```

`git mv` preserves history. Verify:

```bash
git status --short | head -5     # rename rows only
ls payment-base/composer.json    # exists
[ ! -e payment-component ] && echo 'old gone'
```

### 4.2 Step 2 — Update path-repo URLs

Shop root (`source/composer.json`):

```bash
sed -i 's@"./extensions/payment-component"@"./extensions/payment-base"@g' \
  source/composer.json
```

Each consumer:

```bash
for m in stripe paypal one-page-checkout; do
  sed -i 's@"../payment-component"@"../payment-base"@g' \
    "source/extensions/$m/composer.json"
done
```

Verify zero residue:

```bash
grep -rn 'extensions/payment-component\|"\.\./payment-component"' \
  source/composer.json source/extensions/*/composer.json
# Expect: 0 hits.
```

### 4.3 Step 3 — Composer update + lock regen

```bash
cd /home/dtkachev/osc/strpwt7-nov26/source
composer clear-cache
composer update --no-interaction
```

Inspect:

```bash
grep -A 2 '"name": "oxid-esales/payment-base"' composer.lock | head
```

The dist URL / source URL inside `composer.lock` for the renamed
package now resolves through `./extensions/payment-base`.

### 4.4 Step 4 — OXID module re-activation

```bash
docker compose exec php bin/oe-console oe:module:deactivate oe_payment_component || true
docker compose exec php bin/oe-console oe:module:install extensions/payment-base
docker compose exec php bin/oe-console oe:module:activate oe_payment_base
docker compose exec php bin/oe-console oe:cache:clear
```

The `|| true` on deactivate covers the case where the old ID was
already cleaned out by composer update / DB step.

`oe:module:install` regenerates the symlink under
`source/source/out/modules/oe_payment_base`. Confirm:

```bash
ls -la source/source/out/modules/ | grep -E 'oe_payment'
```

Old symlink `oe_payment_component` may persist as a stale link;
remove if present:

```bash
[ -L source/source/out/modules/oe_payment_component ] && \
  rm source/source/out/modules/oe_payment_component
```

### 4.5 Step 5 — Database migration of stale module-ID rows

For each pre-flight (§3.2) hit, write the targeted UPDATE:

```sql
-- Doctrine migration versions table:
UPDATE oe_doctrine_migration_versions
   SET version = REPLACE(version,
       'OxidEsales\\PaymentComponent\\',
       'OxidEsales\\PaymentBase\\')
 WHERE version LIKE '%PaymentComponent%';

-- Module settings table:
UPDATE oxmodule_settings
   SET OXMODULEID = 'oe_payment_base'
 WHERE OXMODULEID = 'oe_payment_component';

-- Disabled-modules list etc. inside oxconfig (varies by install):
-- inspect each row from §3.2 and run a targeted UPDATE.
```

The activate command in step 4 typically writes a fresh row for
the new ID; the UPDATEs above migrate any settings / per-shop
config that survived. Order: deactivate → SQL → activate.

### 4.6 Step 6 — PHP-FPM opcache restart (per memory note)

```bash
docker compose restart php
```

Per `feedback_php_opcache_fpm`: PHP-FPM doesn't react to
`oe:cache:clear` for class-level changes.

### 4.7 Step 7 — Twig + tmp cache clear

```bash
rm -rf source/tmp/*
```

### 4.8 Step 8 — Final test sweep

```bash
# payment-base
docker compose exec -w /var/www/extensions/payment-base -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose exec -w /var/www/extensions/payment-base -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Integration

# one-page-checkout
docker compose exec -w /var/www/extensions/one-page-checkout -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose exec -w /var/www/extensions/one-page-checkout -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Integration

# stripe (canonical pre-commit)
cd source/extensions/stripe && ./bin/pre-commit-check.sh --full

# paypal
docker compose exec -w /var/www/extensions/paypal -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose exec -w /var/www/extensions/paypal -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Integration
```

All four green.

### 4.9 Step 9 — Manual smoke

Browser flow (test mode):

1. Open admin → Order detail → Stripe tab. Page loads, no template
   alias error.
2. Click Capture button on an authorized test order — Stripe API
   call returns 200.
3. Front shop checkout → Stripe redirect → return → order
   committed.

If any of these fail, root-cause inside this sub-sprint — do not
ship 102.5 with a broken admin / checkout.

## 5. Walking order

1. Step 1 — directory move (`git mv`).
2. Step 2 — path-repo URLs (sed).
3. Step 3 — `composer update`. Must resolve. If not, **rollback
   step 1 with `git mv extensions/payment-base extensions/payment-component`
   and `git checkout -- source/composer.json source/extensions/*/composer.json`**;
   then diagnose.
4. Step 4 — module deactivate / install / activate.
5. Step 5 — DB migration UPDATEs (only if §3.2 reported hits).
6. Step 6 — PHP-FPM restart.
7. Step 7 — tmp cache clear.
8. Step 8 — final test sweep. All four modules green.
9. Step 9 — manual smoke. Pass.
10. Commit.

## 6. Acceptance criteria

- [ ] `extensions/payment-component/` does not exist.
      `extensions/payment-base/` exists.
- [ ] No `extensions/payment-component` literal in any composer.json
      (root or extensions).
- [ ] No `../payment-component` literal in any consumer composer.json.
- [ ] `composer install` and `composer update` both green from a
      fresh shell.
- [ ] `bin/oe-console oe:module:list` shows `oe_payment_base`
      active and `oe_payment_component` absent.
- [ ] `oe_doctrine_migration_versions` table's `version` column
      contains no `PaymentComponent` substring.
- [ ] `oxmodule_settings.OXMODULEID = 'oe_payment_component'` row
      count is 0.
- [ ] All four module test suites green.
- [ ] Manual smoke (admin Stripe tab + Capture + checkout) passes.

## 7. Done definition

- [ ] §6 acceptance.
- [ ] Sub-sprint moved to `done/sprint-102.5-…md` with completion
      report.
- [ ] Sprint 102 itself moved from `sprints/` → `done/` with a
      cross-sub-sprint completion report
      (`done/sprint-102-completion-report.md`).
- [ ] Status row updated: "Sprint 102 — fully landed".
- [ ] MEMORY.md "Project Decisions" section gains an entry:
      `[payment-component is now payment-base](project_payment_base_rename.md)`
      so future sessions don't second-guess the name.
- [ ] Pending list in status.md cleaned of any 102-related items.

## 8. Risk

- **`git mv` preserves history only when the rename is a
  sufficiently small fraction of the file's content** (Git's
  rename detection threshold). For the directory move, every file
  moves intact; rename detection should be reliable. If `git log
  --follow extensions/payment-base/src/Foo.php` does NOT show
  history, accept it and document. (No fix; preserve detection
  hint via `git config diff.renames true`.)
- **`composer update` regenerates more than expected** — without
  pinning, transitive deps can drift. Mitigation: the only path
  repo we touched is the renamed one. Add `--prefer-stable` and
  inspect the lock-file diff before committing.
- **Database migration drift** — if step 5 misses a row, runtime
  code that resolves the module ID by string (`Registry::getConfig()
  ->getModulesWithExtendedClass()` etc.) returns the old ID and
  classes don't load. Mitigation: §3.2's pre-flight is exhaustive;
  if a runtime issue surfaces, the fix is a one-line SQL UPDATE,
  documented in the completion report.
- **OXID install symlink stale** — `source/source/out/modules/oe_payment_component`
  may persist after install. Step 4 explicitly removes it.
- **PHP-FPM opcache** — per memory note, restart is mandatory after
  class-level changes. Step 6 covers it.
- **Twig template cache** — per CLAUDE.md, `rm -rf source/tmp/*`
  after template changes. Step 7 covers it.
