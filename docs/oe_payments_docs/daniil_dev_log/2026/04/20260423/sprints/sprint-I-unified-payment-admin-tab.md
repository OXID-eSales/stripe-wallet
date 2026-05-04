# Sprint I — unified "Payment" admin tab owned by payment-component

_Branch (all repos): `b-7.4.x-unification-epoch`.
Dev log: continues from `../../20260422/status.md`._

## Motivation (what's wrong today)

Each PSP module ships its own admin order tab. `stripe/menu.xml`
registers `cl=OrderRefund`; `paypal/menu.xml` registers
`cl=PayPalOrderRefund`; each with its own:

- admin controller extending `AdminDetailsController`,
- view data provider,
- twig template (1-2 files, 180–450 lines each),
- menu.xml entry,
- controller registration in `metadata.php`,
- localization strings (`*_NOT_A_*_ORDER`, card titles, headers).

Cost paid repeatedly today (2026-04-22 → 2026-04-23):

- Missing `<form id="transfer">` in `paypal_order_refund.html.twig`
  dead-ended tab navigation (Task 3, 2026-04-23).
- Missing `bottomnaviitem` / `bottomitem` includes left
  `</body></html>` absent in PayPal's tab (Task 3).
- Stripe's template had a full-screen spinner overlay + 57-line
  JS monkey-patch on `top.reloadEditFrame` that we just deleted
  (Task 1).
- Stripe's template had no friendly notice for non-Stripe
  orders; PayPal's did — asymmetry (Task 2).
- 120+ lines of CSS duplicated between the two PSP templates
  (`.s-card`, `.s-alert-*`, `.s-btn-*` vs `.paypal-card`,
  `.paypal-alert-*`).

Each of these is a separate bug caused by duplicated
infrastructure. Every new PSP would repeat the same mistakes.

## Target

**One** admin tab labeled "Payment", owned by
`payment-component`. PSP modules inject a panel-provider service
that renders the PSP-specific block (dashboard link, provider
order id, capture/refund/cancel forms). The shared
infrastructure (header, not-a-PSP-order notice, transfer form,
admin layout includes, styles) lives in payment-component and is
written once.

### Prerequisite: promote payment-component from library to module

Today payment-component is a `composer-plugin` package in
`vendor/oxid-esales/payment-component/`. OXID's admin menu
loader reads `menu.xml` **only** from installed modules
(`source/modules/<id>/` after `oe:module:install`). A library in
`vendor/` cannot register an admin tab.

Since Sprint I's whole premise is "PC owns the Payment tab",
**payment-component must ship as an OXID module**. This decision
is load-bearing — no workaround (e.g. each PSP duplicating the
menu.xml entry) is acceptable; we chose symmetry with the other
modules in the epoch over convenience of keeping PC as a
dependency-only package.

**Concrete change in payment-component:**

- `composer.json`:
  - `"type": "composer-plugin"` → `"type": "oxideshop-module"`.
  - Remove composer-plugin class wiring if any.
  - Add `extra.oxideshop.source-directory`, `extra.oxideshop.target-directory` as the other PSP modules do.
