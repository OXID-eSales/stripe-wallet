# Sprint 102 — Rename `PaymentComponent` → `PaymentBase` end-to-end

**Repos:** `extensions/payment-component` (→ `payment-base`),
`extensions/one-page-checkout`, `extensions/stripe`,
`extensions/paypal`, plus shop-root `source/composer.json`.
**Mode:** systematic rename split across five atomic sub-sprints.
Each sub-sprint is one git commit and one test gate.
**Trigger:** user-driven naming standardisation
(2026-05-08, ad-hoc — no report file).

## 1. Why

`PaymentComponent` reads as a generic synonym for "module" or
"piece". The package is in fact the **provider-agnostic foundation**
that the PSP modules (Stripe, PayPal) and the checkout layer
(`one-page-checkout`) compose against. Renaming it to `PaymentBase`
makes that role explicit at every site (composer require, namespace
import, services.yaml service ID, OXID module ID, target install
directory, twig template alias).

The rename is structural only. Behaviour, database schema, public
admin URLs, and webhook endpoints all stay identical.

## 2. Goals

- **G1** — `extensions/payment-component/` is fully renamed to
  `extensions/payment-base/`. Composer package
  `oxid-esales/payment-component` → `oxid-esales/payment-base`. PHP
  namespace `OxidEsales\PaymentComponent\` →
  `OxidEsales\PaymentBase\`. Module ID, target-directory and twig
  alias all switch to `oe_payment_base` / `@oe_payment_base`.
- **G2** — Every consumer (`one-page-checkout`, `stripe`, `paypal`)
  imports the new namespace. No `OxidEsales\PaymentComponent\` use
  statement, no FQCN service ID, and no `oxid-esales/payment-component`
  composer dependency remains anywhere outside dev-log markdown.
- **G3** — Shop-root `source/composer.json` requires the renamed
  package and points its path-repository entry at
  `./extensions/payment-base`. `composer install` in the shop root
  resolves cleanly.
- **G4** — OXID module commands work against the new ID:
  `bin/oe-console oe:module:install extensions/payment-base` then
  `oe:module:activate oe_payment_base` succeed.
- **G5** — Every per-module test suite stays green at its sub-sprint
  gate:
  - `payment-base` Unit + Integration ≥ pre-rename baseline.
  - `one-page-checkout` phpunit ≥ pre-rename baseline.
  - `stripe` `./bin/pre-commit-check.sh --full` ≥ 1707 tests
    (Sprint 101 baseline).
  - `paypal` phpunit ≥ pre-rename baseline.
- **G6** — DB-table names (`oe_payments_*`) untouched. No migration
  added, none removed. Schema diff against pre-rename `mysqldump
  --no-data` is empty.
- **G7** — DB-stored module references — `oxconfig.OXMODULE`,
  `oxconfig.OXVARNAME` rows that include the literal
  `oe_payment_component` (e.g. installed-modules registry,
  module-version table, settings) — are migrated by 102.5's
  one-shot SQL or by the OXID module activation flow. Pre-flight
  inventory of those rows lives in 102.5 §3.

## 3. Scope inventory (counts gathered 2026-05-08)

| Module                | PHP files w/ namespace ref | yaml/json/neon/xml refs |
|-----------------------|---------------------------:|------------------------:|
| `payment-component`   |                        353 |                       7 |
| `one-page-checkout`   |                          — |          14 (mixed)     |
| `stripe`              |                          — |         147 (mixed)     |
| `paypal`              |                          — |         100 (mixed)     |
| shop root composer    |                          — |                       1 |

(Mixed = `.php`, `.yaml`, `.json`, `.neon`, `.xml`, excluding
`docs/`, `vendor/`, `node_modules/`.)

Survey commands (reproducible):

```bash
cd source/extensions
grep -rl 'OxidEsales\\PaymentComponent' payment-component --include='*.php' | wc -l
for m in payment-component one-page-checkout stripe paypal; do
  grep -rl 'OxidEsales\\PaymentComponent\|payment-component\|oe_payment_component' "$m" \
       --include='*.php' --include='*.yaml' --include='*.yml' \
       --include='*.json' --include='*.neon' --include='*.xml' \
       --exclude-dir=docs --exclude-dir=vendor --exclude-dir=node_modules \
    | wc -l
