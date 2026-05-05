# Report 01 — CI broken after the "unification" commits in stripe-wallet and payment-component (2026-05-05)

## 1. The two failing runs

| Repo | Last green run | Last green commit | First failing run | First failing commit |
|---|---|---|---|---|
| `OXID-eSales/stripe-wallet` | run 504 (`success`) at 2026-04-23 07:21 UTC | `14c14fd4` ("unification") | run 505 (`failure`) at 2026-04-23 14:39 UTC, then 506 / 507 | `bdb45862` ("unification"), then `5279bbd8` ("STRP-120 …") — current HEAD of `b-7.4.x-return-lang-STRP-120` |
| `OXID-eSales/payment-component` | run 102 (`success`) at 2026-04-22 12:08 UTC | `9fcd4937` ("event system unification") | run 103 (`failure`), then 104 / 105 | `db5d3506` ("unification"), pushed 2026-04-23 14:33 UTC |

The two failing runs cited in the task description:

- stripe-wallet — <https://github.com/OXID-eSales/stripe-wallet/actions/runs/25324055374> (run 507, head `5279bbd8`)
- payment-component — <https://github.com/OXID-eSales/payment-component/actions/runs/25312862181> (run 105, head `db5d3506`)

Both repos broke on the same calendar day (2026-04-23) and now share the
same failure mode.

## 2. Failure mode — identical in both repos

In both workflows the `install_shop_with_module` job fails at the first
step that invokes `bin/oe-console` (in stripe-wallet that step is named
`Install demodata`, in payment-component it is named `Reset database`).
The job log shows:

```text
Run docker compose exec -T php bin/oe-console oe:database:reset \
  --db-host=mysql --db-port=3306 --db-name=example --db-user=root --db-*** --force
…
##[error]Process completed with exit code 255.
```

with no stdout/stderr from `oe-console` itself — total elapsed ~250 ms.
Exit 255 plus the silent stdout is "PHP died at boot". The actual error
is captured in the artifact `data/php/logs/error_log.txt` uploaded by
the workflow's `Upload configuration artifacts` step. **Both artifacts
contain the exact same trace:**

```text
[04-May-2026 14:16:00 UTC] PHP Fatal error: Uncaught Error: Class
  "OxidEsales\Eshop\Core\ConfigFile" not found in /var/www/source/bootstrap.php:184
Stack trace:
#0 /var/www/bin/oe-console(54): require_once()
#1 {main}
  thrown in /var/www/source/bootstrap.php on line 184
```

Line 184 of `source/bootstrap.php` is:

```php
$configFile = new \OxidEsales\Eshop\Core\ConfigFile(OX_BASE_PATH . "config.inc.php");
```

`OxidEsales\Eshop\…` is the **virtual unified-namespace** that
`oxid-esales/oxideshop-unified-namespace-generator` emits on every
composer install/update. Its absence at runtime means either the
generator did not actually emit the class file for `Eshop\Core\ConfigFile`,
or the generated files are not on the autoload path by the time
`oe-console` boots.

## 3. Root cause

The breaking change is in payment-component commit
`db5d3506932ad1e9ca47ebe3b37f9658aa2e9803` ("unification"), specifically
its `composer.json` rewrite:

```diff
 {
     "name": "oxid-esales/payment-component",
-    "description": "Provider-agnostic payment component with smart-contract architecture",
-    "type": "composer-plugin",
+    "description": "Provider-agnostic payment component with smart-contract architecture and shared admin Payment tab",
+    "type": "oxideshop-module",
     "require": {
         "php": "^8.1",
-        "composer-plugin-api": "^2.0",
         …
     },
-    "extra": {
-        "class": "OxidEsales\\PaymentComponent\\Composer\\MigrationPlugin"
-    }
+    "extra": {
+        "oxideshop": {
+            "target-directory": "oe_payment_component"
+        }
+    }
 }
```

The same commit also deletes `src/Composer/MigrationPlugin.php` (149
lines), which previously subscribed to `POST_INSTALL_CMD` /
`POST_UPDATE_CMD` and ran payment-component's Doctrine migrations from
`migration/migrations.yml`.

