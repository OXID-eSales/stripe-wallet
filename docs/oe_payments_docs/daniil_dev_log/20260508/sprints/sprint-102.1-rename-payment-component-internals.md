# Sub-sprint 102.1 — Rename payment-component internals + patch consumer `require`

**Parent:** [`sprint-102-rename-payment-component-to-payment-base.md`](sprint-102-rename-payment-component-to-payment-base.md)
**Repo:** `extensions/payment-component` (directory stays put for now), plus
the `require` line in `extensions/{stripe,paypal,one-page-checkout}/composer.json`
and the shop-root `source/composer.json`.
**Mode:** systematic global substitution + module-targeted phpunit run.

## 1. Why

Sprint 102 §4 dependency graph: every consumer's code change is
gated on the namespace landing inside payment-component first. This
sub-sprint is that landing. It is intentionally narrow:

- it does NOT move the directory (`extensions/payment-component/`
  stays — moved in 102.5);
- it does NOT touch consumer PHP files (102.2/.3/.4);
- it DOES touch the `require` and `repositories.[].package.name`
  lines in every consumer composer.json so that
  `composer install` in the shop root still resolves after the
  package name change.

## 2. Goals

- **G1** — `composer.json` in `extensions/payment-component/`:
  - `name` → `oxid-esales/payment-base`
  - `autoload.psr-4` keys → `OxidEsales\PaymentBase\` and
    `OxidEsales\PaymentBase\Migrations\`
  - `autoload-dev.psr-4` key → `OxidEsales\PaymentBase\Tests\`
  - `extra.oxideshop.target-directory` → `oe_payment_base`
- **G2** — `metadata.php` (payment-component):
  - `'id' => 'oe_payment_base'`
  - any `'@oe_payment_component/…'` template alias →
    `'@oe_payment_base/…'`
  - any `OxidEsales\PaymentComponent\…` use statement →
    `OxidEsales\PaymentBase\…`
- **G3** — `services.yaml` (payment-component): every FQCN service
  ID swapped namespace prefix.
- **G4** — All `src/`, `tests/`, `migration/data/` PHP files: each
  `namespace OxidEsales\PaymentComponent\…;` and `use
  OxidEsales\PaymentComponent\…` rewritten to `OxidEsales\PaymentBase\…`.
- **G5** — `migration/migrations.yml` data namespace key →
  `OxidEsales\PaymentBase\Migrations`.
- **G6** — `phpunit.xml`, `phpstan.neon`, `tests/PhpStan/phpstan.neon`,
  `tests/PhpMd/phpmd.xml`, `tests/PhpMd/phpmd.baseline.xml`,
  `tests/phpcs.xml` and any `bin/*` script: every `payment-component`
  / `PaymentComponent` reference inspected; substituted where it
  refers to namespace, package, or module ID; left alone where it
  is a directory path (102.5 will handle those).
- **G7** — `extensions/{stripe,paypal,one-page-checkout}/composer.json`
  AND shop-root `source/composer.json`: every
  `oxid-esales/payment-component` literal becomes
  `oxid-esales/payment-base`. The path-repo `url` field is **not**
  changed here (102.5 does that with the directory move).
- **G8** — Composer autoload regenerates cleanly:
  ```bash
  cd source/extensions/payment-component && composer dump-autoload
  ```
- **G9** — payment-base unit + integration phpunit ≥ pre-rename
  baseline. Run inside the renamed module's own `tests/phpunit.xml`.

Out of this sub-sprint:
- consumer PHP files (`use` statements, FQCN service IDs, tests) —
  102.2 / 102.3 / 102.4
- directory move + path-repo URL switch — 102.5
- DB-stored module-ID strings (`oxconfig.OXMODULE`) — 102.5

## 3. Pre-flight inventory

```bash
cd source/extensions/payment-component

# PHP files to rename (namespace + use):
grep -rln 'OxidEsales\\PaymentComponent' --include='*.php' . | wc -l
# Expected: 353 (per status.md scope survey).

# YAML / JSON / NEON / XML to rename:
grep -rln 'OxidEsales\\PaymentComponent\|payment-component\|oe_payment_component\|PaymentComponent' \
  --include='*.yaml' --include='*.yml' --include='*.json' \
  --include='*.neon' --include='*.xml' . | wc -l
# Expected: 7.
```

Specific inspect-points (no edit, just surface):

```bash
grep -nE "id|target-directory|@oe_payment_component" metadata.php composer.json
grep -nE "OxidEsales\\\\PaymentComponent" services.yaml
```

The pre-flight run captures the pre-rename baseline:

```bash
cd source/extensions/payment-component
docker compose -f ../../../docker-compose.yml exec -w /var/www/extensions/payment-component -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose -f ../../../docker-compose.yml exec -w /var/www/extensions/payment-component -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Integration
```

(Record numbers; this sub-sprint must equal or exceed.)

## 4. Steps

### 4.1 Step 1 — Replace the namespace in PHP

```bash
cd source/extensions/payment-component
git ls-files '*.php' \
  | xargs sed -i 's@OxidEsales\\PaymentComponent\\@OxidEsales\\PaymentBase\\@g'
```

Verify zero residue:

```bash
grep -rn 'OxidEsales\\PaymentComponent' --include='*.php' . && echo '!! still references' || echo 'clean'
```

### 4.2 Step 2 — Replace the namespace in YAML / NEON / XML / JSON

```bash
cd source/extensions/payment-component
git ls-files '*.yaml' '*.yml' '*.neon' '*.xml' '*.json' \
  | xargs sed -i 's@OxidEsales\\PaymentComponent\\@OxidEsales\\PaymentBase\\@g'
```

### 4.3 Step 3 — Patch composer.json package + autoload

Manually edit `composer.json`:

- `"name": "oxid-esales/payment-base"`
- autoload psr-4 keys (escape backslashes):
  - `"OxidEsales\\PaymentBase\\": "src/"`
  - `"OxidEsales\\PaymentBase\\Migrations\\": "migration/data/"`
- autoload-dev:
  - `"OxidEsales\\PaymentBase\\Tests\\": "tests/"`
- `extra.oxideshop.target-directory`: `oe_payment_base`

Then `composer dump-autoload` and confirm zero warnings.

### 4.4 Step 4 — Patch metadata.php

Replace:
- `'id' => 'oe_payment_component'` → `'id' => 'oe_payment_base'`
- `'@oe_payment_component/…'` → `'@oe_payment_base/…'`
- `'name' => …` (human-readable) — left untouched unless it
  embeds the literal string.

Run a final grep to confirm no `oe_payment_component` token remains
inside the module:

```bash
grep -rn 'oe_payment_component\|@oe_payment_component' \
  --include='*.php' --include='*.yaml' --include='*.yml' \
  --include='*.json' --include='*.xml' --include='*.neon' \
  . | grep -v vendor
```

### 4.5 Step 5 — Patch migrations.yml

```yaml
namespaces:
  'OxidEsales\PaymentBase\Migrations': data
```

(One-line file update; verify Doctrine still discovers the
migration class by class-loading the Version*.php that was
namespace-rewritten in step 1.)

### 4.6 Step 6 — Patch consumer composer.json `require` lines

Across:
- `source/composer.json`
- `source/extensions/stripe/composer.json`
- `source/extensions/paypal/composer.json`
- `source/extensions/one-page-checkout/composer.json`

Replace **only** the package-name literal:

```bash
for f in \
    source/composer.json \
    source/extensions/stripe/composer.json \
    source/extensions/paypal/composer.json \
    source/extensions/one-page-checkout/composer.json
do
  sed -i 's@oxid-esales/payment-component@oxid-esales/payment-base@g' "$f"
done
```

Do **not** modify the `repositories[].url` field (still
`../payment-component` / `./extensions/payment-component`) —
102.5 handles that with the directory move.

After this step, the path-repo URL still resolves to a directory
that contains a `composer.json` whose `name` is now
`oxid-esales/payment-base`. Composer matches the require by `name`,
and the URL is just the location — so `composer install` resolves.
This is the key invariant that lets 102.1 land without needing
102.5 first.

### 4.7 Step 7 — `composer install` smoke

```bash
cd source && composer install
```

Expect zero "package not found" errors. Expect autoload to map
`OxidEsales\PaymentBase\` to the still-named-`payment-component`
directory.

### 4.8 Step 8 — Inside payment-component: regenerate autoload

```bash
cd source/extensions/payment-component && composer dump-autoload
```

### 4.9 Step 9 — Update phpstan / phpmd / phpcs configs

- `phpstan.neon`, `tests/PhpStan/phpstan.neon`: any `paths:` entry
  to `src/` is fine (relative). Any `excludePaths:` referencing the
  namespace should now use `OxidEsales\PaymentBase\…`.
- `tests/PhpMd/phpmd.baseline.xml`: regenerate if its file paths
  embed the namespace; otherwise leave (it tracks file-path hits).
- `tests/PhpMd/phpmd.xml`: rule names — typically no namespace
  reference; verify by grep.

### 4.10 Step 10 — Run payment-base tests

Inside Docker:

```bash
docker compose exec -w /var/www/extensions/payment-component -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose exec -w /var/www/extensions/payment-component -T php \
  vendor/bin/phpunit -c phpunit.xml --testsuite Integration
```

Both green is the gate. `composer phpcs`, `composer phpstan`,
`composer phpmd` also green.

## 5. Walking order (TDD)

1. **Step 1, 2** (sed sweeps) — run, then immediately `git diff
   --stat` and eyeball.
2. **Step 7** (`composer install`) — must resolve. If it errors,
   stop, root-cause, do not proceed.
3. **Step 10** (phpunit inside payment-base) — first run can be
   red on individual tests if the sed sweep missed something
   (e.g. a string-literal class name). Fix per-failure, do not
   batch.
4. Once green: full PHPCS / PHPStan / PHPMD, all green.
5. Commit.

## 6. Acceptance criteria

The sub-sprint is done when **all** are simultaneously true:

- [ ] `cd source/extensions/payment-component && grep -rn
      'OxidEsales\\PaymentComponent' --include='*.php' .` returns no
      hits.
- [ ] `composer.json` (payment-component) contains
      `"oxid-esales/payment-base"`, autoload keys
      `OxidEsales\\PaymentBase\\` (× 2), target-directory
      `oe_payment_base`.
- [ ] `metadata.php` `'id'` is `'oe_payment_base'`; no
      `@oe_payment_component` alias remains.
- [ ] `migration/migrations.yml` namespaces key is
      `OxidEsales\PaymentBase\Migrations`.
- [ ] `cd source && composer install` returns 0.
- [ ] payment-base phpunit Unit + Integration green; counts ≥
      pre-rename baseline.
- [ ] `composer phpcs && composer phpstan && composer phpmd`
      (inside payment-component) all green.
- [ ] No edits to consumer (`stripe`, `paypal`, `one-page-checkout`)
      PHP files in this commit. Only their composer.json `require`
      line is touched.
- [ ] Directory `extensions/payment-component/` still exists at
      this commit. (It moves in 102.5.)

## 7. Done definition

- [ ] §6 acceptance, every box.
- [ ] Sub-sprint moved to `done/sprint-102.1-…md` plus
      `done/sprint-102.1-completion-report.md`.
- [ ] Status.md updated with one row noting "102.1 landed; 102.2
      next".
- [ ] Hand-off: `git log --stat -1` quoted in the completion report
      so the next sub-sprint sees the exact baseline.

## 8. Risk

- **Sed false-positives** — the namespace literal is specific
  enough that `OxidEsales\\PaymentComponent\\` should never match
  inside string content unrelated to the package. If any tests
  match the namespace as a runtime string (e.g. registered service
  IDs in tests), they too move correctly to the new prefix.
  Verified by step 10's full phpunit run.
- **Forgotten file type** — `.dist`, `.lock`, dotfiles. Mitigated
  by step 1/2 limiting to git-tracked files of the listed
  extensions, then step 4's grep over the same extension list.
- **Composer cache stale** — if `composer install` resolves the
  package from cache by old name, run `composer clear-cache && composer
  install`.