- New `metadata.php`:
  - `id: oe_payment_component` (the module id activation commands reference)
  - `title`, `description` (de + en), `version`, `author`, `url`
  - `extend`: empty unless we extend an OXID core class (expected empty)
  - `controllers`: `['PaymentAdmin' => PaymentAdminController::class]` **only if** services.yaml's `oxid.view_controller` tag doesn't resolve on its own (test both; prefer tag-only, fall back to controllers-array if OXID's controller factory insists)
  - `templates`: `['@oe_payment_component/admin/payment_admin_tab' => 'views/twig/admin/payment_admin_tab.html.twig']`
  - `events`: `['onActivate' => …, 'onDeactivate' => …]` — already needed for migrations (existing migrations stay wired exactly as they are today; event-hook classes remain in PC's src)
  - `settings`: none (PC has no admin-editable config today)
- New `menu.xml`: the single `<TAB id="PAYMENT_ADMIN_TAB" cl="PaymentAdmin" />` under `MAINMENU id="mxorders"` / `SUBMENU id="mxdisplayorders"` (mirrors Stripe's + PayPal's existing placement — the tab appears in the same spot the old per-PSP tabs do).
- Existing `services.yaml` gets the new admin wiring (controller, registry, dispatcher, tagged iterator `oe.payment.admin_panel`). No move needed — this stays where PC already defines services.
- `bin/pre-commit-check.sh` already exists in PC and keeps passing.

**Install-flow changes outside PC:**

1. **CI workflows** in all four modules (`stripe`,
   `paypal`, `opalreturns`, `one-page-checkout`) already run
   `composer require oxid-esales/payment-component:${BRANCH}` for
   pinning. Add one step after that:
   ```yaml
   - docker compose exec -T php bin/oe-console oe:module:install /var/www/vendor/oxid-esales/payment-component
   - docker compose exec -T php bin/oe-console oe:module:activate oe_payment_component
   ```
   This runs **before** `oe:module:activate oe_payments_stripe_wallet` (etc.) so PC's `oxid.view_controller` tag is in the compiled container when PSPs are loaded.
2. **Local dev runbook** (committed in PC's README): after
   pulling, `make up` → `docker compose exec php bin/oe-console
   oe:module:install /var/www/vendor/oxid-esales/payment-component
   && bin/oe-console oe:module:activate oe_payment_component`.
3. **Smoke test** in PC's `bin/pre-commit-check.sh`: add the
   standard module-activation round-trip (deactivate →
   activate → cache-clear), same as opalreturns got in
   Sprint H.
4. **Module-activation ordering guard**: opalreturns, Stripe,
   PayPal, OPC all declare in their `composer.json` `require` or
   `suggest` a reminder that `oe_payment_component` must be
   activated first. The epoch's existing `composer require
   oxid-esales/payment-component:${BRANCH}` already pins the
   version; what's missing is documenting the
   activation order in each module's README.

**Migration cost (one-time, documented):**

| Env | Steps |
|---|---|
| CI (every workflow) | 2 lines added after payment-component install step |
| Local dev shops | One-line `oe:module:install + oe:module:activate oe_payment_component` after pulling the sprint branch |
| Staging / prod | Same one-line, coordinated with the deploy that ships the sprint branch |

**Non-changes / explicit invariants:**

- Zero runtime behaviour change in PC's event system, broker,
  handlers, or DB migrations. Only the admin tab surface + module
  packaging move.
- PC stays a singleton dependency — Stripe / PayPal / OPC /
  opalreturns all `require` it. The composer-type change from
  `composer-plugin` to `oxideshop-module` affects `composer
  install` symlinking (module lands in `source/modules/`) but
  doesn't change the namespace or autoload paths PSPs reference.
- The registry's zero-providers path still renders the
  "no registered provider" notice — the tab appears on day 1
  even before any PSP ships its panel provider.

Architecture:

```
menu.xml (payment-component module)
  → <TAB cl="PaymentAdmin" id="PAYMENT_ADMIN_TAB" />

payment-component/src/Admin/
  ├─ PaymentAdminController.php          # the one OXID admin controller
  ├─ PaymentPanelRegistry.php            # collects tagged providers
  └─ Panel/
       ├─ PaymentPanelProviderInterface.php
       └─ PaymentPanelRenderable.php     # DTO: template + view data

payment-component/views/twig/admin/
  └─ payment_admin_tab.html.twig         # header + body slot + closes

stripe/src/Stripe/Admin/
  └─ StripePaymentPanelProvider.php      # tagged 'oe.payment.admin_panel'
       (renders Stripe body into the shared slot)

paypal/src/PayPal/Admin/
  └─ PayPalPaymentPanelProvider.php      # same tag, PayPal body
```

Routing: `PaymentAdminController::render()`:

1. Load the order.
2. Lookup contract via `ContractRepositoryInterface::findByOrderId`.
3. Ask `PaymentPanelRegistry::resolveFor($order, $contract)` for the
   active provider.
4. If none supports → render the shared "This order was not paid
   through a registered payment provider" notice.
5. Else → collect `PaymentPanelRenderable` from the provider and
   render it inside the wrapper.

Action dispatching: PSP panels emit forms that POST to the
shared controller `fnc=…`. Shared controller dispatches the
existing PSP-specific events (`StripeRefundRequestEvent`,
`PayPalRefundRequestEvent`, etc.) without knowing which one —
it delegates `handleAction($actionName)` to the active panel
provider, which translates to its PSP event.

## Deliberately NOT in scope

- **No new events**. Reuse
  `Stripe{Refund,Capture,CancelAuthorization}RequestEvent` and
  `PayPal{…}Event` unchanged. The broker already abstracts
  them via `AbstractProviderRequestEvent`; don't re-abstract at
  the admin layer.
- **No deprecated-compat shims**. `cl=OrderRefund` /
  `cl=PayPalOrderRefund` routes get removed in the same PR.
- **No new DB tables**, migrations, or config flags.
- **No generic "PaymentAdmin" base class** that PSP panels
  extend. Providers are plain services implementing one interface
  — no inheritance across module boundaries (per the epoch's
  "Stripe and PayPal are peers, share payment-component only"
  invariant).
- **Don't ship a fallback to the old tabs**. If Sprint I lands
  in prod while a PSP module hasn't shipped its panel provider,
  the shared notice covers it gracefully.
- Admin menu translation string upkeep for German/English only
  (others handled by the standard translation pipeline).

## Core engineering requirements — how each applies

**TDD-first.**
Every new file has a failing unit test before implementation.
Red→green→refactor. Test fixtures and concrete plans below.

**SOLID.**
- **S** — `PaymentPanelProviderInterface` does one thing:
  render a panel for one PSP. `PaymentAdminController` orchestrates.
  `PaymentPanelRegistry` resolves. No class mixes these concerns.
- **O** — new PSPs add a provider + tag. Zero payment-component
  changes. Closed for modification, open for extension.
- **L** — every provider returns `PaymentPanelRenderable` with
  the same contract (template path + view data array + optional
  dashboard url). No downcasting.
- **I** — the interface has 3 methods: `supports()`, `build()`,
  `handleAction($name, $request)`. Nothing wider.
- **D** — controller → `PaymentPanelRegistryInterface`.
  Registry → iterable of provider interfaces. No concrete type
  in constructor signatures on either side.

**DI.**
- All services constructor-injected via interfaces.
- Registry uses Symfony tagged iterator (`oe.payment.admin_panel`).
- Controller registered as `oxid.view_controller` tagged
  service with `controller_key: PaymentAdmin` — bypasses the
  class-chain pitfall that bit Stripe/PayPal on `cl=order`.
- No `oxNew()` on services. `ContainerFacade::get` only at the
  `ViewConfig`/controller boundary if absolutely needed.

**DRY.**
- Transfer form, head/bottom admin includes, not-a-PSP-order
  notice, `stripe-admin` / `paypal-card` CSS consolidation → all
  in one wrapper template.
- Contract-header block (contract id, state, amount, captured,
  refunded) rendered once in payment-component. Providers only
  emit the PSP-specific delta.
- Shared action-error / action-success surface — one alert
  styling, both PSPs.

**Liskov.**
- `PaymentPanelProviderInterface::build()` always returns
  `PaymentPanelRenderable` (never null, never an alternate
  shape). Negative case is expressed by `supports() === false`,
  not by returning a sentinel from `build()`.

**Clean code.**
- 15–25 line methods. Early returns. No `else`. Small, named
  helpers when logic exceeds 25 lines.
- No PHPMD-visible complexity in the controller — long
  action-dispatch switch extracted into `ActionDispatcher`
  mapped by name.

**No overengineering.**
- 3 new files in payment-component
  (controller + registry + interface), 1 template, 1 DTO.
- 1 new file per PSP (the panel provider).
- Zero new events, zero new DB columns, zero new config flags.

**Drop deprecated.**
- Delete in the same PR:
  - `stripe/src/Stripe/Controller/Admin/OrderRefund.php`
  - `stripe/src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php`
  - `stripe/views/twig/admin/stripe_order_refund.html.twig`
  - `stripe/views/twig/admin/stripe_connect.html.twig` stays —
    different tab, different controller
  - Stripe `menu.xml` admin-tab entry for `OrderRefund`
  - Stripe `metadata.php` `controllers['OrderRefund']` + template entry
  - PayPal equivalents (`OrderRefund.php`,
    `OrderRefundViewDataProvider.php`,
    `paypal_order_refund.html.twig`, menu.xml entry, metadata.php)
  - Translation keys that are now dead: `STRIPE_NOT_A_STRIPE_ORDER`
    (just added yesterday — gone again), `PAYPAL_NOT_A_PAYPAL_ORDER`,
    per-PSP section titles now owned by payment-component.

**pre-commit-check.sh green** in all three repos (`payment-component`,
`stripe`, `paypal`) before the PR is considered landable.

## Lessons from the last three weeks, baked in

These are the concrete anti-patterns this sprint must avoid (each
line already cost us a bug):

1. **Admin templates must ship the transfer form + admin layout
   closes.** The wrapper template in payment-component emits
   both — providers only ever inject the body slot. Regression
   test: PHPUnit render test asserts `<form id="transfer">`,
   `</body>`, `</html>` are present.
   (Today's Task 3.)

2. **Never override `getViewData()`.** The shared controller
   uses a distinct name (`getPanelViewData()`) and attaches the
   provider's DTO there. Regression test: assert `getViewData()`
   is NOT defined on `PaymentAdminController`.
   (Memory: `feedback_oxid_admin_tab_viewdata.md`.)

3. **`controller_key: order` collides.** This sprint registers
   `controller_key: PaymentAdmin` — a name nothing else uses.
   Don't tag `order` again.
   (Memory: `feedback_oxid_controller_routing.md`.)

4. **No `oxNew()` on services.** Constructor-inject everything.
   `oxNew(Order::class)` stays in the controller boundary only,
   where OXID forces it.
   (Memory: `feedback_oxid_no_oxnew_for_controllers.md`.)

5. **Gate twig template extensions on `oView.getEditObjectId()`**
   rather than `attribute(oView, 'foo') is defined`.
   (Memory: `feedback_oxid_admin_crossmodule_templates.md`.)

6. **Services that iterate tagged providers must handle zero
   providers.** If no PSP module is installed, panel registry
   returns `null` → wrapper renders the generic notice. Test
   case explicit.
   (Memory: `feedback_symfony_di_autowire_sweep.md`.)

7. **After PHP class changes, restart php.** Pre-commit test
   suite runs inside docker; smoke test in CI does
   `docker compose restart php` before asserting activation.
   (Memory: `feedback_php_opcache_fpm.md`.)

8. **No `@deprecated` window, no compat shim.** The old
   `OrderRefund` controllers and their tab entries are deleted
   in the same PR as the new one. Pre-commit grep guard: no
   file matching `src/.*Controller/Admin/OrderRefund\.php$`.

9. **Provider-agnostic at the broker layer.** `opalreturns` (from
   Sprint H) already proves this works for refunds. Admin panel
   provider uses the same event-broker pattern for capture /
   cancel / refund actions — it dispatches PSP-specific events
   the handler already listens to. Don't rebuild the bus.

10. **Drop CSS duplication but keep visual parity.** The
    wrapper template ships one `.pc-card`, `.pc-alert`, `.pc-btn`
    system. PSPs don't add their own CSS unless they paint a
    PSP-unique element (e.g. Stripe's purple dashboard link,
    PayPal's sandbox badge) — and those are scoped via a
    provider-emitted `data-provider="stripe|paypal"` attribute
    the wrapper CSS keys off.

## Target file layout

**payment-component (new):**

```
src/Admin/
  Contract/PaymentPanelProviderInterface.php   # 30 lines
  Contract/PaymentPanelRegistryInterface.php   # 15 lines
  PaymentPanelRegistry.php                     # 40 lines
  PaymentAdminController.php                   # 90-110 lines
  PaymentPanelRenderable.php                   # DTO, 25 lines
  PaymentAdminActionDispatcher.php             # 50-60 lines

views/twig/admin/
  payment_admin_tab.html.twig                  # ~120 lines
                                               # wrapper: head, transfer form,
                                               # contract-header card, slot,
                                               # bottomnaviitem, bottomitem
views/admin_twig/{de,en}/payment_admin_lang.php   # 15 keys

metadata.php (new file)                        # module id: oe_payment_component
menu.xml (new file)                            # one <TAB id="PAYMENT_ADMIN_TAB">
services.yaml                                  # new entries for the above

composer.json                                  # type: composer-plugin → oxideshop-module
                                               # adds extra.oxideshop.{source,target}-directory
bin/pre-commit-check.sh                        # adds module-activation smoke (dea→act→cache-clear)
```

**stripe (modified):**

```
DELETE: src/Stripe/Controller/Admin/OrderRefund.php
DELETE: src/Stripe/Controller/Admin/OrderRefundViewDataProvider.php
DELETE: views/twig/admin/stripe_order_refund.html.twig
DELETE: menu.xml tab entry, metadata.php controllers entry + template alias
DELETE: STRIPE_NOT_A_STRIPE_ORDER translation (EN + DE)

ADD:
  src/Stripe/Admin/StripePaymentPanelProvider.php          # 80-100 lines
  views/twig/admin/panel/stripe_panel.html.twig            # ~150 lines
                                                           # body-only, no wrapper
MODIFY:
  services.yaml — register provider with 'oe.payment.admin_panel' tag
```

**paypal (modified):**

```
DELETE: src/PayPal/Controller/Admin/OrderRefund.php
DELETE: src/PayPal/Controller/Admin/OrderRefundViewDataProvider.php
DELETE: views/twig/admin/paypal_order_refund.html.twig
DELETE: menu.xml tab entry, metadata.php controllers entry + template alias
DELETE: PAYPAL_NOT_A_PAYPAL_ORDER translation

ADD:
  src/PayPal/Admin/PayPalPaymentPanelProvider.php          # 60-80 lines
  views/twig/admin/panel/paypal_panel.html.twig            # ~70 lines

MODIFY:
  services.yaml
```

## TDD phases — concrete test list (red first)

### payment-component (7 red tests)

1. `PaymentPanelRegistryTest::testReturnsNullWhenNoProvidersRegistered()`
2. `PaymentPanelRegistryTest::testReturnsNullWhenNoProviderSupportsOrder()`
3. `PaymentPanelRegistryTest::testReturnsFirstSupportingProviderByPriority()`
4. `PaymentAdminControllerTest::testRendersNotConfiguredNoticeWhenNoProvider()`
5. `PaymentAdminControllerTest::testDelegatesBuildToActiveProvider()`
6. `PaymentAdminControllerTest::testRejectsActionWithoutCsrfToken()`
7. `PaymentAdminActionDispatcherTest::testDispatchesActionToActiveProvider()`

Plus 1 twig render test covering the `</body></html>` +
`<form id="transfer">` invariants (learned from Task 3).

### stripe (5 red tests)

1. `StripePaymentPanelProviderTest::testSupportsOrderByPaymentType()`
2. `StripePaymentPanelProviderTest::testSupportsReturnsFalseForOtherProviders()`
3. `StripePaymentPanelProviderTest::testBuildReturnsRenderableWithDashboardUrl()`
4. `StripePaymentPanelProviderTest::testHandleRefundDispatchesStripeRefundEvent()`
5. `StripePaymentPanelProviderTest::testHandleCaptureDispatchesStripeCaptureEvent()`

### paypal (5 red tests)

Same shape, swapping "Stripe" for "PayPal". Capture / refund /
cancel-auth covered.

### Cross-module integration (1 test, lives in payment-component)

`AdminTabIntegrationTest::testRoutesStripeOrderToStripePanel()` —
spin up the registry with both PSP providers, resolve for a
Stripe-tagged order, assert `stripe_panel.html.twig` path is
returned. Repeat for PayPal.

### Stripe admin test suite — must be updated, not dropped

Most specs in `stripe/tests/e2e/playwright/playwright/tests/admin/`
currently drive `cl=OrderRefund` against Stripe's now-deleted
controller + template. They carry real regression coverage for
refund / capture / cancel-authorization flows we cannot afford to
lose. Default action: **port** — keep assertions, retarget
navigation onto the new shared `cl=PaymentAdmin` tab and the
Stripe panel's selectors.

| Spec | Action |
|---|---|
| `stripe-admin-order.spec.ts` | Port — retarget "Stripe" tab locator to the shared "Payment" tab; keep transaction-ID / payment-type assertions against the new Stripe panel DOM. |
| `stripe-admin-capture.spec.ts` | Port — `#captureForm` selector survives; the wrapping frame id/key changes. |
| `stripe-admin-refund.spec.ts` | Port — same shape as capture. |
| `stripe-partial-refund.spec.ts` | Port — remaining-refundable assertion moves to panel-emitted block. |
| `stripe-manual-capture-fix.spec.ts` | Port — manual-capture behaviour test still valid on the new tab. |
| `stripe-tab-styles.spec.ts` | **Delete** — asserts `.stripe-admin` CSS classes that no longer exist. Replace with a shared-wrapper style test in payment-component's suite. |
| `stripe-loading-indicator.spec.ts` | **Delete** (already obsolete since Task 1, 2026-04-23). |
| `payment-date-validation.spec.ts` | Port — payment-date rendering moves to the shared contract-header block. |

**Unit-test impact in `stripe/tests/Unit/`:**

- Any test that instantiates `Stripe\Controller\Admin\OrderRefund`
  or `OrderRefundViewDataProvider` → **delete**. The controller
  classes are removed in Commit 3.
- Behaviour worth preserving (refund-amount validation,
  `isOrderCapturable` logic, factual-captured math) migrates
  into the `StripePaymentPanelProvider` and gets new unit tests
  at that level. Coverage must not shrink — net tests removed
  from Stripe should be ≤ net tests added on the provider.
- `TestableOrderRefundForVisibilityTest` pattern → redo against
  `StripePaymentPanelProvider` if the provider has any
  OXID-integrated seams that need stubbing. Prefer constructor
  DI over the testable-subclass pattern where possible.

**Same treatment for PayPal:** every
`paypal/tests/e2e/playwright/tests/admin/*.spec.ts` gets
retargeted; PayPal unit tests touching its `OrderRefund` /
`OrderRefundViewDataProvider` migrate to
`PayPalPaymentPanelProvider`.

**Shared page-object refactor.** `AdminStripeOrderPage.ts` and
the PayPal equivalent become thin wrappers over a new shared
`AdminPaymentTabPage.ts` in payment-component's playwright
suite. Each PSP page-object keeps only PSP-specific helpers
(Stripe dashboard-link locator, PayPal sandbox-badge locator);
tab activation, transfer-form readiness, capture/refund submit
flow come from the shared base — single source of truth.

**DoD for test-migration work (blocker for Commits 3 and 5):**
every ported spec is green against the new shared tab **before**
the old Stripe / PayPal templates and controllers are deleted.
Sequence:

1. Commit 2 — Stripe panel provider lands; old OrderRefund
   still exists.
2. Port Stripe admin specs onto the new tab. Run them; they pass.
3. Commit 3 — delete Stripe's old OrderRefund controller /
   template / menu / metadata entries / translation keys.
   Ported specs still green.

Same order for PayPal (Commits 4 → port → 5).

**Grep guard added to `pre-commit-check.sh` in each PSP module**
(Commit 3 and Commit 5):

```
grep -rn "cl=OrderRefund\|cl=PayPalOrderRefund" tests/ src/ views/
```

must return empty. Enforces the "no compat shim" rule.

### Playwright (2 new specs, 2 deleted)

DELETE:
- `stripe/tests/e2e/playwright/playwright/tests/admin/stripe-loading-indicator.spec.ts`
  (already obsolete since Task 1)
- duplicate admin-tab specs per PSP that overlap coverage

ADD:
- `payment-component/tests/e2e/playwright/tests/admin/payment-admin-tab-routing.spec.ts`:
  opens a Stripe order → asserts Stripe panel body, opens a
  PayPal order → asserts PayPal panel body, opens an invoice
  order → asserts generic notice.
- `payment-component/tests/e2e/playwright/tests/admin/payment-admin-tab-navigation.spec.ts`:
  the regression for Task 3 — clicks between all admin order
  tabs, asserts iframe reloads every time. This is the spec
  whose absence let yesterday's bug ship.

## Migration plan (in one commit series)

1. **Commit 1 — payment-component infra.** New interface,
   registry, controller, DTO, wrapper template, menu.xml,
   metadata.php, services.yaml. All red tests go green.
   - **Commit 1a (landed 2026-04-23)** — infrastructure slice:
     - `src/Admin/Contract/PaymentPanelProviderInterface.php`
     - `src/Admin/Contract/PaymentPanelRegistryInterface.php`
     - `src/Admin/Panel/PaymentPanelContext.php` (DTO)
     - `src/Admin/Panel/PaymentPanelRenderable.php` (DTO)
     - `src/Admin/Panel/UnsupportedPaymentActionException.php`
     - `src/Admin/PaymentPanelRegistry.php`
     - `src/Admin/PaymentAdminActionDispatcher.php`
     - 3 test files covering the above (8 tests, 15
       assertions, all green). PHPStan level max clean
       (added `\Admin\Panel\` to `NoConcreteClassTypeHintRule`
       allowlist — DTOs follow the same value-object
       convention as `\Event\` / `\Return\` / `\Result\`).
     - `./bin/pre-commit-check.sh` green in payment-component.
     - No OXID module scaffolding yet — that lands in Commit 1b.
   - **Commit 1b (pending) — promote payment-component to a
     first-class OXID module:**
     - `composer.json`: `"type": "composer-plugin"` →
       `"type": "oxideshop-module"`. Add
       `extra.oxideshop.source-directory` (relative to package
       root) and `extra.oxideshop.target-directory`
       (`oe_payment_component/`). Verify a fresh
       `composer install` produces the symlink into
       `source/modules/oe_payment_component/` — smoke test
       by running `oe:module:install
       /var/www/vendor/oxid-esales/payment-component`.
     - `metadata.php` (new): module id `oe_payment_component`,
       title + description (de + en), version
       (start at `1.0.0`, bump by existing branch convention),
       author, url; empty `extend`; `templates` alias for the
       wrapper template; `events.onActivate` / `onDeactivate`
       keep the existing migration runner wiring from PC's
       current event classes.
     - `menu.xml` (new): one `<TAB id="PAYMENT_ADMIN_TAB"
       cl="PaymentAdmin" />` under `MAINMENU id="mxorders"` /
       `SUBMENU id="mxdisplayorders"`. Matches Stripe/PayPal's
       current placement for visual parity.
     - `services.yaml` additions: `PaymentPanelRegistry`
       argument `!tagged_iterator oe.payment.admin_panel`;
       `PaymentAdminController` tagged
       `{ name: oxid.view_controller, controller_key: PaymentAdmin }`,
       `public: true`; `PaymentAdminActionDispatcher` wired
       with the registry; new lang files registered via
       `metadata.php`'s default OXID language loading.
     - `views/twig/admin/payment_admin_tab.html.twig`: wrapper
       with `{% include "headitem.html.twig" with {...} %}`,
       the contract-header card, the `<form id="transfer">`
       with `cl="PaymentAdmin"`, the slot for the active
       provider's panel, `{% include "bottomnaviitem.html.twig" %}`,
       `{% include "bottomitem.html.twig" %}`. These are the
       Task 3 (2026-04-23) regression invariants — assert via
       a PHPUnit rendering test.
     - `views/admin_twig/{de,en}/payment_admin_lang.php`:
       `PAYMENT_ADMIN_TAB`, `PAYMENT_NO_PROVIDER_NOTICE`,
       `PAYMENT_CONTRACT_ID`, `PAYMENT_CONTRACT_STATE`,
       `PAYMENT_AMOUNT`, `PAYMENT_CAPTURED`, `PAYMENT_REFUNDED`,
       `PAYMENT_PROVIDER_ORDER_ID`, + action-result
       success/error strings (~12 keys total).
     - `PaymentAdminController.php`: ≤110 lines, pulls the
       order id from request, fetches the contract via the
       existing `ContractRepositoryInterface`, calls the
       registry, renders the wrapper with the provider's
       renderable, handles `fnc=dispatchAction($name)` via
       `PaymentAdminActionDispatcher`. Does **not** override
       `getViewData()` — uses `getPanelViewData()` per memory
       rule.
     - `bin/pre-commit-check.sh`: add the module-activation
       smoke test (`deactivate` → `activate` → `cache-clear`)
       mirroring opalreturns. Blocks commits that break
       activation.
     - Tests:
       - `PaymentAdminControllerTest` — 4 tests: renders
         no-provider notice when registry returns null,
         renders provider's template, handles action,
         rejects action without CSRF.
       - `PaymentAdminTabRenderTest` — asserts output contains
         `<form id="transfer">`, `cl="PaymentAdmin"`,
         `</body></html>` (the Task 3 regression guard).
     - Acceptance for 1b: `oe:module:install + activate
       oe_payment_component` round-trips clean; admin shows an
       empty "Payment" tab under order details with the
       "no registered provider" notice (because no PSP has
       shipped its panel yet).
2. **Commit 2 — Stripe panel provider.** Add the provider +
   body template + Stripe tests green. Old `OrderRefund`
   controller + template still in place — both tabs exist
   temporarily ONLY inside this commit, to keep it rebasable.
3. **Commit 3 — delete Stripe's old tab.** Remove the menu.xml
   entry, controller, view data provider, template, translation,
   metadata.php entries. Stripe pre-commit green.
4. **Commit 4 — PayPal panel provider.**
5. **Commit 5 — delete PayPal's old tab.**
6. **Commit 6 — playwright specs.**

Each commit is self-consistent, reviewable in isolation, passes
pre-commit-check.sh in its own module.

## Risks + mitigations

| Risk | Mitigation |
|---|---|
| `controller_key: PaymentAdmin` collides with something | grep before landing; name chosen because nothing in any of the four modules uses it today |
| Payment-component going from "library" to "module" changes install semantics (composer-type change, `oe:module:install` step in every env) | One-time cost documented per env; CI workflows get 2 new lines; local runbook gets 1 line; migration cost spelled out in the "Prerequisite" section and is judged acceptable for the architectural win of PC owning the tab directly |
| Existing PSPs (Stripe/PayPal/opalreturns/OPC) load order: PC must be active before them, or their panel-provider tags reference missing interfaces | CI runs `oe:module:install + activate oe_payment_component` **before** activating any PSP. Each PSP's composer.json already pins PC to the branch; README docs the order |
| PSPs that already have `PaymentComponent` references (opalreturns Sprint H) expect PC-as-library semantics (autoloaded from vendor) | `oxideshop-module` type keeps vendor autoload intact — classes still ship in `vendor/oxid-esales/payment-component/src/`, only symlinked into `source/modules/oe_payment_component/` on install. No namespace change, no `require` change |
| PSP-specific JS (Stripe.js SDK, PayPal card fields) needs to load only on the active panel | Wrapper template emits only the active panel's body, so its `<script>` tags are scoped to it |
| Action dispatch bypasses admin capture/refund confirmations | Wrapper form carries `ord_agb`-style confirm flag, same UX as today |
| OXID 7.4 controller-as-a-service pitfalls (tag not honored after cache miss) | `oe:module:activate` + restart php in CI smoke; tag visible in compiled container dump (check `cache/container*.php`) |
| One of Stripe / PayPal's existing Playwright admin specs is a load-bearing regression covering Capture/Refund flows | Port the capture/refund assertions into the new shared spec BEFORE deleting the old — no net coverage loss |

## Acceptance

- `composer install` symlinks PC into `source/modules/oe_payment_component/`. `oe:module:install /var/www/vendor/oxid-esales/payment-component` idempotent.
- `oe:module:activate oe_payment_component` (first) → `oe:module:activate oe_payments_stripe_wallet oe_payments_paypal` (then) → no errors. Cache clears clean.
- Deactivating `oe_payment_component` hides the Payment tab from admin (regression guard — nothing else should keep it alive).
- Admin order detail shows exactly one "Payment" tab (not
  "Stripe" + "PayPal").
- Opening a Stripe-paid order → shows the Stripe body (dashboard
  link, transaction history, capture/refund forms).
- Opening a PayPal-paid order → shows the PayPal body.
- Opening an invoice / cash-on-del order → shows
  "Order was not processed through a registered online payment
  provider. Manual payment handling applies."
- Clicking any other tab from the Payment tab works (Task 3
  regression covered).
- `pre-commit-check.sh --full` green in all three modules.
- Playwright admin-tab routing + navigation specs green.
- Zero grep hits for `OrderRefund\|PayPalOrderRefund` outside of
  git history.

## Estimated effort

- payment-component module promotion: ~40 lines across
  `composer.json` + `metadata.php` + `menu.xml` + smoke
  test in `bin/pre-commit-check.sh`. One-time
  install-flow coordination.
- payment-component: ~400 lines src + 200 lines tests +
  120 lines template.
- Stripe: 80 lines provider + 150 lines template +
  200 lines tests, minus ~600 lines deleted.
- PayPal: 60 + 70 + 150 tests, minus ~450 lines deleted.
- Net deletion across the three modules. One sprint
  (3–5 working days with TDD discipline).

## Out-of-scope follow-ups

- **opalreturns admin tab** — same refactor applies, deferred to
  Sprint J.
- **Admin Payment list view** (future) — provider-agnostic
  summary of all contracts across PSPs. Would reuse the same
  registry.
- **Webhook admin panel** — provider-agnostic webhook-log viewer
  sharing the registry's resolver. Future.