Why this kills the shop bootstrap in CI:

1. Both stripe-wallet's and payment-component's `development.yml`
   workflows pin the dependency as
   `composer require oxid-esales/payment-component:dev-b-7.4.x`. The
   moment the `b-7.4.x` branch of payment-component advanced from
   `9fcd4937` to `db5d3506` (force-push at 2026-04-23 14:33 UTC), every
   subsequent CI run started consuming the new package layout —
   regardless of which commit each consumer repo was on.

2. The `oxid-esales/oxideshop-composer-plugin` discovers
   `type: oxideshop-module` packages and runs an additional install
   hook for them. The CI logs confirm this — the new `Installing
   module oxid-esales/payment-component package.` line appears on every
   failing run (e.g. `/tmp/job-148.log:1260`) and never appeared on
   green runs.

3. After this hook, the unified-namespace-generator's post-install
   script still prints `Generating OXID eShop unified namespace
   classes ... Done`, but its output is no longer findable by the
   bootstrap autoloader. The most plausible mechanism — given that the
   "Done" message lies just before composer's `audit` and before the
   generator's classmap is dumped — is that the
   `oxid-esales/oxideshop-composer-plugin` "Install module" step for
   payment-component runs **after** the namespace-generator's
   post-install hook and triggers an autoload re-dump that does not
   include the generator's output (the generator's classes live under
   `vendor/oxid-esales/oxideshop-unified-namespace-generator/Generated/`
   and rely on a classmap entry that the OXID composer plugin's
   second-pass dump can drop). When `oe-console` boots, the
   `Eshop\Core\ConfigFile` autoload entry simply isn't there.

   A secondary contribution: the deleted `MigrationPlugin` was
   activated very early in composer's plugin lifecycle (because
   `composer-plugin` packages activate before non-plugin packages). It
   participated in the post-install graph; removing it changes the
   order of `POST_INSTALL_CMD` listeners, which is what controls
   whether the namespace classmap is dumped before or after the OXID
   plugin's module-install pass.

The greens reproduce the inverse: in run 502 / 503 / 504 the
`b-7.4.x` HEAD of payment-component was still `9fcd4937` and the
package was a `composer-plugin`; the OXID composer plugin saw nothing
to copy under `source/modules/oe_payment_component/`, the
namespace-generator's output stayed on the autoload classmap, and
`oe-console` booted normally.

## 4. Fix path

`type: oxideshop-module` on payment-component is **deliberate and load-bearing**:
the package now ships the unified "Payment" admin tab
(`PaymentAdminController` + `views/twig/admin/payment_admin_tab.html.twig`,
registered through its own `metadata.php` and `menu.xml`). Reverting to
`composer-plugin` is off the table. The fix has to make CI work with
the new package layout — there is no "go back".

The bootstrap dies because, at the moment `bin/oe-console` is first
invoked, the autoload classmap doesn't carry the
`oxideshop-unified-namespace-generator` output yet. The OXID composer
plugin's "Install module" pass for `oe_payment_component` runs after
the namespace-generator's post-install script (visible in the log
ordering: `Installing module oxid-esales/payment-component package.`
appears before `Generating OXID eShop unified namespace classes ...
Done` on the broken runs, but the autoload dump that ties them
together doesn't fire). The smallest, lowest-risk patch is to force
the dump before the first `oe-console` call.

### Patch — both workflows

In `OXID-eSales/stripe-wallet/.github/workflows/development.yml`,
insert a step between `Install dependencies, reset shop, activate theme`
and `Install demodata`:

```yaml
- name: Re-dump autoload after OXID module install
  run: |
    docker compose exec -T php composer dump-autoload --optimize --no-interaction
```

In `OXID-eSales/payment-component/.github/workflows/development.yml`,
insert the same step between `Install dependencies` and
`Reset database`. Same one-liner.

This is purely a CI change — no shop / module / packagist surgery
needed, the change is reversible, and a single feature branch on each
repo will tell us within one CI run whether the diagnosis is correct.

### If the autoload re-dump alone doesn't unblock CI

Two adjacent things to check, in order:

