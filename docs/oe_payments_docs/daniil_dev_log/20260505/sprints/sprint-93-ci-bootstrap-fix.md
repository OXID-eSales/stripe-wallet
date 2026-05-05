# Sprint 93 — CI bootstrap fix after Sprint I unification

_Branch (both repos): `b-7.4.x-ci-fix-sprint-93`._
_Continues from Sprint I (`../../2026/04/20260423/sprints/sprint-I-unified-payment-admin-tab.md`)._
_Diagnostic that justifies this sprint: `../reports/01-ci-broken-after-unification.md`._

## Motivation (what Sprint I left unfinished)

Sprint I §80–95 ("Install-flow changes outside PC") prescribed **two
new CI workflow steps** in every consumer of payment-component:

```yaml
- docker compose exec -T php bin/oe-console oe:module:install /var/www/vendor/oxid-esales/payment-component
- docker compose exec -T php bin/oe-console oe:module:activate oe_payment_component
```

These steps were **specified but never landed** in the workflow files.
The package-side change (`type: composer-plugin` → `oxideshop-module`,
new `metadata.php` / `menu.xml` / `services.yaml`) shipped in
`db5d3506` on 2026-04-23 14:33 UTC. Within minutes both consumer CIs
went red:

| Repo | First red commit | First red run |
|---|---|---|
| `OXID-eSales/payment-component` | `db5d3506` | run 105 (2026-04-23) |
| `OXID-eSales/stripe-wallet` | `bdb45862` | run 505 (2026-04-23) |

Failure mode (identical in both repos): `bin/oe-console oe:database:reset`
exits 255 in ~250 ms. Artifact `data/php/logs/error_log.txt`:

```text
PHP Fatal error: Uncaught Error: Class
  "OxidEsales\Eshop\Core\ConfigFile" not found in /var/www/source/bootstrap.php:184
```

The OXID composer plugin's "Install module" pass for
`oe_payment_component` runs after the
`oxideshop-unified-namespace-generator`'s post-install hook and
desyncs the autoload classmap — `\OxidEsales\Eshop\Core\ConfigFile`
isn't on the classmap by the time `oe-console` first boots. Until the
classmap is re-dumped and the `oe_payment_component` module is
explicitly installed/activated, every `oe-console` invocation in CI
fatals on the same line.

## Target

Land Sprint I §80–95 verbatim, plus one extra `composer dump-autoload`
step that Sprint I didn't anticipate (the autoload-classmap desync was
not predicted at the time the unification spec was written).

After this sprint:
- `bin/oe-console list` exits 0 in CI immediately after `composer update`.
- `oe_payment_component` is an **installed and activated** module before
  any PSP module activates.
- `PaymentPanelRegistry` resolves ≥1 provider in an integration test
  bootstrapped through the shop container.
- `pre-commit-check.sh --full` and the GitHub Actions `Development`
  workflow are green on both `OXID-eSales/payment-component` and
  `OXID-eSales/stripe-wallet`.

## Core engineering requirements — how each applies

**TDD-first.**
Three failing probes added before any workflow edit. Each probe
isolates one symptom. They stay in the codebase as regression guards.

**SOLID.**
- **S** — every workflow step does one thing. Today's `Install demodata`
  step actually runs `oe:database:reset` AND `oe:setup:demodata`; split.
- **O** — adding a new module to CI must not require editing the
  shared install steps; lift the install/activate pattern into a
  composite shell function reused per module.
- **L** — `oe_payment_component` is interchangeable with any other
  `oxideshop-module` in the install pipeline; no special-case branch
  in the workflow for it.
- **I** — the CI "module install contract" is the pair
  `(module_id, vendor_path)`. No more, no less.
- **D** — workflow steps depend on `bin/oe-console` (the abstraction);
  they do not reach into `vendor/oxid-esales/<module>/source/` or
  `source/modules/<id>/` directly.

**DI / DRY / Clean code.**
The composite step (Phase R2 below) replaces three identical 3-line
blocks across the install_shop_with_module job with one call site.

**No overengineering.**
No GitHub composite action, no reusable workflow, no shell library —
just an inline bash helper function in the install job. The four
consumer repos (Stripe, PayPal, OPC, opalreturns) will copy-paste the
same six lines until a real composite action becomes worth the
maintenance cost.

**Drop deprecated.**
Remove the `allow-plugins.oxid-esales/payment-component: true` entry
from `source/extensions/stripe/composer.json` **only after** the
sprint lands green. While the bootstrap fix is being verified, that
stale entry isolates "is this autoload-related or
allow-list-related?" and removing it in the same PR would muddy the
CI signal.

## Phase R (RED) — failing probes first

Each probe lives next to the code it guards and is wired into the
existing `bin/pre-commit-check.sh` pipeline.