done
```

## 4. Sub-sprint dependency graph

```
102.1 (payment-component internals + consumer composer require)
   │
   ├─► 102.2 (one-page-checkout consumer code)
   ├─► 102.3 (stripe consumer code)
   └─► 102.4 (paypal consumer code)
          │
          └─► 102.5 (directory rename + final sweep)
```

102.2 / 102.3 / 102.4 are independent of each other once 102.1
lands; they can be executed in parallel branches and merged in any
order. 102.5 is gated on all three because the directory rename
breaks any consumer composer.json still pointing at
`../payment-component`.

## 5. The five pillars — explicit application

### 5.1 SOLID

- **SRP.** Each sub-sprint touches exactly one concern (one module
  or the directory move). No sub-sprint mixes a rename with a
  feature change or a refactor.
- **OCP.** The rename does not change any public API. Consumers
  swap an import path; behaviour at the seam is identical. Tests
  written against the abstract contracts (e.g. `EventBrokerInterface`)
  do not need to be rewritten.
- **LSP.** Migration namespace `OxidEsales\PaymentComponent\Migrations`
  → `OxidEsales\PaymentBase\Migrations` with no class-level changes.
  Doctrine's migration runner walks classes by FQCN, so the new
  namespace is a clean substitution.
- **ISP.** No new interfaces, no widened ones.
- **DIP.** Service IDs in services.yaml are FQCNs of the abstract
  interfaces. The rename re-points all four modules' DI graph at
  the new FQCNs in lock-step (102.1 in payment-base, 102.2-4 in
  consumers).

### 5.2 TDD

The "test" of a rename is the existing suite. Walking order is:

1. 102.1 closes the rename inside payment-base. Sub-sprint exits
   green by re-running payment-base's own phpunit.
2. 102.2/.3/.4 each take their consumer's test suite from "red on
   the namespace import" to green. The first run inside each
   sub-sprint is **expected red** — that confirms the namespace
   import is the bottleneck — then the rename brings it green.
3. 102.5 re-runs the four suites end-to-end and ships only when all
   four are simultaneously green against the renamed directory.

### 5.3 DRY

- The rename rules live in one place per sub-sprint as a list of
  `git ls-files | xargs sed` substitutions. The same sed script
  template is parameterised per module so the substitution rules
  are not retyped.
- The PHPUnit arch-guard (in opalreturns) bans the literal
  `'stripe'` / `'paypal'` strings — orthogonal to this sprint, so
  no overlap. No new arch-guards added here.

### 5.4 Liskov

The renamed types are byte-identical to the originals modulo
namespace. Any test that depends on the type system (`instanceof`,
type-hints, mocked interfaces) keeps working because the import
target moves but the type identity does not.

### 5.5 Clean Code / DI

- No new classes, no method signature changes.
- No coupling introduced. Existing test seams stay seams.
- DI service IDs are renamed everywhere consistently; tests that
  pull services via `getContainer()->get(...)` use the same
  FQCN as the production code.

## 6. Mid-sprint state (deliberately documented)

Between sub-sprints the codebase is in a **known broken state**:

- **End of 102.1:** payment-base is consistent. one-page-checkout,
  stripe, paypal are red (their `use` statements and services.yaml
  FQCNs still say `OxidEsales\PaymentComponent\…`). `composer
  install` succeeds because the consumer `require` lines were
  patched in 102.1.
- **End of 102.2:** + one-page-checkout green. Stripe, paypal still
  red.
- **End of 102.3:** + stripe green.
- **End of 102.4:** all four module suites green. Directory still
  named `payment-component`.
- **End of 102.5:** directory renamed; root composer points at the
  new path; full sweep green.

This staged-red is not a quality slip — it is the dependency graph
in §4 made literal. Each sub-sprint commits its own gate. CI for
the merge target should run the affected suites only at each
sub-sprint, not the whole monorepo.

## 7. Acceptance criteria (sprint-level)

The sprint is done when **all** are simultaneously true:

- [ ] All sub-sprint acceptance lists (102.1 §6 — 102.5 §6) green.
- [ ] No file under `extensions/{payment-base,one-page-checkout,stripe,paypal}/`
      contains the literal string `OxidEsales\PaymentComponent` outside of
      a) dev-log `docs/` markdown that documents the rename, b) git
      log entries.
- [ ] No `composer.json` (any extension) requires
      `oxid-esales/payment-component`.
- [ ] No services.yaml references a `OxidEsales\PaymentComponent\…`
      service ID.
- [ ] Shop boots: `bin/oe-console oe:module:list` reports
      `oe_payment_base` active and `oe_payment_component` absent.
- [ ] Manual smoke: order-detail Stripe tab renders; admin Capture
      button issues a Stripe API call (test mode).
- [ ] Test totals, all four modules combined, ≥ pre-rename
      baseline.

## 8. Out of scope / follow-ups

- **Renaming database tables.** `oe_payments_*` tables are correctly
  named already; this sprint does not migrate the schema.
- **Renaming the smart-contract / state machine vocabulary.** Class
  names like `PaymentContract`, `BasketSnapshot`, `ContractRepository`
  stay. Only the package / namespace shell renames.
- **Documentation rewrite.** The `docs/architecture/*` files inside
  the renamed module reference `PaymentComponent` in prose. A
  follow-up sprint can rewrite those; this sprint touches docs
  only where the markdown contains code samples that would no
  longer compile.
- **Public release notes / migration guide for downstream consumers.**
  Out of scope; lives in a separate release-management sprint.

## 9. Risk register

- **Risk:** developer machines have a stale `oxconfig.OXMODULE`
  entry referencing `oe_payment_component` after pulling. Symptom:
  shop crashes with "module not found". **Mitigation:** 102.5 ships
  a one-shot SQL UPDATE / OXID module deactivate-then-install-then-
  activate runbook (102.5 §3).
- **Risk:** Doctrine migrations table contains version rows under
  the old namespace and the migration runner refuses to recognise
  them. **Mitigation:** 102.5 §3 inspects the migrations table and
  documents the in-place fix (`UPDATE oe_doctrine_migration_versions
  SET version = REPLACE(version, 'OxidEsales\\PaymentComponent\\',
  'OxidEsales\\PaymentBase\\')`).
- **Risk:** path-repo update in shop-root composer.json is
  forgotten and `composer update` in the shop pulls a stale copy
  from packagist (which does not exist). **Mitigation:** 102.5 §2
  walks the path-repo URLs explicitly with a checklist.
- **Risk:** Twig template cache caches the old `@oe_payment_component`
  alias. **Mitigation:** 102.5 §4 includes `rm -rf source/tmp/*`
  and `bin/oe-console oe:cache:clear` after the rename.
- **Risk:** PHPMD baseline file paths reference `payment-component`
  in `tests/PhpMd/phpmd.baseline.xml`. **Mitigation:** 102.1 §4
  rewrites the baseline file paths.

## 10. Sub-sprint files

- [102.1 — payment-component internals + consumer composer require](sprint-102.1-rename-payment-component-internals.md)
- [102.2 — one-page-checkout consumer code](sprint-102.2-update-one-page-checkout-consumer.md)
- [102.3 — stripe consumer code](sprint-102.3-update-stripe-consumer.md)
- [102.4 — paypal consumer code](sprint-102.4-update-paypal-consumer.md)
- [102.5 — directory rename + final sweep](sprint-102.5-directory-rename-and-final-sweep.md)

## 11. Done definition

- [ ] §7 acceptance, every box.
- [ ] Each sub-sprint moved from `sprints/` → `done/` with a
      completion report alongside (`done/sprint-102.{n}-completion-report.md`).
- [ ] `sprints/sprint-102-rename-payment-component-to-payment-base.md`
      moved to `done/` once all five sub-sprints close.
- [ ] `done/sprint-102-completion-report.md` files the cross-module
      test-count delta.
- [ ] `status.md` updated with the new test counts and a row noting
      the rename is fully landed.
- [ ] MEMORY.md entry under "Project Decisions" recording the rename
      so future Claude sessions don't second-guess it.