1. **Activate `oe_payment_component` explicitly.** Right now the
   stripe-wallet workflow only activates `oe_payments_stripe_wallet`
   (line 155 of the workflow). Now that payment-component is a real
   OXID module with its own `metadata.php`, services.yaml,
   menu.xml and a `PaymentAdmin` controller, it almost certainly
   needs `oe-console oe:module:activate oe_payment_component` before
   stripe activates — otherwise the `oe.payment.admin_panel`
   tagged-iterator collected by `PaymentPanelRegistry` will be empty
   at runtime and the shared Payment tab will render with no body.
   This is a runtime concern, not the bootstrap one — but it has to
   land in the same workflow patch so the post-bootstrap state
   matches the new architecture.

2. **`source/composer.json` autoload changes.** If the
   namespace-generator's classmap stays out of the dumped autoload
   even after `composer dump-autoload --optimize`, the next thing to
   inspect is whether the OXID composer plugin's "Install module"
   pass writes to `source/composer.json` in a way that drops the
   generator's classmap entry. Diff
   `/tmp/ci-artifact/.../source/composer.json` (broken) against the
   same file from the last green run (artifact name
   `InstallShopWithModules-8.2-5.7-twig` from run 504) — if the
   `extra.installer-paths` or autoload entries differ, that's the
   next thread to pull.

### Memo for whoever lands this

Don't drop the `allow-plugins.oxid-esales/payment-component: true`
entry from the consumer composer.json yet. It's a leftover from
when payment-component was a `composer-plugin`, but composer's
plugin allow-list is checked at install time and removing the entry
in the same patch as the type-flip would mask whether the bootstrap
break is autoload-related or plugin-allow-list related.

## 5. Secondary failure — payment-component `styles` job

Independently of the bootstrap break, the payment-component `styles
(8.3)` job is also red on `db5d3506` (run 105 above):

```text
PHPStan
  Admin/Contract/AdminActionDispatcherInterface.php:35  lsp.concreteClassParameter
  Admin/Contract/AdminActionDispatcherInterface.php:40  lsp.concreteClassParameter
  Admin/Contract/AdminActionDispatcherInterface.php:45  lsp.concreteClassParameter
  Controller/HandlesCheckoutReturn.php:28               trait.unused
[ERROR] Found 4 errors
```

The first three are payment-component's own custom rule
`NoConcreteClassTypeHintRule` flagging that
`AdminActionDispatcherInterface::dispatchRefund/Capture/Cancel` accept a
concrete `OxidEsales\Eshop\Application\Model\Order` instead of an
order interface. The trait warning is a PHPStan default rule —
`HandlesCheckoutReturn` was added in this commit but isn't `use`-d by
any class yet.

This is unrelated to the bootstrap regression and should be fixed in
the new admin-tab code: either narrow the type to an order interface
(if one exists in oxideshop_ce — there isn't a public one for `Order`,
so realistically a baseline entry in
`tests/PhpStan/phpstan-baseline.neon` is the pragmatic choice until
the wider Liskov refactor lands), and either start `use`-ing
`HandlesCheckoutReturn` from the new `PaymentAdminController` or
delete the trait if it was speculative.

## 6. Evidence inventory

- Failing job log (stripe-wallet): `gh api /repos/OXID-eSales/stripe-wallet/actions/jobs/74240084148/logs` → 1572 lines; the relevant slice is between the `Install demodata` group at line 1271 and the exit-255 marker at line 1285.
- Failing job log (payment-component): `gh api /repos/OXID-eSales/payment-component/actions/jobs/74203588525/logs` → relevant slice 1173–1187.
- Bootstrap error trace (both repos): downloaded via
  `gh run download 25324055374 --repo OXID-eSales/stripe-wallet` and
  `gh run download 25312862181 --repo OXID-eSales/payment-component`,
  artefact path `…/data/php/logs/error_log.txt` — five lines, identical
  except for the timestamp.
- Composer.json delta in payment-component:
  `git -C source/extensions/payment-component diff 9fcd4937 db5d3506 -- composer.json`.
- Run-to-commit mapping: `gh api /repos/OXID-eSales/<repo>/actions/runs?per_page=20`.