### Probe 1 — `tests/Unit/Smoke/UnifiedNamespaceClassmapTest.php`

Lives in **payment-component**. Original sketch tried to assert
`class_exists(OxidEsales\Eshop\Core\ConfigFile)` directly — but PC's
standalone `styles` job runs against PC's own vendor folder, which
doesn't depend on `oxid-esales/oxideshop-ce`, so the OXID virtual
namespace classes are never present and the assertion would always
fail. That isn't the bug we want to catch in this job.

What this job *can* protect cheaply is the **load-bearing package
shape**: `composer.json` `type` must stay `oxideshop-module`,
`extra.oxideshop.target-directory` must stay `oe_payment_component`,
and `metadata.php` must declare the matching module id and the
`PaymentAdmin` controller. Any future PR that walks any of these
backwards re-greens the original Sprint 93 failure mode. The
autoload-classmap regression itself is caught by Probe 2 in the
`install_shop_with_module` job, which is where the shop vendor
actually exists.

```php
namespace OxidEsales\PaymentComponent\Tests\Unit\Smoke;

use PHPUnit\Framework\TestCase;

final class UnifiedNamespaceClassmapTest extends TestCase
{
    private const PACKAGE_ROOT = __DIR__ . '/../../..';
    private const MODULE_ID = 'oe_payment_component';

    public function testComposerJsonDeclaresPackageAsOxideshopModule(): void
    {
        // assert composer.json type === 'oxideshop-module'
        // assert extra.oxideshop.target-directory === 'oe_payment_component'
    }

    public function testMetadataPhpDeclaresPaymentComponentModuleId(): void
    {
        // require metadata.php; assert $aModule['id'] === 'oe_payment_component'
        // assert $aModule['controllers']['PaymentAdmin'] is set
    }
}
```

Runs in the **styles** job of payment-component's workflow (no shop
boot needed — just composer autoload). Enters the existing PHPUnit
Unit suite via `tests/Unit/Smoke/`. Cost: 2 tests, ~5 ms.

**Why payment-component owns it:** the load-bearing decision is in
PC's `composer.json` and `metadata.php`. PC owns the regression.

### Probe 2 — `bin/oe-console list` smoke step in `install_shop_with_module`

A single workflow step inserted between `composer update` and
`oe:database:reset`:

```yaml
- name: Smoke-test oe-console boot
  run: docker compose exec -T php bin/oe-console list > /dev/null
```

If this exits 0, bootstrap.php worked end to end and the
unified-namespace classes are loadable. If it fails, we get the same
`ConfigFile not found` trace **before** any database step runs — the
"first failing line of CI" moves into a step that explicitly tests
bootstrap, instead of being silently wedged inside a multi-command
demodata step. This is the install_shop_with_module job's
single-responsibility upgrade (SOLID-S).

### Probe 3 — `tests/Integration/Admin/PaymentPanelRegistryIntegrationTest.php`

Lives in **stripe-wallet**. Bootstraps the shop container, activates
`oe_payment_component` and `oe_payments_stripe_wallet`, and asserts
the registry returns Stripe's panel provider.

```php
namespace OxidEsales\Payments\Stripe\Tests\Integration\Admin;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Admin\Contract\PaymentPanelRegistryInterface;
use OxidEsales\Payments\Stripe\Admin\StripePaymentPanelProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 93 — once oe_payment_component is properly installed +
 * activated in CI, its tag iterator must collect the Stripe panel
 * provider. Empty registry = misconfigured CI install order
 * (PC activated AFTER Stripe, or not at all).
 */
final class PaymentPanelRegistryIntegrationTest extends TestCase
{
    public function testStripePanelProviderIsRegistered(): void
    {
        $registry = $this->getContainer()->get(PaymentPanelRegistryInterface::class);
        $names = array_map(
            static fn ($p) => $p->getProviderName(),
            iterator_to_array($registry->getProviders()),
        );

        self::assertContains(
            StripePaymentPanelProvider::PROVIDER_KEY,
            $names,
            'Stripe panel provider missing from PaymentPanelRegistry. '
            . 'Likely cause: oe_payment_component was not activated '
            . 'before oe_payments_stripe_wallet in CI — see Sprint 93.'
        );
    }

    private function getContainer(): \Psr\Container\ContainerInterface
    {
        // OXID 7.4 ContainerFactory::get(); concrete shape fixed in Probe 3 implementation.
        return \OxidEsales\EshopCommunity\Internal\Container\ContainerFactory::getInstance()->getContainer();
    }
}
```

Runs in the **integration_tests** job. Requires the GREEN phase below
to pass — until `oe_payment_component` is activated in CI, this test
is permanently red. That's the point: it stays red until the install
order is correct.

### Local reproduction of all three probes

```bash
# Probe 1 — runs everywhere composer is available:
docker compose exec -T -w /var/www/extensions/payment-component php \
  vendor/bin/phpunit --filter UnifiedNamespaceClassmapTest

# Probe 2 — same env CI uses:
docker compose exec -T php bin/oe-console list > /dev/null && echo OK

# Probe 3 — needs full container + module activation:
docker compose exec -T php bin/oe-console oe:module:activate oe_payment_component
docker compose exec -T php bin/oe-console oe:module:activate oe_payments_stripe_wallet
docker compose exec -T -w /var/www/extensions/stripe php \
  vendor/bin/phpunit -c tests/phpunit.xml --filter PaymentPanelRegistryIntegrationTest
```

## Phase G (GREEN) — minimum workflow patches

### G.1 — stripe-wallet `.github/workflows/development.yml`

**Insert** between the existing `Install dependencies, reset shop,
activate theme` step (workflow line 132) and the `Install demodata`
step (line 139):

```yaml
- name: Re-dump autoload after OXID module install
  run: |
    docker compose exec -T php composer dump-autoload --optimize --no-interaction

- name: Smoke-test oe-console boot              # Probe 2
  run: docker compose exec -T php bin/oe-console list > /dev/null

- name: Install + activate payment-component module
  run: |
    docker compose exec -T php bin/oe-console oe:module:install \
      /var/www/vendor/oxid-esales/payment-component
    docker compose exec -T php bin/oe-console oe:module:activate \
      oe_payment_component
```

**Split** the existing `Install demodata` step (line 139–143) into two
steps so SOLID-S is satisfied at the workflow level:

```yaml
- name: Reset database
  run: |
    docker compose exec -T php bin/oe-console oe:database:reset \
      --db-host=mysql --db-port=3306 --db-name=example \
      --db-user=root --db-password=root --force

- name: Install demodata
  run: |
    docker compose exec -T php bin/oe-console oe:setup:demodata
```

**Reorder**: keep `oe:module:activate oe_payments_stripe_wallet` (line
155) **after** `oe_payment_component` activation. Sprint I §90 already
specified this ordering — the patch above puts PC's activation in
front of Stripe's at the workflow level, with no further reordering
needed.

### G.2 — payment-component `.github/workflows/development.yml`

Three new steps between `Install dependencies` and `Reset database`:

```yaml
- name: Re-dump autoload after OXID module install
  run: |
    docker compose exec -T php composer dump-autoload --optimize --no-interaction

- name: Smoke-test oe-console boot              # Probe 2
  run: docker compose exec -T php bin/oe-console list > /dev/null

- name: Install + activate payment-component module
  run: |
    docker compose exec -T php bin/oe-console oe:module:install /var/www/test-module
    docker compose exec -T php bin/oe-console oe:module:activate oe_payment_component
```

(In PC's CI the package itself is mounted at `/var/www/test-module`,
so `oe:module:install` points there instead of `vendor/`.)

### G.3 — exit criteria for Phase G

| Probe | Expected after Phase G |
|---|---|
| 1 — `UnifiedNamespaceClassmapTest` | green in both repos' `styles` job |
| 2 — `bin/oe-console list` smoke step | exit 0 in `install_shop_with_module` |
| 3 — `PaymentPanelRegistryIntegrationTest` | green in `integration_tests` |

If Probe 2 still fails after the autoload re-dump, the diagnosis in
report §3 was incomplete and we drop down to the second diagnostic
thread in report §4 ("source/composer.json autoload changes") before
shipping anything else.

## Phase R2 (REFACTOR) — apply the SOLID lens

Run only after Phase G is green on a feature branch.

### R2.1 — DRY the per-module install/activate pattern

Replace the inline `oe:module:install + oe:module:activate` block with
a small shell helper at the top of the
`install_shop_with_module` job:

```yaml
- name: Define install_module helper
  run: |
    cat <<'EOF' >/tmp/install_module.sh
    #!/usr/bin/env bash
    set -euo pipefail
    module_id="$1"
    vendor_path="$2"
    docker compose exec -T php bin/oe-console oe:module:install "${vendor_path}"
    docker compose exec -T php bin/oe-console oe:module:activate "${module_id}"
    EOF
    chmod +x /tmp/install_module.sh
```

Each module activation then becomes one line:

```yaml
- name: Install + activate payment-component
  run: /tmp/install_module.sh oe_payment_component /var/www/vendor/oxid-esales/payment-component

- name: Install + activate stripe-wallet
  run: /tmp/install_module.sh oe_payments_stripe_wallet /var/www/test-module
```

This is intentionally **not** a GitHub composite action — Sprint I
explicitly rejected speculative abstraction. The shell helper is
the smallest shape that satisfies SOLID-O for one repo without
introducing a cross-repo dependency.

### R2.2 — promote `Reset database` to its own step in payment-component too

PC's workflow already has a separate `Reset database` step — no
change needed. Cited only to make the symmetry between the two
workflows explicit.

### R2.3 — drop the stale `allow-plugins` entry

In `stripe/composer.json` (the IDE-opened file), remove:

```diff
   "allow-plugins": {
     "oxid-esales/oxideshop-composer-plugin": true,
     "oxid-esales/oxideshop-unified-namespace-generator": true,
-    "oxid-esales/payment-component": true,
     "infection/extension-installer": true
   }
```

The entry was meaningful only while PC was a `composer-plugin`.
Composer ignores it for non-plugin packages but emits a deprecation
notice on `composer update` once strict mode lands in Composer 3.x.
Drop it — but **only after** Probes 1–3 are green on the feature
branch. If a probe goes red after this drop, the order tells us the
allow-list entry was load-bearing in some way we didn't understand.

## Verification — sprint exit checklist

- [ ] Probe 1 (`UnifiedNamespaceClassmapTest`) green in both repos' `styles` job.
- [ ] Probe 2 (`bin/oe-console list` smoke step) exits 0 in `install_shop_with_module`.
- [ ] Probe 3 (`PaymentPanelRegistryIntegrationTest`) green in `integration_tests`.
- [ ] `./bin/pre-commit-check.sh --full` green on stripe-wallet feature branch.
- [ ] PC's equivalent `./bin/pre-commit-check.sh` green on its feature branch.
- [ ] CI run on `OXID-eSales/stripe-wallet/b-7.4.x-ci-fix-sprint-93` all green.
- [ ] CI run on `OXID-eSales/payment-component/b-7.4.x-ci-fix-sprint-93` all green.
- [ ] Both PRs merged into `b-7.4.x` of their respective repos.

## Out of scope (handled in a separate sprint)

- **payment-component `styles` regression** (3 × `lsp.concreteClassParameter`
  in `Admin/Contract/AdminActionDispatcherInterface.php` + unused
  `HandlesCheckoutReturn` trait). Tracked in
  `../reports/01-ci-broken-after-unification.md` §5. Either widen
  the dispatch interface to take an order interface, or baseline the
  three errors while the panel-tab API stabilises. **Not** Sprint 93.
- Backporting the same workflow patches to `paypal`, `opalreturns`,
  `one-page-checkout`. Sprint I prescribed it for all four; this
  sprint lands two. The remaining two are mechanical copy-paste; one
  follow-up sprint per repo.
- Switching from inline `composer require` + `composer update` to a
  generated `composer.lock` checked into CI fixtures. Future
  optimization, not Sprint 93.

## Deliberately NOT in scope

- **Reverting payment-component to `composer-plugin`.** Off the
  table — see the project memory note.
- **A reusable GitHub composite action** at `OXID-eSales/.github/`
  level. Sprint I explicitly rejected speculative cross-repo
  abstractions; revisit only after a third PSP module copy-pastes
  the same workflow patch.
- **Touching `bootstrap.php`** in oxideshop_ce. The line that fatals
  is correct PHP — we are not the upstream patch surface.
- **Module activation order checks beyond CI.** Local dev runbooks
  and prod deploys still rely on operators following Sprint I §91–92.
  An automated guard would be its own sprint.

## Risks & mitigations

| Risk | Mitigation |
|---|---|
| The autoload re-dump alone doesn't fix Probe 2 | Phase G exit criteria force a stop and a fresh diagnostic before continuing — see "If autoload re-dump alone doesn't unblock CI" in `../reports/01-ci-broken-after-unification.md` §4. |
| `oe:module:install /var/www/vendor/...` fails because the OXID composer plugin already symlinked the module into `source/modules/oe_payment_component/` | Use the `source/modules/oe_payment_component` path instead; OXID accepts either. Confirmed by the existing line 359 (stripe workflow) using `./test-module`. |
| Probe 3 needs the full container, slowing `pre-commit-check.sh` | Keep it in the `Integration` testsuite, not `Unit`. `pre-commit-check.sh` runs Unit by default, `--full` runs both — same behaviour as today. |
| Removing `allow-plugins.oxid-esales/payment-component` (R2.3) regresses something | Land R2.3 as a **separate commit** on the same feature branch so we can revert it without losing G.1/G.2. |

## Migration cost

| Env | Steps |
|---|---|
| CI (per consumer repo) | 5 lines added: re-dump, smoke, install, activate, split demodata. ~2 min total wall-clock added to `install_shop_with_module`. |
| Local dev shops | None — once the workflow is green, `make up` and Sprint I's local runbook continue to work. |
| Staging / prod | None — the prod install path already runs `oe:module:install + oe:module:activate` from the deploy script (per Sprint I §91–92). Sprint 93 is purely a CI catch-up. |
